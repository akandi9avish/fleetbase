<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent with database state fixes for warranties.uuid
     *
     * Original issue: Migration tries to create telematics table and add FK to warranties.uuid
     * which doesn't have a UNIQUE constraint. Fails with error 6125.
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('telematics')) {
            Schema::create('telematics', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid');
                $table->string('_key')->nullable();
                $table->uuid('company_uuid');
                $table->string('name')->nullable();
                $table->string('provider')->nullable();
                $table->string('model')->nullable();
                $table->string('serial_number')->nullable();
                $table->string('firmware_version')->nullable();
                $table->string('status')->default('active');
                $table->string('imei')->nullable();
                $table->string('iccid')->nullable();
                $table->string('imsi')->nullable();
                $table->string('msisdn')->nullable();
                $table->string('type')->nullable();
                $table->json('last_metrics')->nullable();
                $table->json('config')->nullable();
                $table->json('meta')->nullable();
                $table->uuid('created_by_uuid')->nullable();
                $table->uuid('updated_by_uuid')->nullable();
                $table->uuid('warranty_uuid')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->softDeletes();
                $table->timestamps();
                // Don't add FKs yet - will be added after ensuring prerequisites
            });
        }

        // Step 2: Add indexes on foreign key columns if missing
        if (Schema::hasTable('telematics')) {
            Schema::table('telematics', function (Blueprint $table) {
                // Check for uuid index
                $uuidIndexes = DB::select("SHOW INDEX FROM telematics WHERE Column_name = 'uuid'");
                if (empty($uuidIndexes)) {
                    try {
                        $table->index('uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check for company_uuid index
                $companyIndexes = DB::select("SHOW INDEX FROM telematics WHERE Column_name = 'company_uuid'");
                if (empty($companyIndexes)) {
                    try {
                        $table->index('company_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check for warranty_uuid index
                $warrantyIndexes = DB::select("SHOW INDEX FROM telematics WHERE Column_name = 'warranty_uuid'");
                if (empty($warrantyIndexes)) {
                    try {
                        $table->index('warranty_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 3: CRITICAL - Ensure warranties.uuid has a UNIQUE constraint
        // The warranties table was just created in the previous migration
        // We need it to have PRIMARY or UNIQUE for FK to work
        if (Schema::hasTable('warranties') && Schema::hasColumn('warranties', 'uuid')) {
            echo "🔍 Checking warranties.uuid for UNIQUE constraint...\n";

            // Check if uuid column has UNIQUE constraint
            $uniqueIndexes = DB::select("SHOW INDEX FROM warranties WHERE Column_name = 'uuid' AND Non_unique = 0 AND Key_name != 'PRIMARY'");

            if (empty($uniqueIndexes)) {
                echo "⚠️  warranties.uuid does NOT have UNIQUE constraint - fixing now...\n";

                // Drop any existing non-unique indexes on uuid column
                $existingIndexes = DB::select("SHOW INDEX FROM warranties WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");
                foreach ($existingIndexes as $index) {
                    if ($index->Non_unique == 1) {
                        try {
                            DB::statement("DROP INDEX {$index->Key_name} ON warranties");
                            echo "Dropped non-unique index {$index->Key_name} from warranties.uuid\n";
                        } catch (\Exception $e) {
                            echo "Warning: Could not drop index: {$e->getMessage()}\n";
                        }
                    }
                }

                // Add UNIQUE constraint to warranties.uuid
                try {
                    DB::statement("ALTER TABLE warranties ADD UNIQUE INDEX warranties_uuid_unique (uuid)");
                    echo "✅ Added UNIQUE constraint to warranties.uuid\n";
                } catch (\Exception $e) {
                    echo "⚠️  CRITICAL: Could not add UNIQUE to warranties.uuid: {$e->getMessage()}\n";
                    throw $e;
                }
            } else {
                echo "✅ warranties.uuid already has UNIQUE constraint\n";
            }
        }

        // Step 4: Add foreign key for company_uuid if it doesn't exist
        $companyFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telematics'
             AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
        );

        if (empty($companyFkExists)) {
            Schema::table('telematics', function (Blueprint $table) {
                $table->foreign('company_uuid')
                      ->references('uuid')
                      ->on('companies')
                      ->onUpdate('CASCADE')
                      ->onDelete('CASCADE');
            });
        }

        // Step 5: Add foreign key for created_by_uuid if it doesn't exist
        $createdByFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telematics'
             AND COLUMN_NAME = 'created_by_uuid' AND REFERENCED_TABLE_NAME = 'users'"
        );

        if (empty($createdByFkExists)) {
            Schema::table('telematics', function (Blueprint $table) {
                $table->foreign('created_by_uuid')
                      ->references('uuid')
                      ->on('users')
                      ->onUpdate('CASCADE')
                      ->onDelete('SET NULL');
            });
        }

        // Step 6: Add foreign key for updated_by_uuid if it doesn't exist
        $updatedByFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telematics'
             AND COLUMN_NAME = 'updated_by_uuid' AND REFERENCED_TABLE_NAME = 'users'"
        );

        if (empty($updatedByFkExists)) {
            Schema::table('telematics', function (Blueprint $table) {
                $table->foreign('updated_by_uuid')
                      ->references('uuid')
                      ->on('users')
                      ->onUpdate('CASCADE')
                      ->onDelete('SET NULL');
            });
        }

        // Step 7: Add foreign key for warranty_uuid if it doesn't exist
        $warrantyFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'telematics'
             AND COLUMN_NAME = 'warranty_uuid' AND REFERENCED_TABLE_NAME = 'warranties'"
        );

        if (empty($warrantyFkExists)) {
            Schema::table('telematics', function (Blueprint $table) {
                $table->foreign('warranty_uuid')
                      ->references('uuid')
                      ->on('warranties')
                      ->onUpdate('CASCADE')
                      ->onDelete('SET NULL');
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
        Schema::dropIfExists('telematics');
    }
};
