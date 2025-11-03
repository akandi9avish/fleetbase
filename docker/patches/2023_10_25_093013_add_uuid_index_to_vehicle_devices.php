<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Add missing UUID index to vehicle_devices table
     * This prevents foreign key constraint errors in subsequent migrations.
     *
     * Problem: The create_vehicle_devices_table migration doesn't add an index
     * on the uuid column, but create_vehicle_device_events_table needs to
     * reference it with a foreign key.
     *
     * Solution: Add the index idempotently before the events table migration runs.
     */
    public function up()
    {
        // Check if vehicle_devices table exists
        if (!Schema::hasTable('vehicle_devices')) {
            return; // Table doesn't exist yet, skip
        }

        // Check if uuid column exists
        if (!Schema::hasColumn('vehicle_devices', 'uuid')) {
            return; // Column doesn't exist, skip
        }

        // Check if index already exists
        $indexName = 'vehicle_devices_uuid_index';
        $indexes = DB::select("SHOW INDEX FROM vehicle_devices WHERE Key_name = ?", [$indexName]);

        if (empty($indexes)) {
            // Index doesn't exist, add it
            Schema::table('vehicle_devices', function (Blueprint $table) {
                $table->index('uuid', 'vehicle_devices_uuid_index');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        if (Schema::hasTable('vehicle_devices')) {
            Schema::table('vehicle_devices', function (Blueprint $table) {
                $indexName = 'vehicle_devices_uuid_index';
                $indexes = DB::select("SHOW INDEX FROM vehicle_devices WHERE Key_name = ?", [$indexName]);

                if (!empty($indexes)) {
                    $table->dropIndex($indexName);
                }
            });
        }
    }
};
