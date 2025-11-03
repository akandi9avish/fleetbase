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
     * Original issue: Migration creates chat_messages.uuid with regular index, but
     * chat_attachments and chat_receipts reference it with FKs.
     * Fails because chat_messages.uuid isn't UNIQUE.
     *
     * Also fixes typo: nullables() -> nullable()
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->nullable();  // Don't add index yet
                $table->string('public_id')->nullable();  // Note: Fixed typo from original nullables()
                $table->uuid('company_uuid')->nullable();
                $table->uuid('sender_uuid')->nullable();
                $table->uuid('chat_channel_uuid')->nullable();
                $table->mediumText('content')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Step 2: Ensure uuid has unique index (required for FK references)
        if (Schema::hasTable('chat_messages') && Schema::hasColumn('chat_messages', 'uuid')) {
            $uniqueIndexName = 'chat_messages_uuid_unique';
            $indexes = DB::select("SHOW INDEX FROM chat_messages WHERE Key_name = ? AND Non_unique = 0", [$uniqueIndexName]);

            if (empty($indexes)) {
                // Drop any existing non-unique indexes on uuid
                $regularIndexes = DB::select("SHOW INDEX FROM chat_messages WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");

                Schema::table('chat_messages', function (Blueprint $table) use ($regularIndexes) {
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
                    $table->unique('uuid', 'chat_messages_uuid_unique');
                });
            }
        }

        // Step 3: Add indexes on foreign key columns if missing
        if (Schema::hasTable('chat_messages')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                // Check and add public_id index
                $publicIdIndexes = DB::select("SHOW INDEX FROM chat_messages WHERE Column_name = 'public_id'");
                if (empty($publicIdIndexes)) {
                    try {
                        $table->index('public_id');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check and add company_uuid index
                $companyIndexes = DB::select("SHOW INDEX FROM chat_messages WHERE Column_name = 'company_uuid'");
                if (empty($companyIndexes)) {
                    try {
                        $table->index('company_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check and add sender_uuid index
                $senderIndexes = DB::select("SHOW INDEX FROM chat_messages WHERE Column_name = 'sender_uuid'");
                if (empty($senderIndexes)) {
                    try {
                        $table->index('sender_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check and add chat_channel_uuid index
                $channelIndexes = DB::select("SHOW INDEX FROM chat_messages WHERE Column_name = 'chat_channel_uuid'");
                if (empty($channelIndexes)) {
                    try {
                        $table->index('chat_channel_uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 4: Add foreign key for company_uuid if it doesn't exist
        $companyFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages'
             AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
        );

        if (empty($companyFkExists)) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->foreign('company_uuid')
                      ->references('uuid')
                      ->on('companies')
                      ->onUpdate('CASCADE')
                      ->onDelete('CASCADE');
            });
        }

        // Step 5: Add foreign key for sender_uuid if it doesn't exist
        $senderFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages'
             AND COLUMN_NAME = 'sender_uuid' AND REFERENCED_TABLE_NAME = 'chat_participants'"
        );

        if (empty($senderFkExists)) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->foreign('sender_uuid')
                      ->references('uuid')
                      ->on('chat_participants')
                      ->onUpdate('CASCADE')
                      ->onDelete('CASCADE');
            });
        }

        // Step 6: Add foreign key for chat_channel_uuid if it doesn't exist
        $channelFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages'
             AND COLUMN_NAME = 'chat_channel_uuid' AND REFERENCED_TABLE_NAME = 'chat_channels'"
        );

        if (empty($channelFkExists)) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->foreign('chat_channel_uuid')
                      ->references('uuid')
                      ->on('chat_channels')
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
        Schema::dropIfExists('chat_messages');
    }
};
