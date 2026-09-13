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
        Schema::create('finance_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('company_name_ar')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('commercial_registration')->nullable();
            $table->string('address_line')->nullable();
            $table->string('building_number')->nullable();
            $table->string('street')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 2)->default('SA');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->string('invoice_prefix', 20)->default('INV');
            $table->unsignedInteger('next_invoice_sequence')->default(1);
            $table->string('default_payment_terms')->nullable();
            $table->decimal('default_vat_rate', 5, 2)->default(15.00);
            $table->string('zatca_integration_mode')->nullable();
            $table->string('zatca_certificate_serial')->nullable();
            $table->timestamp('zatca_last_synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('workspace_id');
        });

        Schema::create('finance_tax_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->enum('type', ['standard', 'zero_rated', 'exempt', 'out_of_scope'])->default('standard');
            $table->decimal('rate', 5, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'code']);
            $table->index(['workspace_id', 'is_default']);
        });

        Schema::create('finance_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense']);
            $table->string('classification')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('allow_manual_entries')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'code']);
            $table->index(['workspace_id', 'type']);
        });

        Schema::create('finance_fiscal_years', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            $table->unique(['workspace_id', 'name'], 'fin_fy_ws_name_uniq');
            $table->index(['workspace_id', 'status', 'start_date', 'end_date'], 'fin_fy_ws_status_dates_idx');
        });

        Schema::create('finance_accounting_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('finance_fiscal_years')->cascadeOnDelete();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            $table->unique(['workspace_id', 'fiscal_year_id', 'name'], 'fin_period_ws_fy_name_uniq');
            $table->index(['workspace_id', 'status', 'start_date', 'end_date'], 'fin_period_ws_status_dates_idx');
        });

        Schema::create('finance_treasury_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['cash', 'bank'])->default('cash');
            $table->string('account_number')->nullable();
            $table->string('iban')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->foreignId('linked_finance_account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'type']);
        });

        Schema::create('finance_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('arabic_name')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('commercial_registration')->nullable();
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->string('payment_terms')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'name']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('finance_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('finance_suppliers')->nullOnDelete();
            $table->string('invoice_number');
            $table->enum('type', ['sales', 'purchase']);
            $table->enum('status', ['draft', 'sent', 'unpaid', 'partial', 'paid', 'overdue', 'cancelled'])->default('draft');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('currency', 3)->default('SAR');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('amount_due', 14, 2)->default(0);
            $table->enum('tax_profile_type', ['standard', 'zero_rated', 'exempt', 'out_of_scope'])->default('standard');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('zatca_uuid')->nullable();
            $table->text('zatca_qr_code')->nullable();
            $table->string('zatca_xml_hash')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'invoice_number']);
            $table->index(['workspace_id', 'type', 'status']);
            $table->index(['workspace_id', 'issue_date', 'due_date']);
        });

        Schema::create('finance_invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'invoice_id']);
        });

        Schema::create('finance_journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('entry_number');
            $table->date('entry_date');
            $table->enum('type', ['manual', 'sales_invoice', 'purchase_invoice', 'invoice_payment', 'expense', 'payroll', 'adjustment'])->default('manual');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'posted', 'reversed'])->default('posted');
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'entry_number']);
            $table->index(['workspace_id', 'entry_date', 'status']);
        });

        Schema::create('finance_journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('finance_journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('finance_accounts')->cascadeOnDelete();
            $table->text('description')->nullable();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'journal_entry_id']);
        });

        Schema::create('finance_invoice_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('finance_invoices')->cascadeOnDelete();
            $table->foreignId('treasury_account_id')->nullable()->constrained('finance_treasury_accounts')->nullOnDelete();
            $table->date('payment_date');
            $table->enum('method', ['cash', 'bank_transfer', 'card', 'other'])->default('cash');
            $table->string('reference')->nullable();
            $table->decimal('amount', 14, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'invoice_id', 'payment_date'], 'fin_inv_pay_ws_inv_date_idx');
        });

        Schema::create('finance_expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->foreignId('linked_account_id')->nullable()->constrained('finance_accounts')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'code']);
        });

        Schema::create('finance_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('finance_suppliers')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('finance_expense_categories')->nullOnDelete();
            $table->foreignId('treasury_account_id')->nullable()->constrained('finance_treasury_accounts')->nullOnDelete();
            $table->string('expense_number');
            $table->date('expense_date');
            $table->text('description')->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'card', 'other', 'credit'])->default('cash');
            $table->enum('status', ['draft', 'approved', 'paid', 'cancelled'])->default('approved');
            $table->boolean('is_recurring')->default(false);
            $table->enum('recurring_frequency', ['weekly', 'monthly', 'quarterly', 'yearly'])->nullable();
            $table->date('next_due_date')->nullable();
            $table->string('attachment_path')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'expense_number']);
            $table->index(['workspace_id', 'status', 'expense_date']);
        });

        Schema::create('finance_employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->decimal('housing_allowance', 14, 2)->default(0);
            $table->decimal('transport_allowance', 14, 2)->default(0);
            $table->decimal('other_allowances', 14, 2)->default(0);
            $table->decimal('default_deductions', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('finance_salary_advances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->decimal('remaining_amount', 14, 2);
            $table->date('issued_at');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->enum('type', ['salary_advance', 'employee_loan'])->default('salary_advance');
            $table->string('payment_method', 32)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'user_id', 'status']);
        });

        Schema::create('finance_payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('period_month');
            $table->enum('status', ['draft', 'processed', 'posted'])->default('draft');
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'period_month']);
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('finance_payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('finance_payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('basic_salary', 14, 2)->default(0);
            $table->decimal('housing_allowance', 14, 2)->default(0);
            $table->decimal('transport_allowance', 14, 2)->default(0);
            $table->decimal('other_allowances', 14, 2)->default(0);
            $table->decimal('overtime', 14, 2)->default(0);
            $table->decimal('bonuses', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('advances', 14, 2)->default(0);
            $table->decimal('absence_deduction', 14, 2)->default(0);
            $table->decimal('net_salary', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'payroll_run_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_payroll_items');
        Schema::dropIfExists('finance_payroll_runs');
        Schema::dropIfExists('finance_salary_advances');
        Schema::dropIfExists('finance_employee_profiles');
        Schema::dropIfExists('finance_expenses');
        Schema::dropIfExists('finance_expense_categories');
        Schema::dropIfExists('finance_invoice_payments');
        Schema::dropIfExists('finance_journal_entry_lines');
        Schema::dropIfExists('finance_journal_entries');
        Schema::dropIfExists('finance_invoice_items');
        Schema::dropIfExists('finance_invoices');
        Schema::dropIfExists('finance_suppliers');
        Schema::dropIfExists('finance_treasury_accounts');
        Schema::dropIfExists('finance_accounting_periods');
        Schema::dropIfExists('finance_fiscal_years');
        Schema::dropIfExists('finance_accounts');
        Schema::dropIfExists('finance_tax_rates');
        Schema::dropIfExists('finance_settings');
    }
};
