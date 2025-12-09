<?php

namespace Reeup\Integration\Observability\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for automatic HTTP request tracing with OpenTelemetry.
 *
 * Creates spans for incoming HTTP requests with standard semantic conventions.
 * This middleware is defensive - it will skip tracing if OpenTelemetry is not available.
 */
class TraceRequests
{
    protected $tracer = null;

    public function __construct()
    {
        // Only initialize tracer if OpenTelemetry is available
        // Use global tracer provider (registered by App\Providers\OpenTelemetryServiceProvider)
        // instead of container binding to ensure we use the properly configured exporter
        if ($this->isOtelAvailable()) {
            try {
                $this->tracer = Globals::tracerProvider()->getTracer(
                    env('OTEL_SERVICE_NAME', 'reeup-fleetbase'),
                    '1.0.0'
                );
            } catch (\Throwable $e) {
                // Silently fail - don't break app if OTEL isn't configured
                $this->tracer = null;
            }
        }
    }

    /**
     * Check if OpenTelemetry SDK is available.
     */
    protected function isOtelAvailable(): bool
    {
        return class_exists(\OpenTelemetry\API\Trace\TracerInterface::class)
            && class_exists(\OpenTelemetry\API\Trace\SpanKind::class);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip tracing if disabled or not available
        if (!env('OTEL_ENABLED', false) || !$this->tracer || !$this->isOtelAvailable()) {
            return $next($request);
        }

        $spanName = $this->buildSpanName($request);

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_SERVER)
            ->setAttributes([
                'http.request.method' => $request->method(),
                'url.full' => $request->fullUrl(),
                'url.path' => $request->path(),
                'url.scheme' => $request->getScheme(),
                'server.address' => $request->getHost(),
                'server.port' => $request->getPort(),
                'user_agent.original' => $request->userAgent() ?? 'unknown',
                'client.address' => $request->ip(),
                'http.route' => $request->route()?->uri() ?? $request->path(),
                'http.request.body_size' => $request->header('Content-Length', 0),
            ])
            ->startSpan();

        $scope = $span->activate();

        try {
            // Add request context attributes
            $this->addRequestContext($span, $request);

            /** @var Response $response */
            $response = $next($request);

            // Record response attributes
            $span->setAttribute('http.response.status_code', $response->getStatusCode());
            $span->setAttribute('http.response.body_size', strlen($response->getContent()));

            // Set span status based on HTTP status code
            if ($response->getStatusCode() >= 500) {
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, 'Server error');
            } elseif ($response->getStatusCode() >= 400) {
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, 'Client error');
            } else {
                $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_OK);
            }

            return $response;
        } catch (\Throwable $exception) {
            // Record exception details
            $span->recordException($exception);
            $span->setStatus(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Build a descriptive span name from the request.
     */
    protected function buildSpanName(Request $request): string
    {
        $method = $request->method();
        $route = $request->route();

        if ($route) {
            $uri = $route->uri();
            // Replace route parameters with placeholders
            $uri = preg_replace('/\{[^}]+\}/', '{param}', $uri);
            return "{$method} /{$uri}";
        }

        return "{$method} {$request->path()}";
    }

    /**
     * Add REEUP-specific request context to the span.
     */
    protected function addRequestContext($span, Request $request): void
    {
        // Add authenticated user info if available
        if ($user = $request->user()) {
            $span->setAttribute('enduser.id', $user->uuid ?? $user->id);
            if (property_exists($user, 'company_uuid')) {
                $span->setAttribute('reeup.company_uuid', $user->company_uuid);
            }
        }

        // Add Fleetbase-specific headers
        $companySession = $request->header('X-Fleetbase-Session');
        if ($companySession) {
            $span->setAttribute('fleetbase.session', $companySession);
        }

        // Add correlation ID if present
        $correlationId = $request->header('X-Correlation-ID') ?? $request->header('X-Request-ID');
        if ($correlationId) {
            $span->setAttribute('reeup.correlation_id', $correlationId);
        }

        // Add API version if present
        $apiVersion = $request->header('X-API-Version');
        if ($apiVersion) {
            $span->setAttribute('api.version', $apiVersion);
        }
    }
}
