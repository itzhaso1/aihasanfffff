<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_cashier_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_cashier_invoice_id')->constrained('pos_cashier_invoices')->cascadeOnDelete();
            $table->foreignId('pos_menu_item_id')->nullable()->constrained('pos_menu_items')->nullOnDelete();
            $table->string('item_name');
            $table->string('item_type', 100)->nullable();
            $table->string('size_label', 100)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->timestamps();

            $table->index(['workspace_id', 'pos_cashier_invoice_id'], 'pos_cashier_invoice_items_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_cashier_invoice_items');
    }
};
