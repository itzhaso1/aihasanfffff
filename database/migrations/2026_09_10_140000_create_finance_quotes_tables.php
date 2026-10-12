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
                if (! Schema::hasColumn('finance_settings', 'quote_prefix')) {
                    $table->string('quote_prefix', 20)->default('Q');
                }
                if (! Schema::hasColumn('finance_settings', 'next_quote_sequence')) {
                    $table->unsignedInteger('next_quote_sequence')->default(1);
                }
            });
        }

        if (! Schema::hasTable('finance_quotes')) {
            Schema::create('finance_quotes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
                $table->string('quote_number', 64);
                $table->string('status', 16)->default('draft');
                $table->date('issue_date');
                $table->date('expiry_date')->nullable();
                $table->string('currency', 3)->default('SAR');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('taxable_amount', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->string('tax_profile_type', 32)->default('standard');
                $table->decimal('tax_rate', 8, 2)->default(0);
                $table->string('tax_price_mode', 16)->default('exclusive');
                $table->json('tax_breakdown')->nullable();
                $table->text('notes')->nullable();
                $table->text('terms')->nullable();
                $table->json('company_snapshot')->nullable();
                $table->json('recipient_snapshot')->nullable();
                $table->json('pdf_snapshot')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['workspace_id', 'quote_number']);
                $table->index(['workspace_id', 'status']);
                $table->index(['workspace_id', 'customer_id']);
            });
        }

        if (! Schema::hasTable('finance_quote_items')) {
            Schema::create('finance_quote_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('quote_id')->constrained('finance_quotes')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
                $table->string('product_name');
                $table->text('description')->nullable();
                $table->string('unit', 32)->nullable();
                $table->string('unit_code', 16)->nullable();
                $table->decimal('quantity', 14, 3);
                $table->decimal('unit_price', 14, 2);
                $table->decimal('discount', 14, 2)->default(0);
                $table->string('tax_profile_type', 32)->default('standard');
                $table->string('exemption_reason')->nullable();
                $table->string('exemption_code', 32)->nullable();
                $table->decimal('tax_rate', 8, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('taxable_amount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'quote_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_quote_items');
        Schema::dropIfExists('finance_quotes');

        if (Schema::hasTable('finance_settings')) {
            Schema::table('finance_settings', function (Blueprint $table): void {
                $drop = [];
                if (Schema::hasColumn('finance_settings', 'quote_prefix')) {
                    $drop[] = 'quote_prefix';
                }
                if (Schema::hasColumn('finance_settings', 'next_quote_sequence')) {
                    $drop[] = 'next_quote_sequence';
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
