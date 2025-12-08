# OpenTelemetry Setup for Fleetbase

This document describes how to configure OpenTelemetry for the REEUP Fleetbase integration to send traces to OpenObserve.

## Architecture

```
┌─────────────────────┐     OTLP HTTP      ┌─────────────────────┐
│   Fleetbase         │ ─────────────────> │   OpenObserve       │
│   (Laravel/PHP)     │                    │   (Observability)   │
│                     │                    │                     │
│   - Traces          │                    │   - Unified View    │
│   - Request Spans   │                    │   - Dashboards      │
│   - Custom Spans    │                    │   - Alerts          │
└─────────────────────┘                    └─────────────────────┘
         │
         │ (Error Tracking - Existing)
         ▼
┌─────────────────────┐
│   Sentry            │
│   (Error Tracking)  │
└─────────────────────┘
```

## Required Environment Variables

Add these to your Railway Fleetbase service:

### Required Variables

```env
# Enable OpenTelemetry
OTEL_ENABLED=true

# Service identification
OTEL_SERVICE_NAME=reeup-fleetbase

# OTLP Endpoint - Direct to OpenObserve (internal Railway network)
OTEL_EXPORTER_OTLP_ENDPOINT=http://openobserve.railway.internal:5080

# OpenObserve configuration
OPENOBSERVE_ORGANIZATION=reeup
OPENOBSERVE_AUTH_TOKEN=YXZpc2hAcmVldXAuY286YTZteWRuZjkzdzl3YWQ4eHhleGY4b2ZnYWtodXlnNG8=
OPENOBSERVE_STREAM_NAME=fleetbase_traces
```

### Optional Tuning Variables

```env
# Batch Span Processor settings (defaults shown)
OTEL_BSP_MAX_QUEUE_SIZE=2048
OTEL_BSP_SCHEDULE_DELAY=5000
OTEL_BSP_EXPORT_TIMEOUT=30000
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=512
```

## Setting Variables in Railway

1. Go to Railway Dashboard: https://railway.app
2. Select the `reeup` project
3. Click on the `fleetbase` service
4. Go to the **Variables** tab
5. Click **+ New Variable** for each variable above
6. After adding all variables, Railway will auto-deploy

## Alternative: Using OTel Collector

If you prefer to route through the OTel Collector (for fan-out to multiple backends):

```env
# Use OTel Collector instead of direct OpenObserve
OTEL_EXPORTER_OTLP_ENDPOINT=http://reup-fdf35324.railway.internal:4318
```

The OTel Collector will then forward traces to OpenObserve.

## Verification

After deploying with OTEL enabled:

1. Make some API requests to Fleetbase
2. Open OpenObserve: https://openobserve-production-5a53.up.railway.app
3. Navigate to **Traces** → Select `fleetbase_traces` stream
4. You should see traces from `reeup-fleetbase` service

## Using Tracing in Code

### Automatic Tracing

All HTTP requests are automatically traced via the `TraceRequests` middleware.

### Manual Tracing in Controllers

```php
use Reeup\Integration\Observability\Traits\WithTracing;

class MyController extends Controller
{
    use WithTracing;

    public function processDelivery(Request $request)
    {
        return $this->trace('process_delivery', function ($span) use ($request) {
            $span->setAttribute('delivery.id', $request->input('delivery_id'));
            $span->setAttribute('delivery.status', 'processing');

            // Your business logic here
            $result = $this->deliveryService->process($request->all());

            $span->setAttribute('delivery.result', $result->status);

            return response()->json($result);
        });
    }
}
```

### Tracing Database Operations

```php
$result = $this->traceDb('select', 'deliveries', function () use ($id) {
    return Delivery::find($id);
});
```

### Tracing External HTTP Calls

```php
$response = $this->traceHttp('GET', 'https://api.example.com/data', function () {
    return Http::get('https://api.example.com/data');
});
```

## Sentry Integration

Sentry remains the primary error tracking solution. OpenTelemetry and Sentry work together:

- **Sentry**: Error tracking, exception monitoring, crash reporting
- **OpenTelemetry**: Distributed tracing, request flow, performance monitoring

To enable Sentry tracing as well (for correlated error context):

```env
SENTRY_DSN=your-sentry-dsn
SENTRY_TRACES_SAMPLE_RATE=0.1
```

## Troubleshooting

### No traces appearing in OpenObserve

1. Check `OTEL_ENABLED=true` is set
2. Verify endpoint is reachable: `curl http://openobserve.railway.internal:5080/health`
3. Check Laravel logs for OTEL errors
4. Verify auth token is correct (Base64 encoded `email:token`)

### High memory usage

Reduce batch sizes:
```env
OTEL_BSP_MAX_QUEUE_SIZE=512
OTEL_BSP_MAX_EXPORT_BATCH_SIZE=128
```

### Slow requests

Increase export timeout or use async export:
```env
OTEL_BSP_EXPORT_TIMEOUT=60000
```
