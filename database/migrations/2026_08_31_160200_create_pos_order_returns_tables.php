<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_order_returns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->decimal('total', 12, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'order_id']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('pos_order_return_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('return_id')->constrained('pos_order_returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['return_id']);
            $table->index(['workspace_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_order_return_items');
        Schema::dropIfExists('pos_order_returns');
    }
};
