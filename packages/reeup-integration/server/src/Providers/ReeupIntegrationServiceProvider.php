<?php

namespace Reeup\Integration\Providers;

use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Support\Utils;

if (!Utils::classExists(CoreServiceProvider::class)) {
    throw new \Exception('REEUP Integration cannot be loaded without `fleetbase/core-api` installed!');
}

/**
 * REEUP Integration Service Provider
 *
 * Registers custom endpoints, middleware, and commands for REEUP platform integration.
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

        // Register middleware alias
        $this->app['router']->aliasMiddleware(
            'reeup.company-context',
            \Reeup\Integration\Http\Middleware\InjectCompanyContext::class
        );
    }
}
