<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_engagement_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->foreignId('chat_catalog_item_id')
                ->constrained('chat_catalog_items')
                ->cascadeOnDelete();
            $table->decimal('minimum_selling_price', 12, 2)->nullable();
            $table->decimal('maximum_selling_price', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(
                ['worker_engagement_id', 'chat_catalog_item_id'],
                'worker_engagement_products_engagement_item_unique',
            );
            $table->index(
                ['workspace_id', 'worker_engagement_id'],
                'worker_engagement_products_workspace_engagement_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_engagement_products');
    }
};
