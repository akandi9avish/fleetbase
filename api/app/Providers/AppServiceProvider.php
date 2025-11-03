<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * RAILWAY FIX: Override storefront database connection to use the same database
     * as the main connection, removing the "_storefront" suffix requirement.
     *
     * @return void
     */
    public function register()
    {
        // Override storefront database connection after all packages have registered
        $this->app->booted(function () {
            config([
                'database.connections.storefront.driver'    => 'mysql',
                'database.connections.storefront.host'      => env('DB_HOST', '127.0.0.1'),
                'database.connections.storefront.port'      => env('DB_PORT', '3306'),
                'database.connections.storefront.database'  => env('DB_DATABASE', 'fleetbase'),
                'database.connections.storefront.username'  => env('DB_USERNAME', 'fleetbase'),
                'database.connections.storefront.password'  => env('DB_PASSWORD', ''),
                'database.connections.storefront.charset'   => 'utf8mb4',
                'database.connections.storefront.collation' => 'utf8mb4_unicode_ci',
                'database.connections.storefront.prefix'    => '',
                'database.connections.storefront.strict'    => true,
                'database.connections.storefront.engine'    => null,
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
