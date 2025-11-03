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
                echo "🗑️  Found partially created table: {$table}\n";

                // Check if migration was marked as complete
                $migrationCompleted = DB::table('migrations')
                    ->where('migration', 'like', "%create_{$table}_table%")
                    ->orWhere('migration', 'like', "%{$table}%")
                    ->exists();

                if ($migrationCompleted) {
                    echo "   ✓ Migration marked complete for {$table} - skipping cleanup\n";
                    continue;
                }

                try {
                    // Disable foreign key checks temporarily
                    DB::statement('SET FOREIGN_KEY_CHECKS=0');
                    Schema::dropIfExists($table);
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');

                    $tablesDropped[] = $table;
                    echo "   ✅ Dropped incomplete table: {$table}\n";
                } catch (\Exception $e) {
                    echo "   ⚠️  Could not drop {$table}: {$e->getMessage()}\n";
                }
            } else {
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
