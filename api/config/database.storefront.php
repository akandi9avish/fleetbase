<?php

/**
 * Storefront Database Connection Override
 *
 * This configuration overrides the default storefront database connection
 * to use the SAME database as the main connection instead of creating
 * a separate database with "_storefront" suffix.
 *
 * Original behavior: DB_DATABASE . '_storefront' (e.g., "railway_storefront")
 * New behavior: Use same database as main connection
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Storefront Database Connection
    |--------------------------------------------------------------------------
    |
    | Override the storefront connection to use the main database.
    | This allows single-database deployment on Railway.app
    |
    */
    'storefront' => [
        'driver'    => 'mysql',
        'host'      => env('DB_HOST', '127.0.0.1'),
        'port'      => env('DB_PORT', '3306'),
        // KEY CHANGE: Use DB_DATABASE directly without "_storefront" suffix
        'database'  => env('DB_DATABASE', 'fleetbase'),
        'username'  => env('DB_USERNAME', 'fleetbase'),
        'password'  => env('DB_PASSWORD', ''),
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix'    => '',
        'strict'    => true,
        'engine'    => null,
    ],
];
