<?php

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\Propagation\TextMapPropagator;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

class OpenTelemetryServiceProvider extends ServiceProvider
{
    /**
     * Indicates if OTEL has been bootstrapped.
     */
    private static bool $bootstrapped = false;

    /**
     * Register services.
     */
    public function register(): void
    {
        // Bind a singleton tracer provider
        $this->app->singleton('otel.tracer', function ($app) {
            return Globals::tracerProvider()->getTracer(
                env('OTEL_SERVICE_NAME', 'reeup-fleetbase'),
                '1.0.0'
            );
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Only bootstrap if not already done and OTEL is enabled
        if (self::$bootstrapped || !$this->shouldEnableOtel()) {
            return;
        }

        try {
            $this->bootstrapOpenTelemetry();
            self::$bootstrapped = true;
            Log::info('[OTEL] OpenTelemetry bootstrap complete');
        } catch (\Throwable $e) {
            Log::error('[OTEL] Failed to bootstrap OpenTelemetry: ' . $e->getMessage());
        }

        // Register Octane event listener for span flushing
        if (class_exists(\Laravel\Octane\Events\RequestTerminated::class)) {
            $this->app['events']->listen(
                \Laravel\Octane\Events\RequestTerminated::class,
                function () {
                    $this->flushSpans();
                }
            );
            Log::info('[OTEL] Registered Octane RequestTerminated listener for span flushing');
        }
    }

    /**
     * Check if OTEL should be enabled.
     */
    private function shouldEnableOtel(): bool
    {
        // Support both OTEL_ENABLED=true and OTEL_SDK_DISABLED=false
        $otelEnabled = env('OTEL_ENABLED', null);
        $otelSdkDisabled = env('OTEL_SDK_DISABLED', false);

        // If OTEL_ENABLED is explicitly set, use it
        if ($otelEnabled !== null) {
            if (!filter_var($otelEnabled, FILTER_VALIDATE_BOOLEAN)) {
                Log::debug('[OTEL] Disabled: OTEL_ENABLED is false');
                return false;
            }
        }

        // Check if explicitly disabled via OTEL_SDK_DISABLED
        if (filter_var($otelSdkDisabled, FILTER_VALIDATE_BOOLEAN)) {
            Log::debug('[OTEL] Disabled: OTEL_SDK_DISABLED is true');
            return false;
        }

        // Require endpoint to be configured
        $endpoint = env('OTEL_EXPORTER_OTLP_ENDPOINT');
        if (empty($endpoint)) {
            Log::debug('[OTEL] Disabled: No OTEL_EXPORTER_OTLP_ENDPOINT configured');
            return false;
        }

        return true;
    }

    /**
     * Bootstrap OpenTelemetry SDK.
     */
    private function bootstrapOpenTelemetry(): void
    {
        $serviceName = env('OTEL_SERVICE_NAME', 'reeup-fleetbase');
        $serviceNamespace = env('OTEL_SERVICE_NAMESPACE', 'logistics');
        $baseEndpoint = rtrim(env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:5080'), '/');
        // Support both naming conventions: OPENOBSERVE_ORGANIZATION (Railway) and OPENOBSERVE_ORG (new)
        $org = env('OPENOBSERVE_ORGANIZATION', env('OPENOBSERVE_ORG', 'reeup'));
        $authToken = env('OPENOBSERVE_AUTH_TOKEN', '');
        // Support both naming conventions: OPENOBSERVE_STREAM_NAME (Railway) and OPENOBSERVE_STREAM_TRACES (new)
        $streamName = env('OPENOBSERVE_STREAM_NAME', env('OPENOBSERVE_STREAM_TRACES', 'fleetbase_traces'));

        // Build the traces endpoint for OpenObserve
        $tracesEndpoint = "{$baseEndpoint}/api/{$org}/v1/traces";

        Log::info("[OTEL] Creating OTLP exporter to: {$tracesEndpoint}");
        Log::info('[OTEL] Auth configured: ' . (!empty($authToken) ? 'yes' : 'no'));
        Log::info("[OTEL] Stream name: {$streamName}");

        // Create OTLP HTTP transport
        $headers = [
            'Content-Type' => 'application/x-protobuf',
        ];

        if (!empty($authToken)) {
            $headers['Authorization'] = 'Basic ' . $authToken;
        }

        // Add stream name header for OpenObserve
        $headers['stream-name'] = $streamName;

        $transport = (new OtlpHttpTransportFactory())->create(
            $tracesEndpoint,
            'application/x-protobuf',
            $headers
        );

        $exporter = new SpanExporter($transport);

        // Create resource with service attributes
        // Use string keys for compatibility across OpenTelemetry SemConv versions
        $resource = ResourceInfo::create(Attributes::create([
            'service.name' => $serviceName,
            'service.namespace' => $serviceNamespace,
            'service.version' => config('app.version', '1.0.0'),
            'deployment.environment' => config('app.env', 'production'),
            'service.instance.id' => env('RAILWAY_SERVICE_ID', gethostname()),
        ]));

        // Merge with default resource
        $resource = ResourceInfoFactory::defaultResource()->merge($resource);

        // Use SimpleSpanProcessor for Octane compatibility
        // BatchSpanProcessor can cause issues with long-running processes
        $spanProcessor = new SimpleSpanProcessor($exporter);

        Log::info('[OTEL] Using SimpleSpanProcessor for Octane compatibility');

        // Create and register TracerProvider
        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor($spanProcessor)
            ->setResource($resource)
            ->setSampler(new AlwaysOnSampler())
            ->build();

        // Register as global tracer provider
        Globals::registerInitializer(function () use ($tracerProvider) {
            return $tracerProvider;
        });

        // Set up context propagation
        TextMapPropagator::setInstance(TraceContextPropagator::getInstance());
    }

    /**
     * Flush pending spans.
     */
    private function flushSpans(): void
    {
        try {
            $tracerProvider = Globals::tracerProvider();
            if ($tracerProvider instanceof TracerProvider) {
                $tracerProvider->forceFlush();
            }
        } catch (\Throwable $e) {
            Log::debug('[OTEL] Error flushing spans: ' . $e->getMessage());
        }
    }

    /**
     * Shutdown OTEL when application terminates.
     */
    public function __destruct()
    {
        try {
            $tracerProvider = Globals::tracerProvider();
            if ($tracerProvider instanceof TracerProvider) {
                $tracerProvider->shutdown();
                Log::info('[OTEL] TracerProvider shutdown complete');
            }
        } catch (\Throwable $e) {
            // Silently ignore shutdown errors
        }
    }
}
