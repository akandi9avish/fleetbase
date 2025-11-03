<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent with prerequisite unique index checks
     *
     * Original issue: Migration tries to create registry_extension_bundles table and
     * establish bidirectional FKs with registry_extensions. Fails with:
     * 1. "Table already exists" error on restart
     * 2. registry_extension_bundles.uuid needs UNIQUE index for bidirectional FKs
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('registry_extension_bundles')) {
            Schema::create('registry_extension_bundles', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid');  // Don't add index yet - will be unique index below
                $table->uuid('public_id');
                $table->uuid('bundle_id');
                $table->uuid('company_uuid');
                $table->uuid('created_by_uuid')->nullable();
                $table->uuid('extension_uuid')->nullable();
                $table->uuid('bundle_uuid')->nullable();
                $table->string('bundle_number')->nullable();
                $table->string('version')->nullable();
                $table->string('status')->default('pending');
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
                // Don't add FKs yet - will be added in steps below after ensuring prerequisites
            });
        }

        // Step 2: Add UNIQUE index on registry_extension_bundles.uuid (so registry_extensions can reference it)
        if (Schema::hasTable('registry_extension_bundles') && Schema::hasColumn('registry_extension_bundles', 'uuid')) {
            $uniqueIndexName = 'registry_extension_bundles_uuid_unique';
            $indexes = DB::select("SHOW INDEX FROM registry_extension_bundles WHERE Key_name = ? AND Non_unique = 0", [$uniqueIndexName]);

            if (empty($indexes)) {
                // Drop any existing non-unique indexes on uuid
                $regularIndexes = DB::select("SHOW INDEX FROM registry_extension_bundles WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");

                foreach ($regularIndexes as $index) {
                    if ($index->Non_unique == 1) {
                        try {
                            DB::statement("DROP INDEX {$index->Key_name} ON registry_extension_bundles");
                        } catch (\Exception $e) {
                            // Already dropped, continue
                        }
                    }
                }

                // Add unique index to registry_extension_bundles.uuid
                try {
                    DB::statement("ALTER TABLE registry_extension_bundles ADD UNIQUE INDEX {$uniqueIndexName} (uuid)");
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }

        // Step 3: CRITICAL - Ensure registry_extensions.uuid has unique index BEFORE we add FK to it
        // The registry_extensions migration has already run, so we need to add this here
        if (Schema::hasTable('registry_extensions') && Schema::hasColumn('registry_extensions', 'uuid')) {
            // Check if there's ANY unique index on the uuid column
            $uniqueIndexes = DB::select("SHOW INDEX FROM registry_extensions WHERE Column_name = 'uuid' AND Non_unique = 0 AND Key_name != 'PRIMARY'");

            if (empty($uniqueIndexes)) {
                echo "⚠️  registry_extensions.uuid does NOT have a unique index - adding one now...\n";

                // Drop ALL non-unique indexes on uuid column first
                $allIndexes = DB::select("SHOW INDEX FROM registry_extensions WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");
                foreach ($allIndexes as $index) {
                    try {
                        DB::statement("DROP INDEX {$index->Key_name} ON registry_extensions");
                        echo "Dropped index {$index->Key_name} from registry_extensions.uuid\n";
                    } catch (\Exception $e) {
                        echo "Warning: Could not drop index {$index->Key_name}: {$e->getMessage()}\n";
                    }
                }

                // Now add the unique index
                $uniqueIndexName = 'registry_extensions_uuid_unique';
                try {
                    DB::statement("ALTER TABLE registry_extensions ADD UNIQUE INDEX {$uniqueIndexName} (uuid)");
                    echo "✅ Added unique index {$uniqueIndexName} to registry_extensions.uuid\n";
                } catch (\Exception $e) {
                    echo "⚠️  CRITICAL ERROR: Could not add unique index to registry_extensions.uuid: {$e->getMessage()}\n";
                    throw $e;
                }
            } else {
                echo "ℹ️  registry_extensions.uuid already has unique index - good!\n";
            }
        }

        // Step 4: Add indexes on other uuid columns if missing
        if (Schema::hasTable('registry_extension_bundles')) {
            Schema::table('registry_extension_bundles', function (Blueprint $table) {
                // Check for public_id index
                $publicIdIndexes = DB::select("SHOW INDEX FROM registry_extension_bundles WHERE Column_name = 'public_id'");
                if (empty($publicIdIndexes)) {
                    try {
                        $table->index('public_id');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check for bundle_id index
                $bundleIdIndexes = DB::select("SHOW INDEX FROM registry_extension_bundles WHERE Column_name = 'bundle_id'");
                if (empty($bundleIdIndexes)) {
                    try {
                        $table->index('bundle_id');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 5: Add foreign keys from registry_extension_bundles to other tables if they don't exist
        if (Schema::hasTable('registry_extension_bundles')) {
            // Check and add company_uuid FK
            $companyFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extension_bundles'
                 AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
            );

            if (empty($companyFkExists)) {
                Schema::table('registry_extension_bundles', function (Blueprint $table) {
                    $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
                });
            }

            // Check and add created_by_uuid FK
            $createdByFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extension_bundles'
                 AND COLUMN_NAME = 'created_by_uuid' AND REFERENCED_TABLE_NAME = 'users'"
            );

            if (empty($createdByFkExists)) {
                Schema::table('registry_extension_bundles', function (Blueprint $table) {
                    $table->foreign('created_by_uuid')->references('uuid')->on('users')->onDelete('cascade');
                });
            }

            // Check and add extension_uuid FK
            $extensionFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extension_bundles'
                 AND COLUMN_NAME = 'extension_uuid' AND REFERENCED_TABLE_NAME = 'registry_extensions'"
            );

            if (empty($extensionFkExists)) {
                Schema::table('registry_extension_bundles', function (Blueprint $table) {
                    $table->foreign('extension_uuid')->references('uuid')->on('registry_extensions')->onDelete('cascade');
                });
            }

            // Check and add bundle_uuid FK (references files table)
            $bundleFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extension_bundles'
                 AND COLUMN_NAME = 'bundle_uuid' AND REFERENCED_TABLE_NAME = 'files'"
            );

            if (empty($bundleFkExists)) {
                Schema::table('registry_extension_bundles', function (Blueprint $table) {
                    $table->foreign('bundle_uuid')->references('uuid')->on('files')->onDelete('cascade');
                });
            }
        }

        // Step 6: Add bidirectional FKs from registry_extensions to registry_extension_bundles
        if (Schema::hasTable('registry_extensions')) {
            // Check and add current_bundle_uuid FK
            $currentBundleFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extensions'
                 AND COLUMN_NAME = 'current_bundle_uuid' AND REFERENCED_TABLE_NAME = 'registry_extension_bundles'"
            );

            if (empty($currentBundleFkExists)) {
                Schema::table('registry_extensions', function (Blueprint $table) {
                    $table->foreign('current_bundle_uuid')->references('uuid')->on('registry_extension_bundles')->onDelete('cascade');
                });
            }

            // Check and add next_bundle_uuid FK
            $nextBundleFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extensions'
                 AND COLUMN_NAME = 'next_bundle_uuid' AND REFERENCED_TABLE_NAME = 'registry_extension_bundles'"
            );

            if (empty($nextBundleFkExists)) {
                Schema::table('registry_extensions', function (Blueprint $table) {
                    $table->foreign('next_bundle_uuid')->references('uuid')->on('registry_extension_bundles')->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        if (Schema::hasTable('registry_extensions')) {
            Schema::table('registry_extensions', function (Blueprint $table) {
                $table->dropForeign(['current_bundle_uuid']);
                $table->dropForeign(['next_bundle_uuid']);
            });
        }

        Schema::dropIfExists('registry_extension_bundles');
    }
};
