<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('pos_cashier_invoice_id')
                ->nullable()
                ->after('finance_invoice_id')
                ->constrained('pos_cashier_invoices')
                ->nullOnDelete();
            $table->index(['workspace_id', 'pos_cashier_invoice_id'], 'orders_pos_cashier_invoice_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_pos_cashier_invoice_idx');
            $table->dropConstrainedForeignId('pos_cashier_invoice_id');
        });
    }
};
