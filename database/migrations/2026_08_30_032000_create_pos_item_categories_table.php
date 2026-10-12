<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_item_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'is_active', 'sort_order'], 'pos_item_categories_visibility_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_item_categories');
    }
};
