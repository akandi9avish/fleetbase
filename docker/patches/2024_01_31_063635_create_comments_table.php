<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent with unique index on uuid for self-referential FK
     *
     * Original issue: Migration creates comments.uuid with regular index, then tries
     * to add self-referential foreign key parent_comment_uuid -> comments.uuid.
     * Fails because comments.uuid isn't UNIQUE. Not idempotent - table gets created
     * but foreign key doesn't, causing "table already exists" on retries.
     *
     * @return void
     */
    public function up()
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('comments')) {
            Schema::create('comments', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid')->nullable();  // Don't add index yet
                $table->string('public_id')->nullable()->unique();
                $table->uuid('company_uuid');
                $table->uuid('author_uuid');
                $table->uuid('subject_uuid');
                $table->string('subject_type')->nullable();
                $table->mediumText('content');
                $table->json('tags')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // Step 2: Ensure uuid has unique index (required for self-referential FK)
        if (Schema::hasTable('comments') && Schema::hasColumn('comments', 'uuid')) {
            $uniqueIndexName = 'comments_uuid_unique';
            $indexes = DB::select("SHOW INDEX FROM comments WHERE Key_name = ? AND Non_unique = 0", [$uniqueIndexName]);

            if (empty($indexes)) {
                // Drop any existing non-unique indexes on uuid
                $regularIndexes = DB::select("SHOW INDEX FROM comments WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");

                Schema::table('comments', function (Blueprint $table) use ($regularIndexes) {
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
                    $table->unique('uuid', 'comments_uuid_unique');
                });
            }
        }

        // Step 3: Add foreign keys for company and author if they don't exist
        $companyFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments'
             AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
        );

        if (empty($companyFkExists)) {
            Schema::table('comments', function (Blueprint $table) {
                $table->foreign('company_uuid')
                      ->references('uuid')
                      ->on('companies')
                      ->onUpdate('CASCADE')
                      ->onDelete('CASCADE');
            });
        }

        $authorFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments'
             AND COLUMN_NAME = 'author_uuid' AND REFERENCED_TABLE_NAME = 'users'"
        );

        if (empty($authorFkExists)) {
            Schema::table('comments', function (Blueprint $table) {
                $table->foreign('author_uuid')
                      ->references('uuid')
                      ->on('users')
                      ->onUpdate('CASCADE')
                      ->onDelete('CASCADE');
            });
        }

        // Step 4: Add parent_comment_uuid column if it doesn't exist
        if (Schema::hasTable('comments') && !Schema::hasColumn('comments', 'parent_comment_uuid')) {
            Schema::table('comments', function (Blueprint $table) {
                $table->uuid('parent_comment_uuid')->nullable()->after('author_uuid');
            });
        }

        // Step 5: Add self-referential foreign key if it doesn't exist
        $parentFkExists = DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'comments'
             AND COLUMN_NAME = 'parent_comment_uuid' AND REFERENCED_TABLE_NAME = 'comments'"
        );

        if (empty($parentFkExists)) {
            Schema::table('comments', function (Blueprint $table) {
                $table->foreign('parent_comment_uuid')
                      ->references('uuid')
                      ->on('comments')
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
    public function down()
    {
        Schema::dropIfExists('comments');
    }
};
