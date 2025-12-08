<?php

namespace Reeup\Integration\Observability;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;

/**
 * OpenTelemetry Observability Service Provider for REEUP Fleetbase Integration
 *
 * Configures OpenTelemetry SDK to send traces to OpenObserve via OTLP HTTP.
 * Works alongside Sentry for error tracking.
 */
class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Register the OpenTelemetry tracer provider.
     */
    public function register(): void
    {
        $this->app->singleton(TracerInterface::class, function ($app) {
            return $this->createTracer();
        });

        // Register the tracer provider globally
        $this->app->singleton(TracerProvider::class, function ($app) {
            return $this->createTracerProvider();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Only initialize if OTEL is enabled
        if (!$this->isEnabled()) {
            return;
        }

        // Register the tracer provider globally for auto-instrumentation
        $tracerProvider = $this->app->make(TracerProvider::class);
        Globals::registerInitializer(function () use ($tracerProvider) {
            return $tracerProvider;
        });

        // Register shutdown handler to flush pending spans
        $this->app->terminating(function () {
            $tracerProvider = $this->app->make(TracerProvider::class);
            if ($tracerProvider instanceof TracerProvider) {
                $tracerProvider->shutdown();
            }
        });
    }

    /**
     * Check if OpenTelemetry is enabled.
     */
    protected function isEnabled(): bool
    {
        return env('OTEL_ENABLED', false)
            && !empty(env('OTEL_EXPORTER_OTLP_ENDPOINT'));
    }

    /**
     * Create the OpenTelemetry TracerProvider.
     */
    protected function createTracerProvider(): TracerProvider
    {
        if (!$this->isEnabled()) {
            // Return a no-op tracer provider when disabled
            return new TracerProvider();
        }

        $resource = $this->createResource();
        $spanProcessor = $this->createSpanProcessor();

        return new TracerProvider(
            spanProcessors: [$spanProcessor],
            sampler: new ParentBased(new AlwaysOnSampler()),
            resource: $resource
        );
    }

    /**
     * Create the OpenTelemetry Tracer.
     */
    protected function createTracer(): TracerInterface
    {
        $tracerProvider = $this->app->make(TracerProvider::class);

        return $tracerProvider->getTracer(
            name: 'reeup-fleetbase',
            version: env('APP_VERSION', '1.0.0'),
            schemaUrl: null
        );
    }

    /**
     * Create the Resource with service attributes.
     */
    protected function createResource(): ResourceInfo
    {
        return ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(
                Attributes::create([
                    ResourceAttributes::SERVICE_NAME => env('OTEL_SERVICE_NAME', 'reeup-fleetbase'),
                    ResourceAttributes::SERVICE_VERSION => env('APP_VERSION', '1.0.0'),
                    ResourceAttributes::SERVICE_NAMESPACE => 'logistics',
                    ResourceAttributes::DEPLOYMENT_ENVIRONMENT => env('APP_ENV', 'production'),
                    'platform' => 'reeup',
                    'service.instance.id' => gethostname(),
                ])
            )
        );
    }

    /**
     * Create the BatchSpanProcessor with OTLP HTTP exporter.
     */
    protected function createSpanProcessor(): BatchSpanProcessor
    {
        $exporter = $this->createOtlpExporter();

        return new BatchSpanProcessor(
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
    protected function createOtlpExporter(): SpanExporter
    {
        $endpoint = $this->buildOtlpEndpoint();
        $headers = $this->buildOtlpHeaders();

        $transport = (new OtlpHttpTransportFactory())->create(
            endpoint: $endpoint,
            contentType: 'application/json',
            headers: $headers,
            compression: null,
            retryDelay: 100,
            maxRetries: 3,
            timeout: 10
        );

        return new SpanExporter($transport);
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
