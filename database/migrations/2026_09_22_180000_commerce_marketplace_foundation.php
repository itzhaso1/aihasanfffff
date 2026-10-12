<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_workspace_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('operating_mode', 32)->default('independent_store');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('commerce_sellers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('display_name')->nullable();
            $table->string('seller_type', 32)->default('workspace_merchant');
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'slug'], 'commerce_sellers_workspace_slug_unique');
            // List/filter sellers within a tenant (admin UI, future marketplace management).
            $table->index(['workspace_id', 'status'], 'commerce_sellers_workspace_status_idx');
        });

        Schema::create('commerce_seller_payment_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('commerce_seller_id')->constrained('commerce_sellers')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('provider_reference')->nullable();
            $table->string('status', 32)->default('not_configured');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['commerce_seller_id', 'provider'], 'commerce_seller_accounts_seller_provider_idx');
            // Future webhook/reconciliation lookup by external reference (scoped by provider).
            $table->index(['provider', 'provider_reference'], 'commerce_seller_accounts_provider_ref_idx');
            $table->unique(
                ['workspace_id', 'commerce_seller_id', 'provider'],
                'commerce_seller_accounts_workspace_seller_provider_unique',
            );
        });

        Schema::create('commerce_seller_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('commerce_seller_id')->constrained('commerce_sellers')->restrictOnDelete();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('platform_fee_amount', 12, 2)->default(0);
            $table->decimal('seller_net_amount', 12, 2);
            $table->string('currency', 3);
            $table->string('settlement_status', 32)->default('pending');
            $table->string('provider_settlement_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'commerce_seller_id'], 'commerce_allocations_order_seller_idx');
            $table->index(['payment_id', 'settlement_status'], 'commerce_allocations_payment_settlement_idx');
            $table->index(['workspace_id', 'settlement_status'], 'commerce_allocations_workspace_settlement_idx');
        });

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'commerce_seller_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('commerce_seller_id')
                    ->nullable()
                    ->after('customer_id')
                    ->constrained('commerce_sellers')
                    ->nullOnDelete();
                $table->index(['workspace_id', 'commerce_seller_id'], 'orders_workspace_commerce_seller_idx');
            });
        }

        if (Schema::hasTable('order_items') && ! Schema::hasColumn('order_items', 'commerce_seller_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->foreignId('commerce_seller_id')
                    ->nullable()
                    ->after('order_id')
                    ->constrained('commerce_sellers')
                    ->nullOnDelete();
                $table->index(['order_id', 'commerce_seller_id'], 'order_items_order_commerce_seller_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'commerce_seller_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropForeign(['commerce_seller_id']);
                $table->dropColumn('commerce_seller_id');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'commerce_seller_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropForeign(['commerce_seller_id']);
                $table->dropColumn('commerce_seller_id');
            });
        }

        Schema::dropIfExists('commerce_seller_allocations');
        Schema::dropIfExists('commerce_seller_payment_accounts');
        Schema::dropIfExists('commerce_sellers');
        Schema::dropIfExists('commerce_workspace_profiles');
    }
};
