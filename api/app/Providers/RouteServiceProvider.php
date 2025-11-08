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
        $this->routes(
            function () {
                // Root route - Railway healthcheck fallback
                Route::get(
                    '/',
                    function () {
                        return response()->json([
                            'status' => 'ok',
                            'service' => 'fleetbase-api',
                            'version' => config('app.version', '0.7.15')
                        ]);
                    }
                );

                // Health check route - Docker HEALTHCHECK and Railway
                Route::get(
                    '/health',
                    function (Request $request) {
                        $startTime = $request->attributes->get('request_start_time', microtime(true));
                        return response()->json(
                            [
                                'status' => 'ok',
                                'time' => microtime(true) - $startTime
                            ]
                        );
                    }
                );
            }
        );
    }
}
