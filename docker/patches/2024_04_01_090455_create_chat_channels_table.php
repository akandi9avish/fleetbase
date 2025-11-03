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
     * Original issue: Migration creates chat_channels.uuid with regular index, but
     * chat_participants, chat_messages, and chat_attachments reference it with FKs.
     * Fails because chat_channels.uuid isn't UNIQUE. Not idempotent - table gets created
     * but foreign keys don't when they're added later.
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('chat_channels')) {
            Schema::create('chat_channels', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->nullable();  // Don't add index yet
                $table->string('public_id')->nullable();
                $table->uuid('company_uuid')->nullable();
                $table->uuid('created_by_uuid')->nullable();
                $table->string('name')->nullable();
                $table->string('slug')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Step 2: Ensure uuid has unique index (required for FK references)
        if (Schema::hasTable('chat_channels') && Schema::hasColumn('chat_channels', 'uuid')) {
            $uniqueIndexName = 'chat_channels_uuid_unique';
            $indexes = DB::select("SHOW INDEX FROM chat_channels WHERE Key_name = ? AND Non_unique = 0", [$uniqueIndexName]);

            if (empty($indexes)) {
                // Drop any existing non-unique indexes on uuid
                $regularIndexes = DB::select("SHOW INDEX FROM chat_channels WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");

                Schema::table('chat_channels', function (Blueprint $table) use ($regularIndexes) {
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
                    $table->unique('uuid', 'chat_channels_uuid_unique');
                });
            }
        }

        // Step 3: Add indexes on public_id, company_uuid, created_by_uuid if missing
        if (Schema::hasTable('chat_channels')) {
            Schema::table('chat_channels', function (Blueprint $table) {
                // Check and add public_id index
                $publicIdIndexes = DB::select("SHOW INDEX FROM chat_channels WHERE Column_name = 'public_id'");
                if (empty($publicIdIndexes)) {
                    try {
                        $table->index('public_id');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check and add company_uuid index
                $companyIndexes = DB::select("SHOW INDEX FROM chat_channels WHERE Column_name = 'company_uuid'");
                if (empty($companyIndexes)) {
                    try {
                        $table->index('company_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check and add created_by_uuid index
                $createdByIndexes = DB::select("SHOW INDEX FROM chat_channels WHERE Column_name = 'created_by_uuid'");
                if (empty($createdByIndexes)) {
                    try {
                        $table->index('created_by_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 4: Add foreign key for company_uuid if it doesn't exist
        $companyFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_channels'
             AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
        );

        if (empty($companyFkExists)) {
            Schema::table('chat_channels', function (Blueprint $table) {
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
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_channels'
             AND COLUMN_NAME = 'created_by_uuid' AND REFERENCED_TABLE_NAME = 'users'"
        );

        if (empty($createdByFkExists)) {
            Schema::table('chat_channels', function (Blueprint $table) {
                $table->foreign('created_by_uuid')
                      ->references('uuid')
                      ->on('users')
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
        Schema::dropIfExists('chat_channels');
    }
};
