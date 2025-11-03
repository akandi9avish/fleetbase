<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent with unique index on uuid for foreign key support
     *
     * Original issue: Migration creates custom_fields.uuid with regular index, but
     * custom_field_values references it with FK. Fails because custom_fields.uuid isn't UNIQUE.
     *
     * @return void
     */
    public function up()
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('custom_fields')) {
            Schema::create('custom_fields', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid');  // Don't add index yet
                $table->uuid('company_uuid')->nullable();
                $table->uuid('subject_uuid');
                $table->string('subject_type');
                $table->string('name');
                $table->string('label');
                $table->string('type');
                $table->string('component')->nullable();
                $table->json('options')->nullable();
                $table->boolean('required')->default(false);
                $table->text('default_value')->nullable();
                $table->json('validation_rules')->nullable();
                $table->json('meta')->nullable();
                $table->mediumText('description')->nullable();
                $table->mediumText('help_text')->nullable();
                $table->integer('order')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Step 2: Ensure uuid has unique index (required for FK references)
        if (Schema::hasTable('custom_fields') && Schema::hasColumn('custom_fields', 'uuid')) {
            $uniqueIndexName = 'custom_fields_uuid_unique';
            $indexes = DB::select("SHOW INDEX FROM custom_fields WHERE Key_name = ? AND Non_unique = 0", [$uniqueIndexName]);

            if (empty($indexes)) {
                // Drop any existing non-unique indexes on uuid
                $regularIndexes = DB::select("SHOW INDEX FROM custom_fields WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");

                Schema::table('custom_fields', function (Blueprint $table) use ($regularIndexes) {
                    foreach ($regularIndexes as $index) {
                        if ($index->Non_unique == 1) {
                            try {
                                $table->dropIndex($index->Key_name);
                            } catch (\Exception $e) {
                                // Already dropped, continue
                            }
                        }
                    }

                    // Add unique index
                    $table->unique('uuid', 'custom_fields_uuid_unique');
                });
            }
        }

        // Step 3: Add index on company_uuid if missing
        if (Schema::hasTable('custom_fields')) {
            Schema::table('custom_fields', function (Blueprint $table) {
                // Check and add company_uuid index
                $companyIndexes = DB::select("SHOW INDEX FROM custom_fields WHERE Column_name = 'company_uuid'");
                if (empty($companyIndexes)) {
                    try {
                        $table->index('company_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 4: Add foreign key for company_uuid if it doesn't exist
        $companyFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'custom_fields'
             AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
        );

        if (empty($companyFkExists)) {
            Schema::table('custom_fields', function (Blueprint $table) {
                $table->foreign('company_uuid')
                      ->references('uuid')
                      ->on('companies')
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
    public function down()
    {
        Schema::dropIfExists('custom_fields');
    }
};
