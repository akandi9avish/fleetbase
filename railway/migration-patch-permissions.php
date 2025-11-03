<?php

/**
 * Migration Patch for create_permissions_table
 *
 * This file patches the Fleetbase Core-API permissions migration to be idempotent.
 * It adds defensive checks before creating tables to prevent "Table already exists" errors.
 *
 * Apply this patch during Docker build by replacing the original migration file.
 *
 * Original File: vendor/fleetbase/core-api/migrations/2023_04_25_094304_create_permissions_table.php
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up()
    {
        $tableNames  = config('permission.table_names');
        $columnNames = config('permission.column_names');

        if (empty($tableNames)) {
            throw new \Exception('Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }

        // DEFENSIVE CHECK: Create permissions table only if it doesn't exist
        if (!Schema::hasTable($tableNames['permissions'])) {
            Schema::create($tableNames['permissions'], function (Blueprint $table) {
                $table->uuid('id')->index();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        // DEFENSIVE CHECK: Create roles table only if it doesn't exist
        if (!Schema::hasTable($tableNames['roles'])) {
            Schema::create($tableNames['roles'], function (Blueprint $table) {
                $table->uuid('id')->index();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        // DEFENSIVE CHECK: Create model_has_permissions pivot table only if it doesn't exist
        if (!Schema::hasTable($tableNames['model_has_permissions'])) {
            Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames) {
                $table->uuid('permission_id')->index();
                $table->string('model_type');
                $table->uuid($columnNames['model_morph_key']);
                $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_uuid_model_type_index');
                $table->foreign('permission_id')->references('id')->on($tableNames['permissions'])->onDelete('cascade');
                $table->primary(['permission_id', $columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_permission_model_type_primary');
            });
        }

        // DEFENSIVE CHECK: Create model_has_roles pivot table only if it doesn't exist
        if (!Schema::hasTable($tableNames['model_has_roles'])) {
            Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames) {
                $table->uuid('role_id')->index();
                $table->string('model_type');
                $table->uuid($columnNames['model_morph_key']);
                $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_uuid_model_type_index');
                $table->foreign('role_id')->references('id')->on($tableNames['roles'])->onDelete('cascade');
                $table->primary(['role_id', $columnNames['model_morph_key'], 'model_type'], 'model_has_roles_role_model_type_primary');
            });
        }

        // DEFENSIVE CHECK: Create role_has_permissions pivot table only if it doesn't exist
        if (!Schema::hasTable($tableNames['role_has_permissions'])) {
            Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames) {
                $table->uuid('permission_id')->index();
                $table->uuid('role_id')->index();
                $table->foreign('permission_id')->references('id')->on($tableNames['permissions'])->onDelete('cascade');
                $table->foreign('role_id')->references('id')->on($tableNames['roles'])->onDelete('cascade');
                $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
            });
        }

        // Clear permission cache
        app('cache')->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)->forget(config('permission.cache.key'));
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        $tableNames = config('permission.table_names');

        if (empty($tableNames)) {
            throw new \Exception('Error: config/permission.php not found and defaults could not be merged. Please publish the package configuration before proceeding, or drop the tables manually.');
        }

        // Drop tables in reverse order (foreign key dependencies)
        if (Schema::hasTable($tableNames['role_has_permissions'])) {
            Schema::drop($tableNames['role_has_permissions']);
        }

        if (Schema::hasTable($tableNames['model_has_roles'])) {
            Schema::drop($tableNames['model_has_roles']);
        }

        if (Schema::hasTable($tableNames['model_has_permissions'])) {
            Schema::drop($tableNames['model_has_permissions']);
        }

        if (Schema::hasTable($tableNames['roles'])) {
            Schema::drop($tableNames['roles']);
        }

        if (Schema::hasTable($tableNames['permissions'])) {
            Schema::drop($tableNames['permissions']);
        }
    }
};
