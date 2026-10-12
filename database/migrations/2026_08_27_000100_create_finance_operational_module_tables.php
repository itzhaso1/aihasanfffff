<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('finance_price_lists')) {
            Schema::create('finance_price_lists', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 64)->nullable();
                $table->string('currency', 3)->default('SAR');
                $table->enum('status', ['draft', 'approved', 'cancelled'])->default('draft');
                $table->date('effective_from')->nullable();
                $table->date('effective_to')->nullable();
                $table->text('notes')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['workspace_id', 'name']);
                $table->unique(['workspace_id', 'code']);
                $table->index(['workspace_id', 'status']);
            });
        }

        if (! Schema::hasTable('finance_price_list_items')) {
            Schema::create('finance_price_list_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('price_list_id')->constrained('finance_price_lists')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->string('product_name');
                $table->string('sku')->nullable();
                $table->decimal('min_quantity', 12, 3)->default(1);
                $table->decimal('price', 14, 2);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'price_list_id']);
                $table->index(['workspace_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('finance_payroll_adjustments')) {
            Schema::create('finance_payroll_adjustments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->enum('type', ['allowance', 'bonus', 'deduction']);
                $table->string('title');
                $table->decimal('amount', 14, 2);
                $table->date('effective_date');
                $table->enum('status', ['draft', 'approved', 'posted', 'cancelled'])->default('draft');
                $table->text('notes')->nullable();
                $table->foreignId('payroll_run_id')->nullable()->constrained('finance_payroll_runs')->nullOnDelete();
                $table->foreignId('posted_journal_entry_id')->nullable()->constrained('finance_journal_entries')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'type', 'status', 'effective_date'], 'fin_pay_adj_ws_type_status_date_idx');
                $table->index(['workspace_id', 'user_id', 'status'], 'fin_pay_adj_ws_user_status_idx');
            });
        }

        if (! Schema::hasTable('finance_salary_advance_repayments')) {
            Schema::create('finance_salary_advance_repayments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('salary_advance_id')->constrained('finance_salary_advances')->cascadeOnDelete();
                $table->foreignId('treasury_account_id')->nullable()->constrained('finance_treasury_accounts')->nullOnDelete();
                $table->date('payment_date');
                $table->decimal('amount', 14, 2);
                $table->enum('method', ['cash', 'bank_transfer', 'card', 'other', 'payroll_deduction'])->default('cash');
                $table->enum('status', ['posted', 'cancelled'])->default('posted');
                $table->text('notes')->nullable();
                $table->foreignId('posted_journal_entry_id')
                    ->nullable()
                    ->constrained('finance_journal_entries', 'id', 'fin_sal_adv_rep_posted_je_fk')
                    ->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['workspace_id', 'salary_advance_id', 'payment_date'], 'fin_salary_rep_ws_adv_date_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_salary_advance_repayments');
        Schema::dropIfExists('finance_payroll_adjustments');
        Schema::dropIfExists('finance_price_list_items');
        Schema::dropIfExists('finance_price_lists');
    }
};
