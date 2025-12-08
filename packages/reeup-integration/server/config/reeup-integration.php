<?php

return [
    /*
    |--------------------------------------------------------------------------
    | REEUP Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for REEUP platform integration with Fleetbase.
    |
    */

    'enabled' => env('REEUP_INTEGRATION_ENABLED', true),

    'api' => [
        'prefix' => 'int/v1/reeup',
        'middleware' => ['fleetbase.protected', 'reeup.company-context'],
    ],

    'validation' => [
        'relaxed' => true,  // Use relaxed validation rules for programmatic creation
        'allow_flexible_names' => true,  // Allow flexible name formats (no ExcludedWords check)
        'allow_flexible_phones' => true,  // Allow flexible phone formats
    ],

    'roles' => [
        'auto_seed' => env('REEUP_AUTO_SEED_ROLES', false),
        'default_role' => env('REEUP_DEFAULT_ROLE', 'Reeup Retailer'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenTelemetry Observability Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for OpenTelemetry integration with OpenObserve.
    | This enables distributed tracing and metrics collection.
    |
    | Environment Variables:
    | - OTEL_ENABLED: Enable/disable OpenTelemetry (default: false)
    | - OTEL_SERVICE_NAME: Service name for traces (default: reeup-fleetbase)
    | - OTEL_EXPORTER_OTLP_ENDPOINT: OTLP endpoint URL
    | - OPENOBSERVE_ORGANIZATION: OpenObserve organization (default: reeup)
    | - OPENOBSERVE_AUTH_TOKEN: Base64 encoded auth token for OpenObserve
    | - OPENOBSERVE_STREAM_NAME: Stream name for traces (default: fleetbase_traces)
    |
    */
    'observability' => [
        'enabled' => env('OTEL_ENABLED', false),
        'service_name' => env('OTEL_SERVICE_NAME', 'reeup-fleetbase'),
        'endpoint' => env('OTEL_EXPORTER_OTLP_ENDPOINT', ''),
        'organization' => env('OPENOBSERVE_ORGANIZATION', 'reeup'),
        'stream_name' => env('OPENOBSERVE_STREAM_NAME', 'fleetbase_traces'),

        // Batch span processor settings
        'batch' => [
            'max_queue_size' => env('OTEL_BSP_MAX_QUEUE_SIZE', 2048),
            'schedule_delay_ms' => env('OTEL_BSP_SCHEDULE_DELAY', 5000),
            'export_timeout_ms' => env('OTEL_BSP_EXPORT_TIMEOUT', 30000),
            'max_export_batch_size' => env('OTEL_BSP_MAX_EXPORT_BATCH_SIZE', 512),
        ],

        // Request tracing middleware
        'trace_requests' => true,

        // Routes to exclude from tracing (regex patterns)
        'excluded_routes' => [
            '#^/health$#',
            '#^/ready$#',
            '#^/_debugbar#',
        ],
    ],
];
