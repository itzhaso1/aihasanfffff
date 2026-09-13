<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_menu_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('item_type', 100)->default('عام');
            $table->decimal('price', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'is_active', 'sort_order'], 'pos_menu_items_visibility_idx');
            $table->index(['workspace_id', 'item_type'], 'pos_menu_items_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_menu_items');
    }
};
