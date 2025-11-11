<?php

namespace App\Console\Commands;

use App\Auth\Schemas\Reeup as ReeupAuthSchema;
use Fleetbase\Models\Permission;
use Fleetbase\Models\Policy;
use Fleetbase\Models\Role;
use Illuminate\Console\Command;

/**
 * Seed REEUP Custom IAM Roles
 *
 * Creates entity-type-specific roles for the REEUP cannabis retail platform.
 * Run this after migrations to set up custom roles.
 *
 * Usage: php artisan reeup:seed-roles
 */
class SeedReeupRoles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reeup:seed-roles {--reset : Reset all REEUP roles before seeding}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed REEUP custom IAM roles for cannabis retail entities';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $reset = $this->option('reset');

        $this->info('🌱 Seeding REEUP custom IAM roles...');
        $this->newLine();

        try {
            $schema = new ReeupAuthSchema();
            $guard = 'sanctum';

            if ($reset) {
                $this->warn('⚠️  Resetting REEUP roles...');
                Role::where('service', $schema->name)->delete();
                Policy::where('service', $schema->name)->delete();
                Permission::where('service', $schema->name)->delete();
                $this->info('✓ Reset complete');
                $this->newLine();
            }

            // Create permissions
            $this->info('📋 Creating permissions...');
            foreach ($schema->permissions as $permissionName) {
                $permission = Permission::updateOrCreate(
                    [
                        'name'       => $schema->name . ' ' . $permissionName,
                        'guard_name' => $guard,
                    ],
                    [
                        'name'       => $schema->name . ' ' . $permissionName,
                        'guard_name' => $guard,
                        'service'    => $schema->name,
                    ]
                );
                $this->line("  ✓ {$permission->name}");
            }

            // Create resource permissions
            foreach ($schema->resources as $resource) {
                $resourceName = $resource['name'];
                $actions = $resource['actions'] ?? [];

                foreach ($actions as $action) {
                    $permission = Permission::updateOrCreate(
                        [
                            'name'       => $schema->name . ' ' . $action . ' ' . $resourceName,
                            'guard_name' => $guard,
                        ],
                        [
                            'name'       => $schema->name . ' ' . $action . ' ' . $resourceName,
                            'guard_name' => $guard,
                            'service'    => $schema->name,
                        ]
                    );
                    $this->line("  ✓ {$permission->name}");
                }
            }
            $this->newLine();

            // Create policies
            $this->info('📜 Creating policies...');
            foreach ($schema->policies as $policySchema) {
                $policy = Policy::updateOrCreate(
                    [
                        'name'       => $policySchema['name'],
                        'guard_name' => $guard,
                    ],
                    [
                        'name'        => $policySchema['name'],
                        'guard_name'  => $guard,
                        'description' => $policySchema['description'] ?? '',
                        'service'     => $schema->name,
                    ]
                );

                // Assign permissions to policy
                $policyPermissions = $policySchema['permissions'] ?? [];
                $assignedCount = 0;
                foreach ($policyPermissions as $permName) {
                    try {
                        // Handle wildcards and service prefixes
                        if (strpos($permName, '*') === 0) {
                            // Wildcard permission like "* delivery"
                            $permName = trim($permName);
                        } elseif (!str_contains($permName, ' ') && $permName !== 'see extension') {
                            // Add service prefix if not present
                            $permName = $schema->name . ' ' . $permName;
                        }

                        $permission = Permission::where('name', $permName)
                            ->where('guard_name', $guard)
                            ->first();

                        if ($permission) {
                            $policy->givePermissionTo($permission);
                            $assignedCount++;
                        }
                    } catch (\Exception $e) {
                        $this->warn("    ⚠ Could not assign permission '{$permName}': {$e->getMessage()}");
                    }
                }

                $this->line("  ✓ {$policy->name} ({$assignedCount} permissions)");
            }
            $this->newLine();

            // Create roles
            $this->info('👥 Creating roles...');
            foreach ($schema->roles as $roleSchema) {
                $role = Role::updateOrCreate(
                    [
                        'name'       => $roleSchema['name'],
                        'guard_name' => $guard,
                    ],
                    [
                        'name'        => $roleSchema['name'],
                        'guard_name'  => $guard,
                        'description' => $roleSchema['description'] ?? '',
                        'service'     => $schema->name,
                    ]
                );

                // Assign policies to role
                $rolePolicies = $roleSchema['policies'] ?? [];
                foreach ($rolePolicies as $policyName) {
                    $policy = Policy::where('name', $policyName)
                        ->where('guard_name', $guard)
                        ->first();

                    if ($policy) {
                        $role->attachPolicy($policy);
                    }
                }

                // Assign direct permissions to role
                $rolePermissions = $roleSchema['permissions'] ?? [];
                $assignedCount = 0;
                foreach ($rolePermissions as $permName) {
                    try {
                        // Handle wildcards and service prefixes
                        if (strpos($permName, '*') === 0) {
                            $permName = trim($permName);
                        } elseif (!str_contains($permName, ' ') && $permName !== 'see extension') {
                            $permName = $schema->name . ' ' . $permName;
                        }

                        $permission = Permission::where('name', $permName)
                            ->where('guard_name', $guard)
                            ->first();

                        if ($permission) {
                            $role->givePermissionTo($permission);
                            $assignedCount++;
                        }
                    } catch (\Exception $e) {
                        $this->warn("    ⚠ Could not assign permission '{$permName}': {$e->getMessage()}");
                    }
                }

                $this->info("  ✓ {$role->name}");
                $this->line("    Policies: " . count($rolePolicies));
                $this->line("    Direct Permissions: {$assignedCount}");
            }
            $this->newLine();

            $this->info('✅ REEUP roles seeded successfully!');
            $this->newLine();
            $this->comment('Roles created:');
            foreach ($schema->roles as $roleSchema) {
                $this->line("  • {$roleSchema['name']}");
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Failed to seed REEUP roles: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
