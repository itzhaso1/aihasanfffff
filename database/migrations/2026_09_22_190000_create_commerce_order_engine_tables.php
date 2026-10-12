<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commerce_carts')) {
            Schema::create('commerce_carts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('commerce_seller_id')->nullable()
                    ->constrained('commerce_sellers')->nullOnDelete();
                $table->string('currency', 3);
                $table->string('status', 32)->default('active');
                $table->string('channel', 32)->default('chat');
                $table->json('metadata')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->string('conversion_idempotency_key', 128)->nullable();
                $table->timestamps();

                $table->unique(['workspace_id', 'conversion_idempotency_key'], 'commerce_carts_workspace_conversion_key_unique');
                $table->unique('converted_order_id', 'commerce_carts_converted_order_unique');
                $table->index(['workspace_id', 'status', 'expires_at'], 'commerce_carts_workspace_state_expiry_idx');
                $table->index(['workspace_id', 'customer_id'], 'commerce_carts_workspace_customer_idx');
            });
        }

        if (! Schema::hasTable('commerce_cart_items')) {
            Schema::create('commerce_cart_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('commerce_cart_id')->constrained('commerce_carts')->cascadeOnDelete();
                $table->foreignId('chat_catalog_item_id');
                $table->foreignId('chat_catalog_item_variant_id')->nullable();
                $table->foreignId('commerce_seller_id')->nullable()
                    ->constrained('commerce_sellers')->nullOnDelete();
                $table->unsignedInteger('quantity');
                $table->decimal('unit_price', 12, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->json('price_snapshot')->nullable();
                $table->json('selected_options')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('chat_catalog_item_id', 'cc_cart_items_item_fk')
                    ->references('id')->on('chat_catalog_items')->restrictOnDelete();
                $table->foreign('chat_catalog_item_variant_id', 'cc_cart_items_variant_fk')
                    ->references('id')->on('chat_catalog_item_variants')->nullOnDelete();

                $table->index(['commerce_cart_id', 'id'], 'commerce_cart_items_cart_id_idx');
                $table->index(['workspace_id', 'chat_catalog_item_id'], 'commerce_cart_items_workspace_item_idx');
                $table->index(['workspace_id', 'commerce_seller_id'], 'commerce_cart_items_workspace_seller_idx');
            });
        }

        if (Schema::hasTable('commerce_inventory_reservations')
            && ! $this->foreignKeyExists('commerce_inventory_reservations', 'cc_inv_res_variant_fk')) {
            Schema::drop('commerce_inventory_reservations');
        }

        if (! Schema::hasTable('commerce_inventory_reservations')) {
            Schema::create('commerce_inventory_reservations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
                $table->foreignId('chat_catalog_item_id')->nullable();
                $table->foreignId('chat_catalog_item_variant_id')->nullable();
                $table->foreignId('chat_catalog_digital_inventory_unit_id')->nullable();
                $table->string('reservation_kind', 32);
                $table->string('status', 32)->default('reserved');
                $table->string('reservation_key', 191);
                $table->unsignedInteger('quantity')->default(1);
                $table->timestamp('expires_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->foreign('chat_catalog_item_id', 'cc_inv_res_item_fk')
                    ->references('id')->on('chat_catalog_items')->nullOnDelete();
                $table->foreign('chat_catalog_item_variant_id', 'cc_inv_res_variant_fk')
                    ->references('id')->on('chat_catalog_item_variants')->nullOnDelete();
                $table->foreign('chat_catalog_digital_inventory_unit_id', 'cc_inv_res_unit_fk')
                    ->references('id')->on('chat_catalog_digital_inventory_units')->nullOnDelete();

                $table->unique(
                    ['order_item_id', 'reservation_kind', 'reservation_key'],
                    'commerce_reservations_line_kind_key_unique',
                );
                $table->index(
                    ['workspace_id', 'status', 'expires_at'],
                    'commerce_reservations_workspace_status_expiry_idx',
                );
                $table->index(
                    ['chat_catalog_digital_inventory_unit_id', 'status'],
                    'commerce_reservations_digital_unit_status_idx',
                );
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'commerce_cart_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('commerce_cart_id')->nullable()
                    ->after('commerce_seller_id')
                    ->constrained('commerce_carts')->nullOnDelete();
                $table->string('commerce_status', 32)->nullable()->after('order_type');
                $table->string('commerce_channel', 32)->nullable()->after('commerce_status');

                $table->unique('commerce_cart_id', 'orders_commerce_cart_unique');
                $table->index(
                    ['workspace_id', 'source', 'commerce_status'],
                    'orders_workspace_source_commerce_status_idx',
                );
            });
        }

        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'chat_catalog_item_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->foreignId('chat_catalog_item_id')->nullable()
                    ->after('commerce_seller_id');
                $table->foreignId('chat_catalog_item_variant_id')->nullable()
                    ->after('chat_catalog_item_id');
                $table->string('barcode')->nullable()->after('sku');
                $table->string('seller_display_name')->nullable()->after('barcode');
                $table->string('currency', 3)->nullable()->after('seller_display_name');
                $table->string('classification', 32)->nullable()->after('currency');
                $table->string('fulfillment_classification', 32)->nullable()->after('classification');
                $table->string('tax_presentation', 64)->nullable()->after('fulfillment_classification');
                $table->json('catalog_snapshot')->nullable()->after('tax_presentation');
                $table->json('price_snapshot')->nullable()->after('catalog_snapshot');
                $table->json('tax_snapshot')->nullable()->after('price_snapshot');
                $table->json('selected_options')->nullable()->after('tax_snapshot');
                $table->json('metadata')->nullable()->after('selected_options');

                $table->foreign('chat_catalog_item_id', 'order_items_cc_item_fk')
                    ->references('id')->on('chat_catalog_items')->nullOnDelete();
                $table->foreign('chat_catalog_item_variant_id', 'order_items_cc_variant_fk')
                    ->references('id')->on('chat_catalog_item_variants')->nullOnDelete();

                $table->index(
                    ['workspace_id', 'chat_catalog_item_id'],
                    'order_items_workspace_chat_catalog_item_idx',
                );
                $table->index(
                    ['workspace_id', 'commerce_seller_id'],
                    'order_items_workspace_commerce_seller_v2_idx',
                );
            });
        }

        if (Schema::hasTable('commerce_seller_allocations')
            && ! $this->indexExists('commerce_seller_allocations', 'commerce_allocations_line_seller_unique')) {
            Schema::table('commerce_seller_allocations', function (Blueprint $table): void {
                $table->string('fee_type', 32)->default('none')->after('platform_fee_amount');
                $table->json('fee_metadata')->nullable()->after('fee_type');
                $table->unique(
                    ['order_item_id', 'commerce_seller_id'],
                    'commerce_allocations_line_seller_unique',
                );
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
        Schema::table('commerce_seller_allocations', function (Blueprint $table): void {
            $table->dropUnique('commerce_allocations_line_seller_unique');
            $table->dropColumn(['fee_type', 'fee_metadata']);
        });

        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_workspace_chat_catalog_item_idx');
            $table->dropIndex('order_items_workspace_commerce_seller_v2_idx');
            $table->dropConstrainedForeignId('chat_catalog_item_variant_id');
            $table->dropConstrainedForeignId('chat_catalog_item_id');
            $table->dropColumn([
                'barcode',
                'seller_display_name',
                'currency',
                'classification',
                'fulfillment_classification',
                'tax_presentation',
                'catalog_snapshot',
                'price_snapshot',
                'tax_snapshot',
                'selected_options',
                'metadata',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_workspace_source_commerce_status_idx');
            $table->dropUnique('orders_commerce_cart_unique');
            $table->dropConstrainedForeignId('commerce_cart_id');
            $table->dropColumn(['commerce_status', 'commerce_channel']);
        });

        Schema::dropIfExists('commerce_inventory_reservations');
        Schema::dropIfExists('commerce_cart_items');
        Schema::dropIfExists('commerce_carts');
    }
};
