<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PRE-MIGRATION CLEANUP
     *
     * This migration runs BEFORE any 2025_08_28 migrations to clean up
     * partially created tables from previous failed deployments.
     *
     * Timestamp: 2025_08_01_000000 ensures it runs FIRST
     *
     * @return void
     */
    public function up(): void
    {
        echo "\n🧹 CLEANUP: Preparing database for 2025 migrations\n";
        echo "=====================================================\n\n";

        // List of tables that may have been partially created in failed deployments
        $tablesToClean = [
            'telematics',
            'warranties',
            'assets',       // 2025_08_28_054922: May fail due to telematics.uuid not having UNIQUE
            'devices', // This is renamed from vehicle_devices, but cleanup the new name if exists
            'device_events', // This is renamed from vehicle_device_events
            'sensors',
            'parts',
            'equipments',
            'work_orders',
            'maintenances',
        ];

        $tablesDropped = [];
        $tablesNotFound = [];

        foreach ($tablesToClean as $table) {
            if (Schema::hasTable($table)) {
                echo "🗑️  Found table: {$table} - will drop and reset migration\n";

                try {
                    // Disable foreign key checks temporarily
                    DB::statement('SET FOREIGN_KEY_CHECKS=0');
                    Schema::dropIfExists($table);
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');

                    echo "   ✅ Dropped table: {$table}\n";

                    // CRITICAL: Also remove migration entry so it will run again with new code
                    // This ensures migrations with fixes (like adding UNIQUE to uuid) will re-run
                    $deleted = DB::table('migrations')
                        ->where('migration', 'like', "%create_{$table}_table%")
                        ->orWhere('migration', 'like', "%{$table}%")
                        ->delete();

                    if ($deleted > 0) {
                        echo "   ✅ Removed {$deleted} migration entry(ies) for {$table} - will re-run\n";
                    }

                    $tablesDropped[] = $table;
                } catch (\Exception $e) {
                    echo "   ⚠️  Could not drop {$table}: {$e->getMessage()}\n";
                }
            } else {
                // Table doesn't exist - but check if migration entry exists and remove it
                echo "ℹ️  Table {$table} not found - checking migration entries...\n";

                $deleted = DB::table('migrations')
                    ->where('migration', 'like', "%create_{$table}_table%")
                    ->orWhere('migration', 'like', "%{$table}%")
                    ->delete();

                if ($deleted > 0) {
                    echo "   ✅ Removed {$deleted} stale migration entry(ies) for {$table}\n";
                }

                $tablesNotFound[] = $table;
            }
        }

        echo "\n=====================================================\n";
        echo "Cleanup Summary:\n";
        echo "- Tables dropped: " . count($tablesDropped) . "\n";
        if (count($tablesDropped) > 0) {
            echo "  • " . implode("\n  • ", $tablesDropped) . "\n";
        }
        echo "- Tables not found (good): " . count($tablesNotFound) . "\n";
        echo "=====================================================\n";
        echo "✅ Database cleanup complete - ready for 2025 migrations\n\n";
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        echo "⚠️  Cleanup migration cannot be reversed\n";
    }
};
