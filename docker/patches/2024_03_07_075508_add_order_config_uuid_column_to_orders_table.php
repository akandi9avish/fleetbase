<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent with prerequisite unique index check
     *
     * Original issue: Migration tries to add FK from orders.order_config_uuid to order_configs.uuid
     * but order_configs.uuid doesn't have a unique index. Fails with error 6125.
     * Also not idempotent - column already exists on retry.
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: Add column to orders table if it doesn't exist
        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'order_config_uuid')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->char('order_config_uuid', 36)->nullable()->after('updated_by_uuid');
            });
        }

        // Step 2: Ensure order_configs.uuid has unique index BEFORE adding FK
        if (Schema::hasTable('order_configs') && Schema::hasColumn('order_configs', 'uuid')) {
            $uniqueIndexName = 'order_configs_uuid_unique';
            $indexes = DB::select("SHOW INDEX FROM order_configs WHERE Key_name = ? AND Non_unique = 0", [$uniqueIndexName]);

            if (empty($indexes)) {
                // Drop any existing non-unique indexes on uuid
                $regularIndexes = DB::select("SHOW INDEX FROM order_configs WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");

                foreach ($regularIndexes as $index) {
                    if ($index->Non_unique == 1) {
                        try {
                            DB::statement("DROP INDEX {$index->Key_name} ON order_configs");
                        } catch (\Exception $e) {
                            // Already dropped, continue
                        }
                    }
                }

                // Add unique index to order_configs.uuid
                try {
                    DB::statement("ALTER TABLE order_configs ADD UNIQUE INDEX {$uniqueIndexName} (uuid)");
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }

        // Step 3: Add index on order_config_uuid if missing
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'order_config_uuid')) {
            $orderConfigIndexes = DB::select("SHOW INDEX FROM orders WHERE Column_name = 'order_config_uuid'");
            if (empty($orderConfigIndexes)) {
                try {
                    Schema::table('orders', function (Blueprint $table) {
                        $table->index('order_config_uuid');
                    });
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }

        // Step 4: Add foreign key if it doesn't exist
        $fkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'
             AND COLUMN_NAME = 'order_config_uuid' AND REFERENCED_TABLE_NAME = 'order_configs'"
        );

        if (empty($fkExists)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('order_config_uuid')
                      ->references('uuid')
                      ->on('order_configs')
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
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['order_config_uuid']);
            $table->dropColumn(['order_config_uuid']);
        });
    }
};
