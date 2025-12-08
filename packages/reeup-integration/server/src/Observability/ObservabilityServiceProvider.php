<?php

namespace Reeup\Integration\Observability;

use Illuminate\Support\ServiceProvider;

/**
 * OpenTelemetry Observability Service Provider for REEUP Fleetbase Integration
 *
 * Configures OpenTelemetry SDK to send traces to OpenObserve via OTLP HTTP.
 * Works alongside Sentry for error tracking.
 *
 * This provider is defensive - it will only load if OpenTelemetry packages are installed.
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
            return;
        }

        $this->app->singleton(\OpenTelemetry\API\Trace\TracerInterface::class, function ($app) {
            return $this->createTracer();
        });

        $this->app->singleton(\OpenTelemetry\SDK\Trace\TracerProvider::class, function ($app) {
            return $this->createTracerProvider();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only initialize if OTEL is enabled and available
        if (!$this->isEnabled()) {
            return;
        }

        // Register the tracer provider globally for auto-instrumentation
        $tracerProvider = $this->app->make(\OpenTelemetry\SDK\Trace\TracerProvider::class);
        \OpenTelemetry\API\Globals::registerInitializer(function () use ($tracerProvider) {
            return $tracerProvider;
        });

        // Register shutdown handler to flush pending spans
        $this->app->terminating(function () {
            $tracerProvider = $this->app->make(\OpenTelemetry\SDK\Trace\TracerProvider::class);
            if ($tracerProvider instanceof \OpenTelemetry\SDK\Trace\TracerProvider) {
                $tracerProvider->shutdown();
            }
        });
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
     * Create the BatchSpanProcessor with OTLP HTTP exporter.
     */
    protected function createSpanProcessor(): \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor
    {
        $exporter = $this->createOtlpExporter();

        return new \OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor(
            exporter: $exporter,
            maxQueueSize: (int) env('OTEL_BSP_MAX_QUEUE_SIZE', 2048),
            scheduledDelayMillis: (int) env('OTEL_BSP_SCHEDULE_DELAY', 5000),
            exportTimeoutMillis: (int) env('OTEL_BSP_EXPORT_TIMEOUT', 30000),
            maxExportBatchSize: (int) env('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', 512)
        );
    }

    /**
     * Create the OTLP HTTP exporter for OpenObserve.
     */
    protected function createOtlpExporter(): \OpenTelemetry\Contrib\Otlp\SpanExporter
    {
        $endpoint = $this->buildOtlpEndpoint();
        $headers = $this->buildOtlpHeaders();

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
     * Build the OTLP endpoint URL for OpenObserve.
     *
     * OpenObserve expects traces at: {base}/api/{org}/v1/traces
     */
    protected function buildOtlpEndpoint(): string
    {
        $baseEndpoint = rtrim(env('OTEL_EXPORTER_OTLP_ENDPOINT', ''), '/');
        $organization = env('OPENOBSERVE_ORGANIZATION', 'reeup');

        // Check if endpoint already includes /v1/traces
        if (str_contains($baseEndpoint, '/v1/traces')) {
            return $baseEndpoint;
        }

        // Check if using OTel Collector (internal Railway endpoint)
        if (str_contains($baseEndpoint, '.railway.internal')) {
            // OTel Collector uses standard OTLP endpoint
            return $baseEndpoint . '/v1/traces';
        }

        // Direct to OpenObserve: {base}/api/{org}/v1/traces
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
