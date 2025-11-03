<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent with prerequisite checks
     *
     * Original issue: Migration tries to create directives table and add FK to permissions.
     * Fails with "Table already exists" on retry and FK constraint errors.
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('directives')) {
            Schema::create('directives', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->nullable();
                $table->uuid('company_uuid')->nullable();
                $table->uuid('permission_uuid')->nullable();
                $table->string('subject_type')->nullable();
                $table->uuid('subject_uuid')->nullable();
                $table->mediumText('key')->nullable();
                $table->json('rules')->nullable();
                $table->timestamps();
                $table->softDeletes();
                // Don't add FKs yet - add them in steps below after ensuring prerequisites
            });
        }

        // Step 2: Add indexes on foreign key columns if missing
        if (Schema::hasTable('directives')) {
            Schema::table('directives', function (Blueprint $table) {
                // Check for uuid index
                $uuidIndexes = DB::select("SHOW INDEX FROM directives WHERE Column_name = 'uuid'");
                if (empty($uuidIndexes)) {
                    try {
                        $table->index('uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check for company_uuid index
                $companyIndexes = DB::select("SHOW INDEX FROM directives WHERE Column_name = 'company_uuid'");
                if (empty($companyIndexes)) {
                    try {
                        $table->index('company_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check for permission_uuid index
                $permissionIndexes = DB::select("SHOW INDEX FROM directives WHERE Column_name = 'permission_uuid'");
                if (empty($permissionIndexes)) {
                    try {
                        $table->index('permission_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 3: Add foreign key for company_uuid if it doesn't exist
        $companyFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'directives'
             AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
        );

        if (empty($companyFkExists)) {
            Schema::table('directives', function (Blueprint $table) {
                $table->foreign('company_uuid')
                      ->references('uuid')
                      ->on('companies')
                      ->onUpdate('CASCADE')
                      ->onDelete('CASCADE');
            });
        }

        // Step 4: Add foreign key for permission_uuid if it doesn't exist
        // The permissions table uses 'id' as the primary key (which is a UUID)
        $permissionFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'directives'
             AND COLUMN_NAME = 'permission_uuid' AND REFERENCED_TABLE_NAME = 'permissions'"
        );

        if (empty($permissionFkExists)) {
            Schema::table('directives', function (Blueprint $table) {
                $table->foreign('permission_uuid')
                      ->references('id')  // permissions table uses 'id' as primary key
                      ->on('permissions')
                      ->onUpdate('CASCADE')
                      ->onDelete('CASCADE');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('directives');
    }
};
