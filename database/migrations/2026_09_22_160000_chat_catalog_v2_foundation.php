<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_catalogs')) {
            Schema::create('chat_catalogs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
                // Commerce Seller is introduced by a later additive migration.
                // Application services validate this optional relationship.
                $table->unsignedBigInteger('commerce_seller_id')->nullable();
                $table->boolean('seller_status_visibility')->default(true);
                $table->string('name');
                $table->string('slug');
                $table->string('internal_name')->nullable();
                $table->string('public_name')->nullable();
                $table->text('description')->nullable();
                $table->text('short_description')->nullable();
                $table->string('status', 32)->default('draft');
                $table->timestamp('published_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->string('default_language', 8)->default('ar');
                $table->json('supported_languages')->nullable();
                $table->string('default_locale', 16)->default('ar_SA');
                $table->string('timezone', 64)->default('Asia/Riyadh');
                $table->string('fallback_language', 8)->default('en');
                $table->boolean('rtl_mode')->default(true);
                $table->string('logo_path')->nullable();
                $table->string('favicon_path')->nullable();
                $table->string('banner_path')->nullable();
                $table->string('brand_image_path')->nullable();
                $table->text('brand_description')->nullable();
                $table->string('legal_business_name')->nullable();
                $table->string('public_title')->nullable();
                $table->text('public_description')->nullable();
                $table->json('public_support_contact')->nullable();
                $table->string('public_website')->nullable();
                $table->json('social_links')->nullable();
                $table->string('default_currency', 3)->default('SAR');
                $table->string('sort_policy', 32)->default('manual');
                $table->string('availability_mode', 32)->default('always_open');
                $table->string('unavailable_item_behavior', 32)->default('show_unavailable');
                $table->json('display_settings')->nullable();
                $table->json('discovery_settings')->nullable();
                $table->json('channel_visibility')->nullable();
                $table->json('customer_service_policy')->nullable();
                $table->json('availability_schedule')->nullable();
                $table->json('customer_templates')->nullable();
                $table->json('seo')->nullable();
                $table->json('search_weights')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'status'], 'chat_catalogs_ws_status_idx');
                $table->index(['workspace_id', 'commerce_seller_id'], 'chat_catalogs_ws_seller_idx');
            });
        }

        if (! Schema::hasTable('chat_catalog_communication_profiles')) {
            Schema::create('chat_catalog_communication_profiles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('chat_catalog_id')->unique()->constrained('chat_catalogs')->cascadeOnDelete();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('primary_language', 8)->default('ar');
                $table->json('supported_languages')->nullable();
                $table->string('arabic_dialect', 32)->default('msa');
                $table->string('formality', 32)->default('neutral');
                $table->string('communication_style', 32)->default('balanced');
                $table->string('greeting_behavior', 32)->default('first_contact');
                $table->string('name_usage', 32)->default('first_name');
                $table->string('language_fallback', 8)->default('en');
                $table->string('date_format', 32)->default('Y-m-d');
                $table->string('time_format', 16)->default('H:i');
                $table->string('number_format', 32)->default('western');
                $table->string('currency_format', 32)->default('symbol_after');
                $table->string('units', 16)->default('metric');
                $table->string('response_length', 16)->default('balanced');
                $table->string('punctuation_style', 16)->default('standard');
                $table->string('emoji_policy', 16)->default('limited');
                $table->timestamps();

                $table->index('workspace_id');
            });
        }

        if (Schema::hasTable('chat_catalog_categories') && ! Schema::hasColumn('chat_catalog_categories', 'parent_id')) {
            Schema::table('chat_catalog_categories', function (Blueprint $table): void {
                $table->foreignId('parent_id')->nullable()->after('workspace_id')
                    ->constrained('chat_catalog_categories')->nullOnDelete();
                $table->string('slug')->nullable()->after('name');
                $table->text('description')->nullable()->after('slug');
                $table->string('image_path')->nullable()->after('description');
                $table->string('status', 32)->default('published')->after('image_path');
                $table->boolean('is_visible')->default(true)->after('status');
                $table->boolean('is_featured')->default(false)->after('is_visible');
                $table->json('seo')->nullable()->after('is_featured');
                $table->json('localization')->nullable()->after('seo');
                $table->json('aliases')->nullable()->after('localization');

                $table->index(['workspace_id', 'parent_id'], 'chat_catalog_categories_parent_idx');
                $table->unique(['workspace_id', 'slug'], 'chat_catalog_categories_ws_slug_unique');
            });
        }

        if (Schema::hasTable('chat_catalog_items') && ! Schema::hasColumn('chat_catalog_items', 'commerce_seller_id')) {
            Schema::table('chat_catalog_items', function (Blueprint $table): void {
                // See the matching catalog-root relationship above.
                $table->unsignedBigInteger('commerce_seller_id')->nullable()->after('workspace_id');
                $table->string('internal_name')->nullable()->after('name');
                $table->string('short_description', 512)->nullable()->after('description');
                $table->string('barcode', 64)->nullable()->after('sku');
                $table->string('brand')->nullable()->after('barcode');
                $table->string('manufacturer')->nullable()->after('brand');
                $table->string('model')->nullable()->after('manufacturer');
                $table->string('external_reference')->nullable()->after('model');
                $table->text('customer_description')->nullable()->after('external_reference');
                $table->text('usage_instructions')->nullable()->after('customer_description');
                $table->json('specifications')->nullable()->after('usage_instructions');
                $table->json('highlights')->nullable()->after('specifications');
                $table->text('warnings')->nullable()->after('highlights');
                $table->text('terms')->nullable()->after('warnings');
                $table->string('classification', 32)->nullable()->after('tangibility');
                $table->string('digital_subtype', 32)->nullable()->after('classification');
                $table->string('service_type', 32)->nullable()->after('digital_subtype');
                $table->boolean('is_featured')->default(false)->after('is_active');
                $table->boolean('is_bestseller')->default(false)->after('is_featured');
                $table->boolean('is_new')->default(false)->after('is_bestseller');
                $table->boolean('is_recommended')->default(false)->after('is_new');
                $table->boolean('is_searchable')->default(true)->after('is_recommended');
                $table->boolean('is_visible')->default(true)->after('is_searchable');
                $table->string('publication_status', 32)->nullable()->after('is_visible');
                $table->timestamp('published_at')->nullable()->after('publication_status');
                $table->timestamp('archived_at')->nullable()->after('published_at');
                $table->string('inventory_policy', 32)->default('finite_stock')->after('stock');
                $table->decimal('compare_at_price', 12, 2)->nullable()->after('price');
                $table->decimal('sale_price', 12, 2)->nullable()->after('compare_at_price');
                $table->string('tax_display_behavior', 32)->default('tax_exclusive')->after('currency');
                $table->timestamp('price_valid_from')->nullable()->after('tax_display_behavior');
                $table->timestamp('price_valid_until')->nullable()->after('price_valid_from');
                $table->json('policies')->nullable()->after('attributes');
                $table->json('ai_safe_profile')->nullable()->after('policies');
                $table->json('tags')->nullable()->after('ai_safe_profile');
                $table->json('aliases')->nullable()->after('tags');
                $table->json('search_phrases')->nullable()->after('aliases');

                $table->index(['workspace_id', 'barcode'], 'chat_catalog_items_barcode_idx');
                $table->index(['workspace_id', 'publication_status'], 'chat_catalog_items_pub_idx');
                $table->index(['workspace_id', 'is_searchable', 'is_visible'], 'chat_catalog_items_disc_idx');
                $table->index(['workspace_id', 'commerce_seller_id'], 'chat_catalog_items_seller_idx');
            });
        }

        if (Schema::hasTable('chat_catalog_item_variants') && ! Schema::hasColumn('chat_catalog_item_variants', 'barcode')) {
            Schema::table('chat_catalog_item_variants', function (Blueprint $table): void {
                $table->string('barcode', 64)->nullable()->after('sku');
                $table->decimal('compare_at_price', 12, 2)->nullable()->after('price');
                $table->decimal('sale_price', 12, 2)->nullable()->after('compare_at_price');
                $table->string('currency', 3)->nullable()->after('sale_price');
                $table->string('status', 32)->default('published')->after('currency');
                $table->boolean('is_visible')->default(true)->after('status');
                $table->unsignedInteger('sort_order')->default(0)->after('is_visible');
                $table->string('inventory_policy', 32)->default('finite_stock')->after('stock');
            });
        }

        if (! Schema::hasTable('chat_catalog_item_digital_profiles')) {
            Schema::create('chat_catalog_item_digital_profiles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_catalog_item_id');
                $table->foreignId('chat_catalog_item_variant_id')->nullable();
                $table->string('unit_type', 32);
                $table->timestamps();

                $table->foreign('chat_catalog_item_id', 'cc_dig_prof_item_fk')
                    ->references('id')->on('chat_catalog_items')->cascadeOnDelete();
                $table->foreign('chat_catalog_item_variant_id', 'cc_dig_prof_variant_fk')
                    ->references('id')->on('chat_catalog_item_variants')->cascadeOnDelete();

                $table->unique(['chat_catalog_item_id', 'chat_catalog_item_variant_id'], 'chat_catalog_digital_profile_unique');
            });
        }

        if (Schema::hasTable('chat_catalog_digital_inventory_pools')
            && ! $this->foreignKeyExists('chat_catalog_digital_inventory_pools', 'cc_dig_pools_item_fk')) {
            Schema::drop('chat_catalog_digital_inventory_pools');
        }

        if (! Schema::hasTable('chat_catalog_digital_inventory_pools')) {
            Schema::create('chat_catalog_digital_inventory_pools', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_catalog_item_id');
                $table->foreignId('chat_catalog_item_variant_id')->nullable();
                $table->string('name');
                $table->unsignedInteger('low_stock_threshold')->default(5);
                $table->timestamps();

                $table->foreign('chat_catalog_item_id', 'cc_dig_pools_item_fk')
                    ->references('id')->on('chat_catalog_items')->cascadeOnDelete();
                $table->foreign('chat_catalog_item_variant_id', 'cc_dig_pools_variant_fk')
                    ->references('id')->on('chat_catalog_item_variants')->nullOnDelete();

                $table->index(['workspace_id', 'chat_catalog_item_id'], 'chat_catalog_digital_pools_item_idx');
            });
        }

        if (! Schema::hasTable('chat_catalog_digital_inventory_units')) {
            Schema::create('chat_catalog_digital_inventory_units', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_catalog_digital_inventory_pool_id');
                $table->string('unit_type', 32);
                $table->string('status', 32)->default('available');
                $table->text('encrypted_secret')->nullable();
                $table->string('secret_fingerprint', 64)->nullable();
                $table->string('reservation_key')->nullable();
                $table->timestamp('reserved_until')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('chat_catalog_digital_inventory_pool_id', 'cc_dig_units_pool_fk')
                    ->references('id')->on('chat_catalog_digital_inventory_pools')->cascadeOnDelete();

                $table->index(['workspace_id', 'status'], 'chat_catalog_digital_units_status_idx');
                $table->index(['chat_catalog_digital_inventory_pool_id', 'status'], 'chat_catalog_digital_units_pool_idx');
                $table->index(['workspace_id', 'secret_fingerprint'], 'chat_catalog_digital_units_fp_idx');
                $table->unique(['workspace_id', 'secret_fingerprint'], 'chat_catalog_digital_units_fp_unique');
                $table->unique(['workspace_id', 'reservation_key'], 'chat_catalog_digital_units_reservation_unique');
            });
        }

        if (! Schema::hasTable('chat_catalog_item_media')) {
            Schema::create('chat_catalog_item_media', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_catalog_item_id')->constrained('chat_catalog_items')->cascadeOnDelete();
                $table->string('kind', 32)->default('gallery');
                $table->string('disk', 32)->default('public');
                $table->string('path');
                $table->string('alt')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('focal_metadata')->nullable();
                $table->boolean('is_visible')->default(true);
                $table->timestamps();

                $table->index(['chat_catalog_item_id', 'sort_order'], 'chat_catalog_item_media_sort_idx');
            });
        }

        if (! Schema::hasTable('chat_catalog_item_attributes')) {
            Schema::create('chat_catalog_item_attributes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_catalog_item_id');
                $table->foreignId('chat_catalog_item_variant_id')->nullable();
                $table->string('attribute_key');
                $table->text('attribute_value')->nullable();
                $table->string('value_type', 32)->default('string');
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->foreign('chat_catalog_item_id', 'cc_item_attrs_item_fk')
                    ->references('id')->on('chat_catalog_items')->cascadeOnDelete();
                $table->foreign('chat_catalog_item_variant_id', 'cc_item_attrs_variant_fk')
                    ->references('id')->on('chat_catalog_item_variants')->cascadeOnDelete();

                $table->index(['chat_catalog_item_id', 'attribute_key'], 'chat_catalog_item_attrs_key_idx');
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

    public function down(): void
    {
        Schema::dropIfExists('chat_catalog_item_attributes');
        Schema::dropIfExists('chat_catalog_item_media');
        Schema::dropIfExists('chat_catalog_digital_inventory_units');
        Schema::dropIfExists('chat_catalog_digital_inventory_pools');
        Schema::dropIfExists('chat_catalog_item_digital_profiles');
        Schema::table('chat_catalog_item_variants', function (Blueprint $table): void {
            $table->dropColumn([
                'barcode', 'compare_at_price', 'sale_price', 'currency', 'status',
                'is_visible', 'sort_order', 'inventory_policy',
            ]);
        });
        Schema::table('chat_catalog_items', function (Blueprint $table): void {
            $table->dropIndex('chat_catalog_items_seller_idx');
            $table->dropIndex('chat_catalog_items_barcode_idx');
            $table->dropIndex('chat_catalog_items_pub_idx');
            $table->dropIndex('chat_catalog_items_disc_idx');
            $table->dropColumn([
                'commerce_seller_id',
                'internal_name', 'short_description', 'barcode', 'brand', 'manufacturer', 'model',
                'external_reference', 'customer_description', 'usage_instructions', 'specifications',
                'highlights', 'warnings', 'terms', 'classification', 'digital_subtype', 'service_type',
                'is_featured', 'is_bestseller', 'is_new', 'is_recommended', 'is_searchable', 'is_visible',
                'publication_status', 'published_at', 'archived_at', 'inventory_policy',
                'compare_at_price', 'sale_price', 'tax_display_behavior', 'price_valid_from', 'price_valid_until',
                'policies', 'ai_safe_profile', 'tags', 'aliases', 'search_phrases',
            ]);
        });
        Schema::table('chat_catalog_categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropUnique('chat_catalog_categories_ws_slug_unique');
            $table->dropColumn([
                'slug', 'description', 'image_path', 'status', 'is_visible', 'is_featured', 'seo', 'localization', 'aliases',
            ]);
        });
        Schema::dropIfExists('chat_catalog_communication_profiles');
        Schema::dropIfExists('chat_catalogs');
    }
};
