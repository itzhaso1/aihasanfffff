<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_invoices')) {
            return;
        }

        if (! Schema::hasColumn('finance_invoices', 'billing_occurrence_key')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->string('billing_occurrence_key', 64)->nullable();
            });
        }

        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->unique(
                ['billing_schedule_id', 'billing_occurrence_key'],
                'fin_inv_sched_occurrence_uidx'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_invoices')) {
            return;
        }

        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropUnique('fin_inv_sched_occurrence_uidx');
        });

        if (Schema::hasColumn('finance_invoices', 'billing_occurrence_key')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->dropColumn('billing_occurrence_key');
            });
        }
    }
};
