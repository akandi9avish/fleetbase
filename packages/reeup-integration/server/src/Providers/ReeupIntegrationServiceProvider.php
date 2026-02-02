<?php

namespace Reeup\Integration\Providers;

use Fleetbase\Providers\CoreServiceProvider;
use Fleetbase\Support\Utils;
use Reeup\Integration\Auth\Schemas\Reeup as ReeupAuthSchema;
use Reeup\Integration\Observability\ObservabilityServiceProvider;
use Reeup\Integration\Observability\Middleware\TraceRequests;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;

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

        // Auto-seed REEUP IAM roles on boot (idempotent via updateOrCreate)
        $this->app->booted(function () {
            $this->seedRolesIfNeeded();
        });

        // Register OpenTelemetry tracing middleware
        if (config('reeup-integration.observability.enabled') && config('reeup-integration.observability.trace_requests')) {
            $this->app['router']->aliasMiddleware('reeup.trace', TraceRequests::class);

            // Add to API middleware group if it exists
            $this->registerTracingMiddleware();
        }
    }

    /**
     * Auto-seed REEUP roles if they are missing or outdated.
     *
     * Uses a lightweight check: if the Microbusiness role exists with the expected
     * number of policies, skip seeding. Otherwise, run the full idempotent seed.
     *
     * @return void
     */
    protected function seedRolesIfNeeded(): void
    {
        try {
            // Quick check: does the Microbusiness role exist with all 4 policies?
            $role = Role::where('name', 'Reeup Microbusiness')->where('guard_name', 'sanctum')->first();

            if ($role) {
                $policyCount = $role->policies()->count();
                $schema = new ReeupAuthSchema();
                $expectedCount = 0;
                foreach ($schema->roles as $r) {
                    if ($r['name'] === 'Reeup Microbusiness') {
                        $expectedCount = count($r['policies'] ?? []);
                        break;
                    }
                }

                if ($policyCount >= $expectedCount) {
                    return; // Roles are up to date
                }
            }

            // Roles missing or outdated — run seed via artisan
            \Illuminate\Support\Facades\Artisan::call('reeup:seed-roles');
            \Illuminate\Support\Facades\Log::info('🌱 [REEUP] Auto-seeded IAM roles on boot');
        } catch (\Exception $e) {
            // Don't crash the app if seeding fails (e.g., DB not ready)
            \Illuminate\Support\Facades\Log::warning('⚠️ [REEUP] Auto-seed roles failed: ' . $e->getMessage());
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
