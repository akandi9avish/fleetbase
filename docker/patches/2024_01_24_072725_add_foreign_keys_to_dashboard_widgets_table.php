<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent to handle missing unique index on dashboards.uuid
     *
     * Original issue: The migration tries to add a foreign key referencing dashboards.uuid,
     * but dashboards.uuid only has a regular index, not a UNIQUE index. MySQL requires
     * referenced columns to have UNIQUE constraint.
     *
     * Solution: Add unique index to dashboards.uuid if missing, then add foreign key.
     *
     * @return void
     */
    public function up()
    {
        // Step 1: Ensure dashboards table has unique index on uuid column
        if (Schema::hasTable('dashboards') && Schema::hasColumn('dashboards', 'uuid')) {
            $indexName = 'dashboards_uuid_unique';

            // Check if unique index already exists
            $indexes = DB::select("SHOW INDEX FROM dashboards WHERE Key_name = ? AND Non_unique = 0", [$indexName]);

            if (empty($indexes)) {
                // Check if there's a non-unique index we need to drop first
                $regularIndexes = DB::select("SHOW INDEX FROM dashboards WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");

                Schema::table('dashboards', function (Blueprint $table) use ($regularIndexes) {
                    // Drop any existing non-unique indexes on uuid column
                    foreach ($regularIndexes as $index) {
                        if ($index->Non_unique == 1) {
                            try {
                                $table->dropIndex($index->Key_name);
                            } catch (\Exception $e) {
                                // Index might already be dropped, continue
                            }
                        }
                    }

                    // Add unique index
                    $table->unique('uuid', 'dashboards_uuid_unique');
                });
            }
        }

        // Step 2: Add foreign key constraint if it doesn't exist
        if (Schema::hasTable('dashboard_widgets') && Schema::hasColumn('dashboard_widgets', 'dashboard_uuid')) {
            // Check if foreign key already exists
            $foreignKeys = DB::select(
                "SELECT CONSTRAINT_NAME
                 FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = 'dashboard_widgets'
                 AND COLUMN_NAME = 'dashboard_uuid'
                 AND REFERENCED_TABLE_NAME = 'dashboards'"
            );

            if (empty($foreignKeys)) {
                Schema::table('dashboard_widgets', function (Blueprint $table) {
                    $table->foreign('dashboard_uuid')
                          ->references('uuid')
                          ->on('dashboards')
                          ->onUpdate('CASCADE')
                          ->onDelete('CASCADE');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('dashboard_widgets')) {
            Schema::table('dashboard_widgets', function (Blueprint $table) {
                // Check if foreign key exists before dropping
                $foreignKeys = DB::select(
                    "SELECT CONSTRAINT_NAME
                     FROM information_schema.KEY_COLUMN_USAGE
                     WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'dashboard_widgets'
                     AND COLUMN_NAME = 'dashboard_uuid'
                     AND REFERENCED_TABLE_NAME = 'dashboards'"
                );

                if (!empty($foreignKeys)) {
                    $table->dropForeign(['dashboard_uuid']);
                }
            });
        }
    }
};
