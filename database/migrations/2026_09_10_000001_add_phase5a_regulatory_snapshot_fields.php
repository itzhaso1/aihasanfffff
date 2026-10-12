<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_invoices') && ! Schema::hasColumn('finance_invoices', 'supply_date')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->date('supply_date')->nullable()->after('due_date');
            });
        }

        if (Schema::hasTable('finance_invoice_items') && ! Schema::hasColumn('finance_invoice_items', 'unit_code')) {
            Schema::table('finance_invoice_items', function (Blueprint $table): void {
                $table->string('unit_code', 16)->nullable()->after('quantity');
            });
        }

        if (Schema::hasTable('finance_credit_note_items') && ! Schema::hasColumn('finance_credit_note_items', 'unit_code')) {
            Schema::table('finance_credit_note_items', function (Blueprint $table): void {
                $table->string('unit_code', 16)->nullable()->after('quantity');
            });
        }

        if (Schema::hasTable('pos_cashier_invoices') && ! Schema::hasColumn('pos_cashier_invoices', 'tax_document_subtype')) {
            Schema::table('pos_cashier_invoices', function (Blueprint $table): void {
                $table->string('tax_document_subtype', 32)->nullable()->after('status');
            });
        }

        if (Schema::hasTable('pos_cashier_invoice_items') && ! Schema::hasColumn('pos_cashier_invoice_items', 'unit_code')) {
            Schema::table('pos_cashier_invoice_items', function (Blueprint $table): void {
                $table->string('unit_code', 16)->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_invoices') && Schema::hasColumn('finance_invoices', 'supply_date')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->dropColumn('supply_date');
            });
        }

        if (Schema::hasTable('finance_invoice_items') && Schema::hasColumn('finance_invoice_items', 'unit_code')) {
            Schema::table('finance_invoice_items', function (Blueprint $table): void {
                $table->dropColumn('unit_code');
            });
        }

        if (Schema::hasTable('finance_credit_note_items') && Schema::hasColumn('finance_credit_note_items', 'unit_code')) {
            Schema::table('finance_credit_note_items', function (Blueprint $table): void {
                $table->dropColumn('unit_code');
            });
        }

        if (Schema::hasTable('pos_cashier_invoices') && Schema::hasColumn('pos_cashier_invoices', 'tax_document_subtype')) {
            Schema::table('pos_cashier_invoices', function (Blueprint $table): void {
                $table->dropColumn('tax_document_subtype');
            });
        }

        if (Schema::hasTable('pos_cashier_invoice_items') && Schema::hasColumn('pos_cashier_invoice_items', 'unit_code')) {
            Schema::table('pos_cashier_invoice_items', function (Blueprint $table): void {
                $table->dropColumn('unit_code');
            });
        }
    }
};
