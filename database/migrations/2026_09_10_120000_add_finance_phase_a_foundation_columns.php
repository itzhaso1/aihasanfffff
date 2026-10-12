<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'party_type')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->string('party_type', 16)->default('individual')->after('name');
            });
        }

        if (Schema::hasTable('finance_invoice_items') && ! Schema::hasColumn('finance_invoice_items', 'unit')) {
            Schema::table('finance_invoice_items', function (Blueprint $table): void {
                $after = Schema::hasColumn('finance_invoice_items', 'unit_code') ? 'unit_code' : 'quantity';
                $table->string('unit', 32)->nullable()->after($after);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'party_type')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('party_type');
            });
        }

        if (Schema::hasTable('finance_invoice_items') && Schema::hasColumn('finance_invoice_items', 'unit')) {
            Schema::table('finance_invoice_items', function (Blueprint $table): void {
                $table->dropColumn('unit');
            });
        }
    }
};
