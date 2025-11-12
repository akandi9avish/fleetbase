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
];
