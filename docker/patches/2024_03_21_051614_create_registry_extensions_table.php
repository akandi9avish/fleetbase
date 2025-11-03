<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * RAILWAY PATCH: Made idempotent with prerequisite unique index checks
     *
     * Original issue: Migration tries to add FK from registry_extensions to multiple tables
     * including registry_users.uuid which doesn't have a unique index. Fails with error 6125.
     * Also not idempotent - table gets created but FKs don't when added later.
     *
     * @return void
     */
    public function up(): void
    {
        // Step 1: Create table if it doesn't exist
        if (!Schema::hasTable('registry_extensions')) {
            Schema::create('registry_extensions', function (Blueprint $table) {
                $table->increments('id');
                $table->uuid('uuid');  // Don't add index yet
                $table->uuid('company_uuid');
                $table->uuid('created_by_uuid')->nullable();
                $table->uuid('registry_user_uuid')->nullable();
                $table->uuid('current_bundle_uuid')->nullable();
                $table->uuid('next_bundle_uuid')->nullable();
                $table->uuid('icon_uuid')->nullable();
                $table->uuid('category_uuid')->nullable();
                $table->string('public_id')->nullable();
                $table->string('stripe_product_id')->nullable();
                $table->string('name');
                $table->string('subtitle')->nullable();
                $table->boolean('payment_required')->default(0);
                $table->integer('price')->nullable();
                $table->integer('sale_price')->nullable();
                $table->boolean('on_sale')->default(0);
                $table->boolean('subscription_required')->default(0);
                $table->string('subscription_billing_period')->nullable();
                $table->string('subscription_model')->nullable();
                $table->integer('subscription_amount')->nullable();
                $table->json('subscription_tiers')->nullable();
                $table->string('currency')->default('USD');
                $table->string('slug');
                $table->string('version')->nullable();
                $table->string('fa_icon')->nullable();
                $table->mediumText('description')->nullable();
                $table->mediumText('promotional_text')->nullable();
                $table->string('website_url')->nullable();
                $table->string('repo_url')->nullable();
                $table->string('support_url')->nullable();
                $table->string('privacy_policy_url')->nullable();
                $table->string('tos_url')->nullable();
                $table->string('copyright')->nullable();
                $table->string('primary_language')->nullable();
                $table->json('tags')->nullable();
                $table->json('languages')->nullable();
                $table->json('meta')->nullable();
                $table->boolean('core_extension')->default(0);
                $table->string('status')->default('pending');
                $table->timestamp('published_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                // Don't add FKs yet - add them in steps below after ensuring unique indexes exist
            });
        }

        // Step 2: Ensure registry_users.uuid has unique index BEFORE adding FK
        if (Schema::hasTable('registry_users') && Schema::hasColumn('registry_users', 'uuid')) {
            $uniqueIndexName = 'registry_users_uuid_unique';
            $indexes = DB::select("SHOW INDEX FROM registry_users WHERE Key_name = ? AND Non_unique = 0", [$uniqueIndexName]);

            if (empty($indexes)) {
                // Drop any existing non-unique indexes on uuid
                $regularIndexes = DB::select("SHOW INDEX FROM registry_users WHERE Column_name = 'uuid' AND Key_name != 'PRIMARY'");

                foreach ($regularIndexes as $index) {
                    if ($index->Non_unique == 1) {
                        try {
                            DB::statement("DROP INDEX {$index->Key_name} ON registry_users");
                        } catch (\Exception $e) {
                            // Already dropped, continue
                        }
                    }
                }

                // Add unique index to registry_users.uuid
                try {
                    DB::statement("ALTER TABLE registry_users ADD UNIQUE INDEX {$uniqueIndexName} (uuid)");
                } catch (\Exception $e) {
                    // Index might already exist
                }
            }
        }

        // Step 3: Add indexes on registry_extensions columns if missing
        if (Schema::hasTable('registry_extensions')) {
            Schema::table('registry_extensions', function (Blueprint $table) {
                // Check for uuid index
                $uuidIndexes = DB::select("SHOW INDEX FROM registry_extensions WHERE Column_name = 'uuid'");
                if (empty($uuidIndexes)) {
                    try {
                        $table->index('uuid');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }

                // Check for public_id index
                $publicIdIndexes = DB::select("SHOW INDEX FROM registry_extensions WHERE Column_name = 'public_id'");
                if (empty($publicIdIndexes)) {
                    try {
                        $table->index('public_id');
                    } catch (\Exception $e) {
                        // Index might already exist
                    }
                }
            });
        }

        // Step 4: Add foreign keys if they don't exist
        if (Schema::hasTable('registry_extensions')) {
            // Check and add company_uuid FK
            $companyFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extensions'
                 AND COLUMN_NAME = 'company_uuid' AND REFERENCED_TABLE_NAME = 'companies'"
            );

            if (empty($companyFkExists)) {
                Schema::table('registry_extensions', function (Blueprint $table) {
                    $table->foreign('company_uuid')->references('uuid')->on('companies')->onDelete('cascade');
                });
            }

            // Check and add created_by_uuid FK
            $createdByFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extensions'
                 AND COLUMN_NAME = 'created_by_uuid' AND REFERENCED_TABLE_NAME = 'users'"
            );

            if (empty($createdByFkExists)) {
                Schema::table('registry_extensions', function (Blueprint $table) {
                    $table->foreign('created_by_uuid')->references('uuid')->on('users')->onDelete('cascade');
                });
            }

            // Check and add registry_user_uuid FK
            $registryUserFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extensions'
                 AND COLUMN_NAME = 'registry_user_uuid' AND REFERENCED_TABLE_NAME = 'registry_users'"
            );

            if (empty($registryUserFkExists)) {
                Schema::table('registry_extensions', function (Blueprint $table) {
                    $table->foreign('registry_user_uuid')->references('uuid')->on('registry_users')->onDelete('cascade');
                });
            }

            // Check and add icon_uuid FK
            $iconFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extensions'
                 AND COLUMN_NAME = 'icon_uuid' AND REFERENCED_TABLE_NAME = 'files'"
            );

            if (empty($iconFkExists)) {
                Schema::table('registry_extensions', function (Blueprint $table) {
                    $table->foreign('icon_uuid')->references('uuid')->on('files')->onDelete('cascade');
                });
            }

            // Check and add category_uuid FK
            $categoryFkExists = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registry_extensions'
                 AND COLUMN_NAME = 'category_uuid' AND REFERENCED_TABLE_NAME = 'categories'"
            );

            if (empty($categoryFkExists)) {
                Schema::table('registry_extensions', function (Blueprint $table) {
                    $table->foreign('category_uuid')->references('uuid')->on('categories')->onDelete('cascade');
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('registry_extensions');
    }
};
