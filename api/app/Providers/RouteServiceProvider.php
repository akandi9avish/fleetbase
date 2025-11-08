<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        // Register simple healthcheck routes without middleware
        Route::get('/', function () {
            return response()->json([
                'status' => 'ok',
                'service' => 'fleetbase-api',
                'version' => config('app.version', '0.7.15')
            ]);
        });

        Route::get('/health', function (Request $request) {
            $startTime = $request->attributes->get('request_start_time', microtime(true));
            return response()->json([
                'status' => 'ok',
                'time' => microtime(true) - $startTime
            ]);
        });
    }
}
