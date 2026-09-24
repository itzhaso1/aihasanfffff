<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_catalogs') && ! Schema::hasColumn('chat_catalogs', 'seller_public_display_name')) {
            Schema::table('chat_catalogs', function (Blueprint $table): void {
                $table->string('seller_public_display_name')->nullable()->after('commerce_seller_id');
                $table->json('seller_support_contact')->nullable()->after('seller_public_display_name');
            });
        }

        if (Schema::hasTable('chat_catalog_categories') && ! $this->indexExists('chat_catalog_categories', 'chat_catalog_categories_discovery_idx')) {
            Schema::table('chat_catalog_categories', function (Blueprint $table): void {
                $table->index(['workspace_id', 'status', 'is_visible'], 'chat_catalog_categories_discovery_idx');
            });
        }

        if (Schema::hasTable('chat_catalog_items') && ! $this->indexExists('chat_catalog_items', 'chat_catalog_items_sku_idx')) {
            Schema::table('chat_catalog_items', function (Blueprint $table): void {
                $table->index(['workspace_id', 'sku'], 'chat_catalog_items_sku_idx');
                $table->index(['workspace_id', 'chat_catalog_category_id'], 'chat_catalog_items_category_idx');
                $table->index(['workspace_id', 'published_at'], 'chat_catalog_items_published_idx');
            });
        }

        if (Schema::hasTable('chat_catalog_item_variants') && ! $this->indexExists('chat_catalog_item_variants', 'chat_catalog_variants_sku_idx')) {
            Schema::table('chat_catalog_item_variants', function (Blueprint $table): void {
                $table->index(['workspace_id', 'sku'], 'chat_catalog_variants_sku_idx');
                $table->index(['workspace_id', 'barcode'], 'chat_catalog_variants_barcode_idx');
            });
        }

        if (Schema::hasTable('chat_catalog_digital_inventory_pools')
            && ! Schema::hasColumn('chat_catalog_digital_inventory_pools', 'reservation_timeout_seconds')) {
            Schema::table('chat_catalog_digital_inventory_pools', function (Blueprint $table): void {
                $table->unsignedInteger('reservation_timeout_seconds')->default(900)->after('low_stock_threshold');
            });
        }

        if (Schema::hasTable('chat_catalog_digital_inventory_units')
            && ! $this->indexExists('chat_catalog_digital_inventory_units', 'chat_catalog_digital_units_availability_idx')) {
            Schema::table('chat_catalog_digital_inventory_units', function (Blueprint $table): void {
                $table->index(
                    ['chat_catalog_digital_inventory_pool_id', 'status', 'expires_at'],
                    'chat_catalog_digital_units_availability_idx',
                );
            });
        }

        if (Schema::hasTable('chat_catalog_item_search_terms')
            && ! $this->foreignKeyExists('chat_catalog_item_search_terms', 'cc_item_terms_item_fk')) {
            Schema::drop('chat_catalog_item_search_terms');
        }

        if (! Schema::hasTable('chat_catalog_item_search_terms')) {
            Schema::create('chat_catalog_item_search_terms', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_catalog_item_id');
                $table->string('term', 255);
                $table->string('term_type', 32);
                $table->string('language', 8)->nullable();
                $table->timestamps();

                $table->foreign('chat_catalog_item_id', 'cc_item_terms_item_fk')
                    ->references('id')->on('chat_catalog_items')->cascadeOnDelete();

                $table->unique(['chat_catalog_item_id', 'term_type', 'term'], 'chat_catalog_item_terms_unique');
                $table->index(['workspace_id', 'term'], 'chat_catalog_item_terms_lookup_idx');
            });
        }

        if (Schema::hasTable('chat_catalog_category_search_terms')
            && ! $this->foreignKeyExists('chat_catalog_category_search_terms', 'cc_cat_terms_cat_fk')) {
            Schema::drop('chat_catalog_category_search_terms');
        }

        if (! Schema::hasTable('chat_catalog_category_search_terms')) {
            Schema::create('chat_catalog_category_search_terms', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_catalog_category_id');
                $table->string('term', 255);
                $table->string('language', 8)->nullable();
                $table->timestamps();

                $table->foreign('chat_catalog_category_id', 'cc_cat_terms_cat_fk')
                    ->references('id')->on('chat_catalog_categories')->cascadeOnDelete();

                $table->unique(['chat_catalog_category_id', 'term'], 'chat_catalog_category_terms_unique');
                $table->index(['workspace_id', 'term'], 'chat_catalog_category_terms_lookup_idx');
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return true;
        }

        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'select constraint_name from information_schema.table_constraints
             where table_schema = ? and table_name = ? and constraint_name = ? and constraint_type = ? limit 1',
            [$database, $table, $constraintName, 'FOREIGN KEY'],
        );

        return $row !== null;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return Schema::hasIndex($table, $indexName);
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_catalog_category_search_terms');
        Schema::dropIfExists('chat_catalog_item_search_terms');

        Schema::table('chat_catalog_digital_inventory_units', function (Blueprint $table): void {
            $table->dropIndex('chat_catalog_digital_units_availability_idx');
        });
        Schema::table('chat_catalog_digital_inventory_pools', function (Blueprint $table): void {
            $table->dropColumn('reservation_timeout_seconds');
        });
        Schema::table('chat_catalog_item_variants', function (Blueprint $table): void {
            $table->dropIndex('chat_catalog_variants_sku_idx');
            $table->dropIndex('chat_catalog_variants_barcode_idx');
        });
        Schema::table('chat_catalog_items', function (Blueprint $table): void {
            $table->dropIndex('chat_catalog_items_sku_idx');
            $table->dropIndex('chat_catalog_items_category_idx');
            $table->dropIndex('chat_catalog_items_published_idx');
        });
        Schema::table('chat_catalog_categories', function (Blueprint $table): void {
            $table->dropIndex('chat_catalog_categories_discovery_idx');
        });
        Schema::table('chat_catalogs', function (Blueprint $table): void {
            $table->dropColumn(['seller_public_display_name', 'seller_support_contact']);
        });
    }
};
