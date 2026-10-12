<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_quotes')) {
            return;
        }

        Schema::table('finance_quotes', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_quotes', 'outcome')) {
                $table->string('outcome', 16)->default('pending')->after('status');
                $table->index(['workspace_id', 'outcome']);
            }
            if (! Schema::hasColumn('finance_quotes', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('cancelled_at');
            }
            if (! Schema::hasColumn('finance_quotes', 'accepted_by')) {
                $table->foreignId('accepted_by')->nullable()->after('accepted_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('finance_quotes', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('accepted_by');
            }
            if (! Schema::hasColumn('finance_quotes', 'rejected_by')) {
                $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('finance_quotes', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable()->after('rejected_by');
            }
            if (! Schema::hasColumn('finance_quotes', 'converted_invoice_id')) {
                $table->foreignId('converted_invoice_id')->nullable()->after('rejection_reason')->constrained('finance_invoices')->restrictOnDelete();
                $table->unique('converted_invoice_id');
            }
            if (! Schema::hasColumn('finance_quotes', 'converted_at')) {
                $table->timestamp('converted_at')->nullable()->after('converted_invoice_id');
            }
            if (! Schema::hasColumn('finance_quotes', 'converted_by')) {
                $table->foreignId('converted_by')->nullable()->after('converted_at')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('finance_quotes')) {
            return;
        }

        Schema::table('finance_quotes', function (Blueprint $table): void {
            if (Schema::hasColumn('finance_quotes', 'converted_invoice_id')) {
                $table->dropUnique(['converted_invoice_id']);
                $table->dropConstrainedForeignId('converted_invoice_id');
            }
            foreach (['converted_by', 'rejected_by', 'accepted_by'] as $column) {
                if (Schema::hasColumn('finance_quotes', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
            $drop = [];
            foreach (['outcome', 'accepted_at', 'rejected_at', 'rejection_reason', 'converted_at'] as $column) {
                if (Schema::hasColumn('finance_quotes', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
