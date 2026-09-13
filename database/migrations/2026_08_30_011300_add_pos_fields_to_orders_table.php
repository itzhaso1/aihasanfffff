<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('source', 32)->default('manual')->after('order_number');
            $table->string('pos_status', 32)->default('new')->after('status');
            $table->foreignId('dining_table_id')->nullable()->after('customer_id')->constrained('dining_tables')->nullOnDelete();
            $table->foreignId('table_session_id')->nullable()->after('dining_table_id')->constrained('table_sessions')->nullOnDelete();
            $table->foreignId('finance_invoice_id')->nullable()->after('table_session_id')->constrained('finance_invoices')->nullOnDelete();
            $table->json('metadata')->nullable()->after('notes');

            $table->index(['workspace_id', 'source'], 'orders_source_idx');
            $table->index(['workspace_id', 'pos_status'], 'orders_pos_status_idx');
            $table->index(['workspace_id', 'dining_table_id'], 'orders_table_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_source_idx');
            $table->dropIndex('orders_pos_status_idx');
            $table->dropIndex('orders_table_idx');

            $table->dropConstrainedForeignId('finance_invoice_id');
            $table->dropConstrainedForeignId('table_session_id');
            $table->dropConstrainedForeignId('dining_table_id');

            $table->dropColumn(['source', 'pos_status', 'metadata']);
        });
    }
};
