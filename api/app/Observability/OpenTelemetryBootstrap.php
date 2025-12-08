<?php

namespace App\Observability;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SemConv\TraceAttributes;

class OpenTelemetryBootstrap
{
    /**
     * Get the global tracer instance.
     */
    public static function getTracer(?string $name = null): TracerInterface
    {
        return Globals::tracerProvider()->getTracer(
            $name ?? env('OTEL_SERVICE_NAME', 'reeup-fleetbase'),
            '1.0.0'
        );
    }

    /**
     * Record an exception to the current span or create a new one.
     */
    public static function recordException(
        \Throwable $exception,
        ?Request $request = null,
        array $additionalAttributes = []
    ): void {
        try {
            $tracer = self::getTracer();

            // Get current span or create a new one
            $currentSpan = self::getCurrentSpan();

            if ($currentSpan === null) {
                // Create a new span for this exception
                $spanName = 'exception.' . self::getExceptionShortName($exception);
                $span = $tracer->spanBuilder($spanName)
                    ->setSpanKind(SpanKind::KIND_INTERNAL)
                    ->startSpan();

                self::populateExceptionSpan($span, $exception, $request, $additionalAttributes);

                $span->end();
            } else {
                // Record exception on existing span
                self::populateExceptionSpan($currentSpan, $exception, $request, $additionalAttributes);
            }
        } catch (\Throwable $e) {
            // Silently fail - don't let OTEL errors break the application
        }
    }

    /**
     * Get the current active span if any.
     */
    public static function getCurrentSpan(): ?SpanInterface
    {
        try {
            $span = \OpenTelemetry\API\Trace\Span::getCurrent();
            // Check if it's a valid span (not a no-op)
            if ($span->getContext()->isValid()) {
                return $span;
            }
        } catch (\Throwable $e) {
            // Ignore
        }

        return null;
    }

    /**
     * Populate span with exception details.
     */
    private static function populateExceptionSpan(
        SpanInterface $span,
        \Throwable $exception,
        ?Request $request = null,
        array $additionalAttributes = []
    ): void {
        // Record the exception
        $span->recordException($exception, [
            TraceAttributes::EXCEPTION_ESCAPED => true,
        ]);

        // Set error status
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

        // Add exception attributes
        $span->setAttribute('exception.type', get_class($exception));
        $span->setAttribute('exception.message', $exception->getMessage());
        $span->setAttribute('exception.code', $exception->getCode());
        $span->setAttribute('exception.file', $exception->getFile());
        $span->setAttribute('exception.line', $exception->getLine());

        // Add stack trace (limited to prevent huge payloads)
        $stackTrace = self::getCleanStackTrace($exception);
        $span->setAttribute('exception.stacktrace', $stackTrace);

        // Add request context if available
        if ($request) {
            self::addRequestAttributes($span, $request);
        }

        // Add user context if authenticated
        self::addUserAttributes($span);

        // Add additional attributes
        foreach ($additionalAttributes as $key => $value) {
            $span->setAttribute($key, $value);
        }
    }

    /**
     * Add HTTP request attributes to span.
     */
    private static function addRequestAttributes(SpanInterface $span, Request $request): void
    {
        $span->setAttribute(TraceAttributes::HTTP_REQUEST_METHOD, $request->method());
        $span->setAttribute(TraceAttributes::URL_FULL, $request->fullUrl());
        $span->setAttribute(TraceAttributes::URL_PATH, $request->path());
        $span->setAttribute(TraceAttributes::HTTP_ROUTE, $request->route()?->uri() ?? $request->path());
        $span->setAttribute(TraceAttributes::CLIENT_ADDRESS, $request->ip());
        $span->setAttribute(TraceAttributes::USER_AGENT_ORIGINAL, $request->userAgent() ?? 'unknown');

        // Add request ID if present
        $requestId = $request->header('X-Request-ID') ?? $request->header('X-Correlation-ID');
        if ($requestId) {
            $span->setAttribute('http.request_id', $requestId);
        }
    }

    /**
     * Add authenticated user attributes to span.
     */
    private static function addUserAttributes(SpanInterface $span): void
    {
        try {
            $user = Auth::user();
            if ($user) {
                $span->setAttribute('enduser.id', $user->uuid ?? $user->id ?? 'unknown');

                if (method_exists($user, 'getCompany') && $user->getCompany()) {
                    $company = $user->getCompany();
                    $span->setAttribute('company.id', $company->uuid ?? $company->id ?? 'unknown');
                    $span->setAttribute('company.name', $company->name ?? 'unknown');
                }
            }
        } catch (\Throwable $e) {
            // Ignore auth errors
        }
    }

    /**
     * Get a clean, truncated stack trace.
     */
    private static function getCleanStackTrace(\Throwable $exception, int $maxLength = 4096): string
    {
        $trace = $exception->getTraceAsString();

        // Truncate if too long
        if (strlen($trace) > $maxLength) {
            $trace = substr($trace, 0, $maxLength) . "\n... [truncated]";
        }

        return $trace;
    }

    /**
     * Get a short name for the exception class.
     */
    private static function getExceptionShortName(\Throwable $exception): string
    {
        $className = get_class($exception);
        $parts = explode('\\', $className);
        return end($parts);
    }

    /**
     * Start a new span for a named operation.
     */
    public static function startSpan(string $name, int $kind = SpanKind::KIND_INTERNAL): SpanInterface
    {
        return self::getTracer()
            ->spanBuilder($name)
            ->setSpanKind($kind)
            ->startSpan();
    }

    /**
     * Execute a callback within a span context.
     *
     * @template T
     * @param string $name
     * @param callable(): T $callback
     * @param array $attributes
     * @return T
     */
    public static function withSpan(string $name, callable $callback, array $attributes = [])
    {
        $span = self::startSpan($name);

        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }

        $scope = $span->activate();

        try {
            $result = $callback();
            $span->setStatus(StatusCode::STATUS_OK);
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Check if OpenTelemetry is enabled and configured.
     */
    public static function isEnabled(): bool
    {
        // Support both OTEL_ENABLED=true and OTEL_SDK_DISABLED=false conventions
        $otelEnabled = env('OTEL_ENABLED', null);
        $otelSdkDisabled = env('OTEL_SDK_DISABLED', false);

        // If OTEL_ENABLED is explicitly set to false, disable
        if ($otelEnabled !== null && !filter_var($otelEnabled, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // If OTEL_SDK_DISABLED is true, disable
        if (filter_var($otelSdkDisabled, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        // Require endpoint to be configured
        return !empty(env('OTEL_EXPORTER_OTLP_ENDPOINT'));
    }
}
