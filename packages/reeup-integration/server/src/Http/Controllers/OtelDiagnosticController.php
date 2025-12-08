<?php

namespace Reeup\Integration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Diagnostic controller to verify OpenTelemetry setup.
 *
 * Access at: GET /int/v1/reeup/otel-diagnostic
 */
class OtelDiagnosticController extends Controller
{
    public function diagnose(): JsonResponse
    {
        $diagnostics = [
            'timestamp' => now()->toIso8601String(),
            'environment' => [
                'OTEL_ENABLED' => env('OTEL_ENABLED', 'not set'),
                'OTEL_SERVICE_NAME' => env('OTEL_SERVICE_NAME', 'not set'),
                'OTEL_EXPORTER_OTLP_ENDPOINT' => env('OTEL_EXPORTER_OTLP_ENDPOINT', 'not set'),
                'OTEL_ENDPOINT_TYPE' => env('OTEL_ENDPOINT_TYPE', 'not set (default: auto)'),
                'OPENOBSERVE_ORGANIZATION' => env('OPENOBSERVE_ORGANIZATION', 'not set'),
                'OPENOBSERVE_AUTH_TOKEN' => env('OPENOBSERVE_AUTH_TOKEN') ? 'SET (hidden)' : 'NOT SET',
                'OPENOBSERVE_STREAM_NAME' => env('OPENOBSERVE_STREAM_NAME', 'not set'),
            ],
            'packages' => [
                'sdk_installed' => class_exists(\OpenTelemetry\SDK\Trace\TracerProvider::class),
                'exporter_installed' => class_exists(\OpenTelemetry\Contrib\Otlp\SpanExporter::class),
                'sem_conv_installed' => class_exists(\OpenTelemetry\SemConv\ResourceAttributes::class),
                'api_installed' => class_exists(\OpenTelemetry\API\Trace\TracerInterface::class),
            ],
            'service_container' => [
                'tracer_bound' => app()->bound(\OpenTelemetry\API\Trace\TracerInterface::class),
                'tracer_provider_bound' => app()->bound(\OpenTelemetry\SDK\Trace\TracerProvider::class),
            ],
            'config' => [
                'observability_enabled' => config('reeup-integration.observability.enabled'),
                'trace_requests' => config('reeup-integration.observability.trace_requests'),
            ],
            'computed_endpoint' => null,
            'tracer_test' => null,
        ];

        // Try to compute the endpoint
        try {
            $baseEndpoint = rtrim(env('OTEL_EXPORTER_OTLP_ENDPOINT', ''), '/');
            $organization = env('OPENOBSERVE_ORGANIZATION', 'reeup');
            $endpointType = strtolower(env('OTEL_ENDPOINT_TYPE', 'auto'));

            $hostname = parse_url($baseEndpoint, PHP_URL_HOST) ?? '';

            if (str_contains($baseEndpoint, '/v1/traces')) {
                $computedEndpoint = $baseEndpoint;
                $reason = 'Full path provided';
            } elseif ($endpointType === 'collector') {
                $computedEndpoint = $baseEndpoint . '/v1/traces';
                $reason = 'Explicit collector type';
            } elseif ($endpointType === 'openobserve') {
                $computedEndpoint = $baseEndpoint . '/api/' . $organization . '/v1/traces';
                $reason = 'Explicit openobserve type';
            } elseif (preg_match('/\b(otel-?collector|collector)\b/i', $hostname)) {
                $computedEndpoint = $baseEndpoint . '/v1/traces';
                $reason = 'Auto-detected collector in hostname';
            } elseif (preg_match('/\b(openobserve|o2)\b/i', $hostname)) {
                $computedEndpoint = $baseEndpoint . '/api/' . $organization . '/v1/traces';
                $reason = 'Auto-detected openobserve in hostname';
            } else {
                $computedEndpoint = $baseEndpoint . '/api/' . $organization . '/v1/traces';
                $reason = 'Default to openobserve format';
            }

            $diagnostics['computed_endpoint'] = [
                'url' => $computedEndpoint,
                'reason' => $reason,
                'hostname_parsed' => $hostname,
            ];
        } catch (\Throwable $e) {
            $diagnostics['computed_endpoint'] = ['error' => $e->getMessage()];
        }

        // Try to create a test span
        try {
            if ($diagnostics['service_container']['tracer_bound']) {
                $tracer = app(\OpenTelemetry\API\Trace\TracerInterface::class);
                $span = $tracer->spanBuilder('diagnostic-test-span')
                    ->setAttribute('diagnostic', true)
                    ->setAttribute('timestamp', time())
                    ->startSpan();
                $span->end();

                $diagnostics['tracer_test'] = [
                    'success' => true,
                    'message' => 'Test span created and ended successfully',
                    'note' => 'Span will be exported based on BatchSpanProcessor schedule (5s delay)',
                ];
            } else {
                $diagnostics['tracer_test'] = [
                    'success' => false,
                    'message' => 'Tracer not bound in container',
                ];
            }
        } catch (\Throwable $e) {
            $diagnostics['tracer_test'] = [
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }

        // Overall status
        $diagnostics['status'] = $this->computeStatus($diagnostics);

        return response()->json($diagnostics, 200, [], JSON_PRETTY_PRINT);
    }

    private function computeStatus(array $diagnostics): array
    {
        $issues = [];

        if ($diagnostics['environment']['OTEL_ENABLED'] !== 'true' && $diagnostics['environment']['OTEL_ENABLED'] !== true) {
            $issues[] = 'OTEL_ENABLED is not set to true';
        }

        if (!$diagnostics['packages']['sdk_installed']) {
            $issues[] = 'OpenTelemetry SDK package not installed';
        }

        if (!$diagnostics['packages']['exporter_installed']) {
            $issues[] = 'OTLP Exporter package not installed';
        }

        if (!$diagnostics['packages']['sem_conv_installed']) {
            $issues[] = 'Semantic Conventions package not installed';
        }

        if (!$diagnostics['service_container']['tracer_bound']) {
            $issues[] = 'Tracer not registered in service container';
        }

        if (empty($diagnostics['environment']['OTEL_EXPORTER_OTLP_ENDPOINT']) ||
            $diagnostics['environment']['OTEL_EXPORTER_OTLP_ENDPOINT'] === 'not set') {
            $issues[] = 'OTEL_EXPORTER_OTLP_ENDPOINT not configured';
        }

        if ($diagnostics['environment']['OPENOBSERVE_AUTH_TOKEN'] === 'NOT SET') {
            $issues[] = 'OPENOBSERVE_AUTH_TOKEN not configured';
        }

        return [
            'healthy' => empty($issues),
            'issues' => $issues,
            'issue_count' => count($issues),
        ];
    }
}
