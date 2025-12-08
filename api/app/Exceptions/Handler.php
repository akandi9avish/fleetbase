<?php

namespace App\Exceptions;

use App\Observability\OpenTelemetryBootstrap;
use Fleetbase\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;

class Handler extends ExceptionHandler
{
    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // Call parent registration (Sentry integration)
        parent::register();

        // Add OTEL exception recording
        $this->reportable(function (\Throwable $e) {
            $this->recordExceptionToOtel($e);
        });
    }

    /**
     * Report or log an exception.
     *
     * @return void
     *
     * @throws \Exception
     */
    public function report(\Throwable $exception)
    {
        // Record to OTEL first (before any potential early returns)
        $this->recordExceptionToOtel($exception);

        // Log with full context for debugging
        $this->logExceptionWithContext($exception);

        // Call parent report (CloudWatch logging + Sentry)
        parent::report($exception);
    }

    /**
     * Record exception to OpenTelemetry.
     */
    private function recordExceptionToOtel(\Throwable $exception): void
    {
        try {
            if (!OpenTelemetryBootstrap::isEnabled()) {
                return;
            }

            $request = request();

            OpenTelemetryBootstrap::recordException($exception, $request, [
                'fleetbase.service' => 'reeup-fleetbase',
                'fleetbase.environment' => config('app.env'),
            ]);
        } catch (\Throwable $e) {
            // Don't let OTEL errors break the exception handling
            Log::debug('[OTEL] Failed to record exception: ' . $e->getMessage());
        }
    }

    /**
     * Log exception with full context for better debugging.
     *
     * This ensures the error message appears in the logs, not just "unknown error".
     */
    private function logExceptionWithContext(\Throwable $exception): void
    {
        try {
            $request = request();

            $context = [
                'exception' => [
                    'class' => get_class($exception),
                    'message' => $exception->getMessage(),
                    'code' => $exception->getCode(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ],
            ];

            // Add request context if available
            if ($request) {
                $context['request'] = [
                    'method' => $request->method(),
                    'url' => $request->fullUrl(),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ];

                // Add route info if available
                if ($route = $request->route()) {
                    $context['request']['route'] = $route->uri();
                    $context['request']['action'] = $route->getActionName();
                }
            }

            // Add user context if authenticated
            if ($user = auth()->user()) {
                $context['user'] = [
                    'id' => $user->uuid ?? $user->id ?? 'unknown',
                ];

                if (method_exists($user, 'getCompany') && $company = $user->getCompany()) {
                    $context['user']['company_id'] = $company->uuid ?? $company->id ?? 'unknown';
                }
            }

            // Log with structured context
            // This will output to stderr with JSON format, including the actual error message
            Log::error(
                sprintf(
                    '[%s] %s in %s:%d',
                    class_basename($exception),
                    $exception->getMessage() ?: 'No message',
                    $exception->getFile(),
                    $exception->getLine()
                ),
                $context
            );
        } catch (\Throwable $e) {
            // Fallback: at minimum log the exception message
            Log::error('Exception: ' . $exception->getMessage());
        }
    }
}
