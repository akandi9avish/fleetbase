<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Custom service provider for REEUP-specific overrides.
 *
 * This provider registers custom controllers that override Fleetbase's default
 * controllers to handle Railway internal networking issues.
 */
class ReeupServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Bind our custom UserController to override Fleetbase's
        $this->app->bind(
            \Fleetbase\Http\Controllers\Internal\v1\UserController::class,
            \App\Http\Controllers\Internal\v1\UserController::class
        );
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
