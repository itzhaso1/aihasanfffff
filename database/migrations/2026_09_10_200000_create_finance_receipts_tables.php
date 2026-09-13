<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_settings')) {
            Schema::table('finance_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_settings', 'receipt_prefix')) {
                    $table->string('receipt_prefix', 20)->default('RCT');
                }
                if (! Schema::hasColumn('finance_settings', 'next_receipt_sequence')) {
                    $table->unsignedInteger('next_receipt_sequence')->default(1);
                }
            });
        }

        if (Schema::hasTable('finance_receipts')) {
            return;
        }

        Schema::create('finance_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('finance_invoice_payments')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('receipt_number', 64);
            $table->string('currency', 3)->default('SAR');
            $table->date('payment_date');
            $table->string('method', 32);
            $table->string('reference')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('posted');
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'receipt_number']);
            $table->index(['workspace_id', 'invoice_id']);
            $table->index(['workspace_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_receipts');

        if (Schema::hasTable('finance_settings')) {
            Schema::table('finance_settings', function (Blueprint $table): void {
                $drop = [];
                if (Schema::hasColumn('finance_settings', 'receipt_prefix')) {
                    $drop[] = 'receipt_prefix';
                }
                if (Schema::hasColumn('finance_settings', 'next_receipt_sequence')) {
                    $drop[] = 'next_receipt_sequence';
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
