<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_catalog_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'is_active', 'sort_order'], 'chat_catalog_categories_visibility_idx');
        });

        Schema::create('chat_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_catalog_category_id')
                ->nullable()
                ->constrained('chat_catalog_categories')
                ->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('item_kind', 32)->default('product');
            $table->string('tangibility', 32)->default('tangible');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->unsignedInteger('stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('image_path')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'is_active', 'sort_order'], 'chat_catalog_items_visibility_idx');
            $table->index(['workspace_id', 'name'], 'chat_catalog_items_name_idx');
            $table->unique(['workspace_id', 'slug'], 'chat_catalog_items_workspace_slug_unique');
        });

        Schema::create('chat_catalog_item_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_catalog_item_id')->constrained('chat_catalog_items')->cascadeOnDelete();
            $table->string('name');
            $table->string('sku', 64)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->unsignedInteger('stock')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'chat_catalog_item_id'], 'chat_catalog_variants_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_catalog_item_variants');
        Schema::dropIfExists('chat_catalog_items');
        Schema::dropIfExists('chat_catalog_categories');
    }
};
