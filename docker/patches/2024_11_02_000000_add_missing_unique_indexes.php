<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY FIX: Add missing unique indexes to uuid columns that are referenced by foreign keys
     *
     * This migration runs AFTER the original table creation migrations and adds unique indexes
     * to any uuid columns that are referenced by foreign keys but don't have unique indexes.
     *
     * Background: The original migrations created tables with regular (non-unique) indexes on uuid columns.
     * When later migrations tried to add foreign keys referencing these uuid columns, they failed with:
     * "SQLSTATE[HY000]: General error: 6125 Failed to add the foreign key constraint. Missing unique key"
     *
     * This migration fixes the issue by:
     * 1. Checking if the referenced table and column exist
     * 2. Checking if a unique index already exists
     * 3. Dropping any non-unique indexes on the column
     * 4. Adding a unique index
     *
     * @return void
     */
    public function up(): void
    {
        // Fix custom_fields.uuid - referenced by custom_field_values
        if (Schema::hasTable('custom_fields') && Schema::hasColumn('custom_fields', 'uuid')) {
            $this->ensureUniqueIndex('custom_fields', 'uuid', 'custom_fields_uuid_unique');
        }

        // Fix chat_channels.uuid - referenced by chat_participants, chat_messages, chat_attachments
        if (Schema::hasTable('chat_channels') && Schema::hasColumn('chat_channels', 'uuid')) {
            $this->ensureUniqueIndex('chat_channels', 'uuid', 'chat_channels_uuid_unique');
        }

        // Fix chat_participants.uuid - referenced by chat_messages, chat_attachments, chat_receipts
        if (Schema::hasTable('chat_participants') && Schema::hasColumn('chat_participants', 'uuid')) {
            $this->ensureUniqueIndex('chat_participants', 'uuid', 'chat_participants_uuid_unique');
        }

        // Fix chat_messages.uuid - referenced by chat_attachments, chat_receipts
        if (Schema::hasTable('chat_messages') && Schema::hasColumn('chat_messages', 'uuid')) {
            $this->ensureUniqueIndex('chat_messages', 'uuid', 'chat_messages_uuid_unique');
        }

        // Fix comments.uuid - self-referential FK (parent_comment_uuid)
        if (Schema::hasTable('comments') && Schema::hasColumn('comments', 'uuid')) {
            $this->ensureUniqueIndex('comments', 'uuid', 'comments_uuid_unique');
        }

        // Fix dashboards.uuid - referenced by dashboard_widgets
        if (Schema::hasTable('dashboards') && Schema::hasColumn('dashboards', 'uuid')) {
            $this->ensureUniqueIndex('dashboards', 'uuid', 'dashboards_uuid_unique');
        }
    }

    /**
     * Helper method to ensure a column has a unique index
     *
     * @param string $table Table name
     * @param string $column Column name
     * @param string $indexName Desired unique index name
     * @return void
     */
    private function ensureUniqueIndex(string $table, string $column, string $indexName): void
    {
        // Check if unique index already exists
        $uniqueIndexes = DB::select(
            "SHOW INDEX FROM {$table} WHERE Column_name = ? AND Key_name = ? AND Non_unique = 0",
            [$column, $indexName]
        );

        if (!empty($uniqueIndexes)) {
            // Unique index already exists, nothing to do
            return;
        }

        // Get all indexes on this column
        $allIndexes = DB::select(
            "SHOW INDEX FROM {$table} WHERE Column_name = ? AND Key_name != 'PRIMARY'",
            [$column]
        );

        // Drop any non-unique indexes on this column
        foreach ($allIndexes as $index) {
            if ($index->Non_unique == 1) {
                try {
                    DB::statement("DROP INDEX {$index->Key_name} ON {$table}");
                    echo "Dropped non-unique index {$index->Key_name} from {$table}.{$column}\n";
                } catch (\Exception $e) {
                    // Index might already be dropped, continue
                    echo "Warning: Could not drop index {$index->Key_name}: {$e->getMessage()}\n";
                }
            }
        }

        // Add the unique index
        try {
            DB::statement("ALTER TABLE {$table} ADD UNIQUE INDEX {$indexName} ({$column})");
            echo "✅ Added unique index {$indexName} to {$table}.{$column}\n";
        } catch (\Exception $e) {
            echo "⚠️  Warning: Could not add unique index {$indexName} to {$table}.{$column}: {$e->getMessage()}\n";
            // Don't throw - this migration should be idempotent and not fail deployment
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        // Drop the unique indexes we added (optional - usually not needed)
        $indexes = [
            'custom_fields' => 'custom_fields_uuid_unique',
            'chat_channels' => 'chat_channels_uuid_unique',
            'chat_participants' => 'chat_participants_uuid_unique',
            'chat_messages' => 'chat_messages_uuid_unique',
            'comments' => 'comments_uuid_unique',
            'dashboards' => 'dashboards_uuid_unique',
        ];

        foreach ($indexes as $table => $indexName) {
            if (Schema::hasTable($table)) {
                try {
                    DB::statement("DROP INDEX {$indexName} ON {$table}");
                } catch (\Exception $e) {
                    // Index might not exist, ignore
                }
            }
        }
    }
};
