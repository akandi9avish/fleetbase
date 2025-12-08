<?php

namespace Reeup\Integration\Providers;

use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Support\Utils;
use Reeup\Integration\Observability\ObservabilityServiceProvider;
use Reeup\Integration\Observability\Middleware\TraceRequests;

if (!Utils::classExists(CoreServiceProvider::class)) {
    throw new \Exception('REEUP Integration cannot be loaded without `fleetbase/core-api` installed!');
}

/**
 * REEUP Integration Service Provider
 *
 * Registers custom endpoints, middleware, and commands for REEUP platform integration.
 * Also configures OpenTelemetry observability for distributed tracing.
 */
class ReeupIntegrationServiceProvider extends CoreServiceProvider
{
    /**
     * The console commands registered with the service provider.
     *
     * @var array
     */
    public $commands = [
        \Reeup\Integration\Console\Commands\SeedReeupRoles::class,
    ];

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(CoreServiceProvider::class);

        // Register OpenTelemetry observability provider
        if (class_exists(ObservabilityServiceProvider::class)) {
            $this->app->register(ObservabilityServiceProvider::class);
        }
    }

    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerCommands();
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');
        $this->mergeConfigFrom(__DIR__ . '/../../config/reeup-integration.php', 'reeup-integration');

        // Register middleware aliases
        $this->app['router']->aliasMiddleware(
            'reeup.company-context',
            \Reeup\Integration\Http\Middleware\InjectCompanyContext::class
        );

        // Register OpenTelemetry tracing middleware
        if (config('reeup-integration.observability.enabled') && config('reeup-integration.observability.trace_requests')) {
            $this->app['router']->aliasMiddleware('reeup.trace', TraceRequests::class);

            // Add to API middleware group if it exists
            $this->registerTracingMiddleware();
        }
    }

    /**
     * Register tracing middleware in the appropriate middleware groups.
     *
     * @return void
     */
    protected function registerTracingMiddleware(): void
    {
        // Get the router instance
        $router = $this->app['router'];

        // Add tracing middleware to the API middleware group
        // This ensures all API requests are traced
        if (method_exists($router, 'pushMiddlewareToGroup')) {
            $router->pushMiddlewareToGroup('api', TraceRequests::class);
        }
    }
}
