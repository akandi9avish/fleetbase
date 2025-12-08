<?php

namespace Reeup\Integration\Observability;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;

/**
 * OpenTelemetry Observability Service Provider for REEUP Fleetbase Integration
 *
 * Configures OpenTelemetry SDK to send traces to OpenObserve via OTLP HTTP.
 * Works alongside Sentry for error tracking.
 *
 * This provider is defensive - it will only load if OpenTelemetry packages are installed.
 *
 * IMPORTANT: This provider is Octane-compatible. It uses SimpleSpanProcessor for
 * immediate span export since Octane workers don't terminate between requests.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Check if OpenTelemetry SDK is available.
     */
    protected function isOtelAvailable(): bool
    {
        return class_exists(\OpenTelemetry\SDK\Trace\TracerProvider::class)
            && class_exists(\OpenTelemetry\Contrib\Otlp\SpanExporter::class);
    }

    /**
     * Check if OpenTelemetry is enabled.
     */
    protected function isEnabled(): bool
    {
        return env('OTEL_ENABLED', false)
            && !empty(env('OTEL_EXPORTER_OTLP_ENDPOINT'))
            && $this->isOtelAvailable();
    }

    /**
     * Register the OpenTelemetry tracer provider.
     */
    public function register(): void
    {
        // Skip registration if OTEL packages aren't installed
        if (!$this->isOtelAvailable()) {
            Log::debug('[OTEL] OpenTelemetry packages not available - skipping registration');
            return;
        }

        Log::info('[OTEL] Registering OpenTelemetry service provider');

        $this->app->singleton(\OpenTelemetry\SDK\Trace\TracerProvider::class, function ($app) {
            return $this->createTracerProvider();
        });

        $this->app->singleton(\OpenTelemetry\API\Trace\TracerInterface::class, function ($app) {
            return $this->createTracer();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only initialize if OTEL is enabled and available
        if (!$this->isEnabled()) {
            Log::debug('[OTEL] OpenTelemetry not enabled or not available', [
                'otel_enabled' => env('OTEL_ENABLED', false),
                'endpoint_set' => !empty(env('OTEL_EXPORTER_OTLP_ENDPOINT')),
                'packages_available' => $this->isOtelAvailable(),
            ]);
            return;
        }

        Log::info('[OTEL] Bootstrapping OpenTelemetry', [
            'service_name' => env('OTEL_SERVICE_NAME', 'reeup-fleetbase'),
            'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT'),
        ]);

        // Force-resolve the tracer provider to ensure it's initialized
        $tracerProvider = $this->app->make(\OpenTelemetry\SDK\Trace\TracerProvider::class);

        // Register Octane event listener to flush spans after each request
        // This is critical because Octane workers don't terminate between requests
        if (class_exists(RequestTerminated::class)) {
            $this->app['events']->listen(RequestTerminated::class, function () {
                $this->flushSpans();
            });
            Log::info('[OTEL] Registered Octane RequestTerminated listener for span flushing');
        }

        // Also register shutdown handler as fallback for non-Octane environments
        $this->app->terminating(function () {
            $this->shutdownTracer();
        });

        Log::info('[OTEL] OpenTelemetry bootstrap complete');
    }

    /**
     * Create the OpenTelemetry TracerProvider.
     */
    protected function createTracerProvider(): \OpenTelemetry\SDK\Trace\TracerProvider
    {
        if (!$this->isEnabled()) {
            return new \OpenTelemetry\SDK\Trace\TracerProvider();
        }

        $resource = $this->createResource();
        $spanProcessor = $this->createSpanProcessor();

        return new \OpenTelemetry\SDK\Trace\TracerProvider(
            spanProcessors: [$spanProcessor],
            sampler: new \OpenTelemetry\SDK\Trace\Sampler\ParentBased(
                new \OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler()
            ),
            resource: $resource
        );
    }

    /**
     * Create the OpenTelemetry Tracer.
     */
    protected function createTracer(): \OpenTelemetry\API\Trace\TracerInterface
    {
        $tracerProvider = $this->app->make(\OpenTelemetry\SDK\Trace\TracerProvider::class);

        return $tracerProvider->getTracer(
            name: 'reeup-fleetbase',
            version: env('APP_VERSION', '1.0.0'),
            schemaUrl: null
        );
    }

    /**
     * Create the Resource with service attributes.
     */
    protected function createResource(): \OpenTelemetry\SDK\Resource\ResourceInfo
    {
        return \OpenTelemetry\SDK\Resource\ResourceInfoFactory::defaultResource()->merge(
            \OpenTelemetry\SDK\Resource\ResourceInfo::create(
                \OpenTelemetry\SDK\Common\Attribute\Attributes::create([
                    \OpenTelemetry\SemConv\ResourceAttributes::SERVICE_NAME => env('OTEL_SERVICE_NAME', 'reeup-fleetbase'),
                    \OpenTelemetry\SemConv\ResourceAttributes::SERVICE_VERSION => env('APP_VERSION', '1.0.0'),
                    \OpenTelemetry\SemConv\ResourceAttributes::SERVICE_NAMESPACE => 'logistics',
                    \OpenTelemetry\SemConv\ResourceAttributes::DEPLOYMENT_ENVIRONMENT_NAME => env('APP_ENV', 'production'),
                    'platform' => 'reeup',
                    'service.instance.id' => gethostname(),
                ])
            )
        );
    }

    /**
     * Create the span processor.
     *
     * Uses SimpleSpanProcessor for Octane compatibility (immediate export).
     * This ensures spans are exported immediately rather than batched,
     * which is necessary because Octane workers don't terminate between requests.
     *
     * Set OTEL_USE_BATCH_PROCESSOR=true to use BatchSpanProcessor instead
     * (only recommended for non-Octane environments).
     */
    protected function createSpanProcessor(): \OpenTelemetry\SDK\Trace\SpanProcessorInterface
    {
        $exporter = $this->createOtlpExporter();

        // Use SimpleSpanProcessor by default for Octane compatibility
        $useBatch = filter_var(env('OTEL_USE_BATCH_PROCESSOR', false), FILTER_VALIDATE_BOOLEAN);

        if ($useBatch) {
            Log::info('[OTEL] Using BatchSpanProcessor (not recommended for Octane)');
            return new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor(
                exporter: $exporter,
                maxQueueSize: (int) env('OTEL_BSP_MAX_QUEUE_SIZE', 2048),
                scheduledDelayMillis: (int) env('OTEL_BSP_SCHEDULE_DELAY', 5000),
                exportTimeoutMillis: (int) env('OTEL_BSP_EXPORT_TIMEOUT', 30000),
                maxExportBatchSize: (int) env('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', 512)
            );
        }

        Log::info('[OTEL] Using SimpleSpanProcessor for Octane compatibility');
        return new \OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor($exporter);
    }

    /**
     * Flush pending spans (called after each Octane request).
     */
    protected function flushSpans(): void
    {
        try {
            if ($this->app->bound(\OpenTelemetry\SDK\Trace\TracerProvider::class)) {
                $tracerProvider = $this->app->make(\OpenTelemetry\SDK\Trace\TracerProvider::class);
                if ($tracerProvider instanceof \OpenTelemetry\SDK\Trace\TracerProvider) {
                    $tracerProvider->forceFlush();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[OTEL] Failed to flush spans: ' . $e->getMessage());
        }
    }

    /**
     * Shutdown the tracer provider (called on application termination).
     */
    protected function shutdownTracer(): void
    {
        try {
            if ($this->app->bound(\OpenTelemetry\SDK\Trace\TracerProvider::class)) {
                $tracerProvider = $this->app->make(\OpenTelemetry\SDK\Trace\TracerProvider::class);
                if ($tracerProvider instanceof \OpenTelemetry\SDK\Trace\TracerProvider) {
                    $tracerProvider->shutdown();
                    Log::info('[OTEL] TracerProvider shutdown complete');
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[OTEL] Failed to shutdown tracer: ' . $e->getMessage());
        }
    }

    /**
     * Create the OTLP HTTP exporter for OpenObserve.
     */
    protected function createOtlpExporter(): \OpenTelemetry\Contrib\Otlp\SpanExporter
    {
        $endpoint = $this->buildOtlpEndpoint();
        $headers = $this->buildOtlpHeaders();

        // Log with explicit string formatting for Railway visibility
        Log::info('[OTEL] Creating OTLP exporter to: ' . $endpoint);
        Log::info('[OTEL] Auth configured: ' . (isset($headers['Authorization']) ? 'yes' : 'no'));
        Log::info('[OTEL] Stream name: ' . ($headers['stream-name'] ?? 'not set'));

        $transport = (new \OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory())->create(
            endpoint: $endpoint,
            contentType: 'application/json',
            headers: $headers,
            compression: null,
            retryDelay: 100,
            maxRetries: 3,
            timeout: 10
        );

        return new \OpenTelemetry\Contrib\Otlp\SpanExporter($transport);
    }

    /**
     * Build the OTLP endpoint URL based on the target backend.
     *
     * Supports multiple backend types:
     * - OpenObserve: {base}/api/{org}/v1/traces (default)
     * - OTel Collector: {base}/v1/traces
     * - Custom: Use full path in OTEL_EXPORTER_OTLP_ENDPOINT
     *
     * Environment Variables:
     * - OTEL_EXPORTER_OTLP_ENDPOINT: Base URL or full endpoint path
     * - OTEL_ENDPOINT_TYPE: 'openobserve' (default), 'collector', or 'auto'
     * - OPENOBSERVE_ORGANIZATION: Organization name for OpenObserve (default: 'reeup')
     */
    protected function buildOtlpEndpoint(): string
    {
        $baseEndpoint = rtrim(env('OTEL_EXPORTER_OTLP_ENDPOINT', ''), '/');
        $organization = env('OPENOBSERVE_ORGANIZATION', 'reeup');
        $endpointType = strtolower(env('OTEL_ENDPOINT_TYPE', 'auto'));

        // If endpoint already includes /v1/traces, use as-is (explicit full path)
        if (str_contains($baseEndpoint, '/v1/traces')) {
            return $baseEndpoint;
        }

        // Explicit endpoint type takes precedence
        if ($endpointType === 'collector') {
            // Standard OTLP Collector endpoint
            return $baseEndpoint . '/v1/traces';
        }

        if ($endpointType === 'openobserve') {
            // OpenObserve format: {base}/api/{org}/v1/traces
            return $baseEndpoint . '/api/' . $organization . '/v1/traces';
        }

        // Auto-detect based on hostname patterns
        $hostname = parse_url($baseEndpoint, PHP_URL_HOST) ?? '';

        // Check if it's an OTel Collector (look for 'collector' in hostname)
        if (preg_match('/\b(otel-?collector|collector)\b/i', $hostname)) {
            return $baseEndpoint . '/v1/traces';
        }

        // Check if it's OpenObserve (look for 'openobserve' or 'o2' in hostname)
        if (preg_match('/\b(openobserve|o2)\b/i', $hostname)) {
            return $baseEndpoint . '/api/' . $organization . '/v1/traces';
        }

        // Default to OpenObserve format (most common for direct OTLP ingestion)
        // This handles cases like openobserve.railway.internal
        return $baseEndpoint . '/api/' . $organization . '/v1/traces';
    }

    /**
     * Build the authentication headers for OTLP exporter.
     */
    protected function buildOtlpHeaders(): array
    {
        $headers = [];

        // OpenObserve requires Basic auth with Base64 encoded credentials
        $authToken = env('OPENOBSERVE_AUTH_TOKEN');
        if ($authToken) {
            $headers['Authorization'] = 'Basic ' . $authToken;
        }

        // Add stream name for OpenObserve organization
        $streamName = env('OPENOBSERVE_STREAM_NAME', 'fleetbase_traces');
        $headers['stream-name'] = $streamName;

        return $headers;
    }
}
