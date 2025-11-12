<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REEUP Integration API Routes
|--------------------------------------------------------------------------
|
| Custom endpoints for REEUP backend API integration.
| These endpoints provide relaxed validation for programmatic user creation.
|
*/

Route::prefix('int/v1/reeup')->namespace('Reeup\Integration\Http\Controllers')->group(function ($router) {
    // Protected routes (require authentication)
    $router->middleware(['fleetbase.protected', 'Reeup\Integration\Http\Middleware\InjectCompanyContext'])->group(function () use ($router) {
        // User management endpoints
        $router->post('users', 'ReeupUserController@create');
        $router->get('users', 'ReeupUserController@query');
        $router->get('users/{id}', 'ReeupUserController@find');
    });
});
