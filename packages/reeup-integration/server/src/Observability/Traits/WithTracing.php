<?php

namespace Reeup\Integration\Observability\Traits;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;

/**
 * Trait for adding OpenTelemetry tracing to controllers and services.
 *
 * Usage:
 * ```php
 * class MyController extends Controller
 * {
 *     use WithTracing;
 *
 *     public function processOrder(Request $request)
 *     {
 *         return $this->trace('process_order', function ($span) use ($request) {
 *             $span->setAttribute('order.id', $request->input('order_id'));
 *             // ... process order
 *             return response()->json(['success' => true]);
 *         });
 *     }
 * }
 * ```
 */
trait WithTracing
{
    protected ?TracerInterface $tracer = null;

    /**
     * Get the OpenTelemetry tracer instance.
     */
    protected function tracer(): TracerInterface
    {
        if ($this->tracer === null) {
            $this->tracer = app(TracerInterface::class);
        }

        return $this->tracer;
    }

    /**
     * Execute a callback within a new span.
     *
     * @param string $name The span name
     * @param callable $callback The callback to execute (receives SpanInterface as first argument)
     * @param array $attributes Initial span attributes
     * @param SpanKind|null $kind The span kind (defaults to INTERNAL)
     * @return mixed The callback's return value
     */
    protected function trace(
        string $name,
        callable $callback,
        array $attributes = [],
        ?SpanKind $kind = null
    ): mixed {
        if (!env('OTEL_ENABLED', false)) {
            return $callback(new NoOpSpan());
        }

        $spanBuilder = $this->tracer()->spanBuilder($name)
            ->setSpanKind($kind ?? SpanKind::KIND_INTERNAL);

        foreach ($attributes as $key => $value) {
            $spanBuilder->setAttribute($key, $value);
        }

        $span = $spanBuilder->startSpan();
        $scope = $span->activate();

        try {
            $result = $callback($span);
            $span->setStatus(StatusCode::STATUS_OK);

            return $result;
        } catch (\Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());

            throw $exception;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Create a child span for database operations.
     */
    protected function traceDb(string $operation, string $table, callable $callback): mixed
    {
        return $this->trace("db.{$operation}", $callback, [
            'db.system' => 'mysql',
            'db.operation' => $operation,
            'db.sql.table' => $table,
        ]);
    }

    /**
     * Create a child span for external HTTP calls.
     */
    protected function traceHttp(string $method, string $url, callable $callback): mixed
    {
        $parsedUrl = parse_url($url);

        return $this->trace("HTTP {$method}", $callback, [
            'http.request.method' => $method,
            'url.full' => $url,
            'server.address' => $parsedUrl['host'] ?? 'unknown',
        ], SpanKind::KIND_CLIENT);
    }

    /**
     * Create a child span for queue job processing.
     */
    protected function traceJob(string $jobName, callable $callback): mixed
    {
        return $this->trace("job.{$jobName}", $callback, [
            'messaging.system' => 'redis',
            'messaging.operation' => 'process',
            'messaging.destination.name' => $jobName,
        ], SpanKind::KIND_CONSUMER);
    }

    /**
     * Add an event to the current span.
     */
    protected function addEvent(string $name, array $attributes = []): void
    {
        $span = \OpenTelemetry\API\Trace\Span::getCurrent();
        $span->addEvent($name, $attributes);
    }

    /**
     * Set attributes on the current span.
     */
    protected function setSpanAttributes(array $attributes): void
    {
        $span = \OpenTelemetry\API\Trace\Span::getCurrent();
        foreach ($attributes as $key => $value) {
            $span->setAttribute($key, $value);
        }
    }
}

/**
 * No-op span implementation for when tracing is disabled.
 */
class NoOpSpan implements SpanInterface
{
    public function setAttribute(string $key, $value): SpanInterface
    {
        return $this;
    }

    public function setAttributes(iterable $attributes): SpanInterface
    {
        return $this;
    }

    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanInterface
    {
        return $this;
    }

    public function recordException(\Throwable $exception, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
    }

    public function isRecording(): bool
    {
        return false;
    }

    public function getContext(): \OpenTelemetry\Context\ContextInterface
    {
        return \OpenTelemetry\Context\Context::getCurrent();
    }

    public function activate(): \OpenTelemetry\Context\ScopeInterface
    {
        return new class implements \OpenTelemetry\Context\ScopeInterface {
            public function detach(): int
            {
                return 0;
            }
        };
    }

    public function storeInContext(\OpenTelemetry\Context\ContextInterface $context): \OpenTelemetry\Context\ContextInterface
    {
        return $context;
    }
}
