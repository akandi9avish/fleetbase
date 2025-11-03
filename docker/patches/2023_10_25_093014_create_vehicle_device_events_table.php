<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent to handle database remnants from previous deployments
     *
     * Original issue: This migration adds an index to vehicle_devices without checking
     * if it already exists, causing "Duplicate key name" errors on retries.
     *
     * @return void
     */
    public function up()
    {
        // Fix indexes on vehicle_devices table (IDEMPOTENT VERSION)
        if (Schema::hasTable('vehicle_devices') && Schema::hasColumn('vehicle_devices', 'uuid')) {
            $indexName = 'vehicle_devices_uuid_index';
            $indexes = DB::select("SHOW INDEX FROM vehicle_devices WHERE Key_name = ?", [$indexName]);

            if (empty($indexes)) {
                Schema::table('vehicle_devices', function (Blueprint $table) {
                    $table->index('uuid');
                });
            }
        }

        // Create events table (only if it doesn't exist)
        if (!Schema::hasTable('vehicle_device_events')) {
            Schema::create('vehicle_device_events', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->nullable();
                $table->uuid('vehicle_device_uuid');
                $table->foreign('vehicle_device_uuid')->references('uuid')->on('vehicle_devices');
                $table->json('payload')->nullable();
                $table->json('meta')->nullable();
                $table->string('ident')->nullable();
                $table->string('protocol')->nullable();
                $table->string('provider')->nullable();
                $table->point('location')->nullable();
                $table->string('mileage')->nullable();
                $table->string('state')->nullable();
                $table->string('code')->nullable();
                $table->string('reason')->nullable();
                $table->softDeletes();
                $table->timestamps();
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
        Schema::dropIfExists('vehicle_device_events');

        // Fix indexes on vehicle_devices table
        if (Schema::hasTable('vehicle_devices')) {
            Schema::table('vehicle_devices', function (Blueprint $table) {
                $indexName = 'vehicle_devices_uuid_index';
                $indexes = DB::select("SHOW INDEX FROM vehicle_devices WHERE Key_name = ?", [$indexName]);

                if (!empty($indexes)) {
                    $table->dropIndex(['uuid']);
                }
            });
        }
    }
};
