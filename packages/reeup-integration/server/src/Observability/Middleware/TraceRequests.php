<?php

namespace Reeup\Integration\Observability\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SemConv\TraceAttributes;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for automatic HTTP request tracing with OpenTelemetry.
 *
 * Creates spans for incoming HTTP requests with standard semantic conventions.
 */
class TraceRequests
{
    protected TracerInterface $tracer;

    public function __construct(TracerInterface $tracer)
    {
        $this->tracer = $tracer;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip tracing if disabled
        if (!env('OTEL_ENABLED', false)) {
            return $next($request);
        }

        $spanName = $this->buildSpanName($request);

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttributes([
                TraceAttributes::HTTP_REQUEST_METHOD => $request->method(),
                TraceAttributes::URL_FULL => $request->fullUrl(),
                TraceAttributes::URL_PATH => $request->path(),
                TraceAttributes::URL_SCHEME => $request->getScheme(),
                TraceAttributes::SERVER_ADDRESS => $request->getHost(),
                TraceAttributes::SERVER_PORT => $request->getPort(),
                TraceAttributes::USER_AGENT_ORIGINAL => $request->userAgent() ?? 'unknown',
                TraceAttributes::CLIENT_ADDRESS => $request->ip(),
                TraceAttributes::HTTP_ROUTE => $request->route()?->uri() ?? $request->path(),
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
            $span->setAttribute(TraceAttributes::HTTP_RESPONSE_STATUS_CODE, $response->getStatusCode());
            $span->setAttribute('http.response.body_size', strlen($response->getContent()));

            // Set span status based on HTTP status code
            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR, 'Server error');
            } elseif ($response->getStatusCode() >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, 'Client error');
            } else {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            return $response;
        } catch (\Throwable $exception) {
            // Record exception details
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

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
