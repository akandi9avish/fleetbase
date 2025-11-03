<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent to handle table already existing from failed deployments
     *
     * Original issue: Migration uses Schema::create() without checking if table exists.
     * On container restart with existing table, throws "Table already exists" error.
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('custom_field_values')) {
            Schema::create('custom_field_values', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid');  // Don't add index yet
                $table->uuid('company_uuid')->nullable();
                $table->uuid('custom_field_uuid')->nullable();
                $table->uuid('subject_uuid');
                $table->string('subject_type');
                $table->text('value');
                $table->string('value_type');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Step 2: Ensure uuid has regular index if missing
        if (Schema::hasTable('custom_field_values')) {
            Schema::table('custom_field_values', function (Blueprint $table) {
                $uuidIndexes = DB::select("SHOW INDEX FROM custom_field_values WHERE Column_name = 'uuid'");
                if (empty($uuidIndexes)) {
                    try {
                        $table->index('uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 3: Add indexes on foreign key columns if missing
        if (Schema::hasTable('custom_field_values')) {
            Schema::table('custom_field_values', function (Blueprint $table) {
                // Check and add company_uuid index
                $companyIndexes = DB::select("SHOW INDEX FROM custom_field_values WHERE Column_name = 'company_uuid'");
                if (empty($companyIndexes)) {
                    try {
                        $table->index('company_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check and add custom_field_uuid index
                $customFieldIndexes = DB::select("SHOW INDEX FROM custom_field_values WHERE Column_name = 'custom_field_uuid'");
                if (empty($customFieldIndexes)) {
                    try {
                        $table->index('custom_field_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 4: Add foreign key for company_uuid if it doesn't exist
        $companyFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_field_values'
             AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
        );

        if (empty($companyFkExists)) {
            Schema::table('custom_field_values', function (Blueprint $table) {
                $table->foreign('company_uuid')
                      ->references('uuid')
                      ->on('companies')
                      ->onUpdate('CASCADE')
                      ->onDelete('CASCADE');
            });
        }

        // Step 5: Add foreign key for custom_field_uuid if it doesn't exist
        $customFieldFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_field_values'
             AND COLUMN_NAME = 'custom_field_uuid' AND REFERENCED_TABLE_NAME = 'custom_fields'"
        );

        if (empty($customFieldFkExists)) {
            Schema::table('custom_field_values', function (Blueprint $table) {
                $table->foreign('custom_field_uuid')
                      ->references('uuid')
                      ->on('custom_fields')
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
        Schema::dropIfExists('custom_field_values');
    }
};
