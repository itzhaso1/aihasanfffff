<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('finance_treasury_transfers')) {
            Schema::create('finance_treasury_transfers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('from_treasury_account_id')->constrained('finance_treasury_accounts')->cascadeOnDelete();
                $table->foreignId('to_treasury_account_id')->constrained('finance_treasury_accounts')->cascadeOnDelete();
                $table->decimal('amount', 14, 2);
                $table->date('transfer_date');
                $table->string('reference')->nullable();
                $table->string('status', 32)->default('posted');
                $table->foreignId('journal_entry_id')->nullable()->constrained('finance_journal_entries')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['workspace_id', 'transfer_date'], 'fin_treasury_xfer_ws_date_idx');
                $table->unique(['workspace_id', 'reference'], 'fin_treasury_xfer_ws_ref_uidx');
            });
        }

        if (! Schema::hasTable('finance_bank_statements')) {
            Schema::create('finance_bank_statements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('treasury_account_id')->constrained('finance_treasury_accounts')->cascadeOnDelete();
                $table->date('statement_date');
                $table->decimal('opening_balance', 14, 2)->default(0);
                $table->decimal('closing_balance', 14, 2)->default(0);
                $table->string('status', 32)->default('open');
                $table->text('notes')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'treasury_account_id', 'statement_date'], 'fin_bank_stmt_ws_acct_date_idx');
            });
        }

        if (! Schema::hasTable('finance_bank_statement_lines')) {
            Schema::create('finance_bank_statement_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bank_statement_id')->constrained('finance_bank_statements')->cascadeOnDelete();
                $table->date('posted_date');
                $table->string('description')->nullable();
                $table->string('reference')->nullable();
                $table->decimal('amount', 14, 2);
                $table->string('status', 32)->default('unmatched');
                $table->string('suggested_type')->nullable();
                $table->unsignedBigInteger('suggested_id')->nullable();
                $table->unsignedTinyInteger('suggestion_confidence')->nullable();
                $table->string('suggestion_reason')->nullable();
                $table->string('matched_type')->nullable();
                $table->unsignedBigInteger('matched_id')->nullable();
                $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('matched_at')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'bank_statement_id', 'status'], 'fin_bank_line_ws_stmt_status_idx');
            });
        }

        if (! Schema::hasTable('finance_purchase_orders')) {
            Schema::create('finance_purchase_orders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('supplier_id')->constrained('finance_suppliers')->restrictOnDelete();
                $table->string('po_number');
                $table->string('status', 32)->default('draft');
                $table->date('order_date');
                $table->date('expected_date')->nullable();
                $table->string('currency', 3)->default('SAR');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->foreignId('finance_invoice_id')->nullable()->constrained('finance_invoices')->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['workspace_id', 'po_number'], 'fin_po_ws_number_uidx');
                $table->index(['workspace_id', 'status', 'order_date'], 'fin_po_ws_status_date_idx');
            });
        }

        if (! Schema::hasTable('finance_purchase_order_items')) {
            Schema::create('finance_purchase_order_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_id')->constrained('finance_purchase_orders')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->string('product_name');
                $table->decimal('quantity', 12, 3)->default(1);
                $table->unsignedInteger('received_quantity')->default(0);
                $table->decimal('unit_price', 14, 2)->default(0);
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('taxable_amount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->timestamps();

                $table->index(['workspace_id', 'purchase_order_id'], 'fin_po_items_ws_po_idx');
            });
        } else {
            $this->ensureIndex('finance_purchase_order_items', 'fin_po_items_ws_po_idx', ['workspace_id', 'purchase_order_id']);
        }

        if (! Schema::hasTable('crm_leads')) {
            Schema::create('crm_leads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('company_name')->nullable();
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('source')->nullable();
                $table->string('status', 32)->default('new');
                $table->decimal('estimated_value', 14, 2)->default(0);
                $table->string('currency', 3)->default('SAR');
                $table->text('notes')->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'status'], 'crm_leads_ws_status_idx');
                $table->index(['workspace_id', 'name'], 'crm_leads_ws_name_idx');
            });
        }

        if (! Schema::hasTable('finance_projects')) {
            Schema::create('finance_projects', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('name');
                $table->string('status', 32)->default('active');
                $table->decimal('budget', 14, 2)->default(0);
                $table->date('starts_on')->nullable();
                $table->date('ends_on')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'status'], 'fin_projects_ws_status_idx');
            });
        }

        if (Schema::hasTable('finance_invoices') && ! Schema::hasColumn('finance_invoices', 'project_id')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->foreignId('project_id')->nullable()->constrained('finance_projects')->nullOnDelete();
            });
        }

        if (Schema::hasTable('finance_expenses') && ! Schema::hasColumn('finance_expenses', 'project_id')) {
            Schema::table('finance_expenses', function (Blueprint $table): void {
                $table->foreignId('project_id')->nullable()->constrained('finance_projects')->nullOnDelete();
            });
        }

        $this->ensureIndex('finance_journal_entry_lines', 'fin_jel_ws_account_idx', ['workspace_id', 'account_id']);
        $this->ensureIndex('products', 'products_ws_track_stock_idx', ['workspace_id', 'inventory_tracking', 'stock']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('finance_invoices', 'project_id')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('project_id');
            });
        }
        if (Schema::hasColumn('finance_expenses', 'project_id')) {
            Schema::table('finance_expenses', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('project_id');
            });
        }

        Schema::dropIfExists('finance_purchase_order_items');
        Schema::dropIfExists('finance_purchase_orders');
        Schema::dropIfExists('finance_bank_statement_lines');
        Schema::dropIfExists('finance_bank_statements');
        Schema::dropIfExists('finance_treasury_transfers');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('finance_projects');
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function ensureIndex(string $table, string $name, array $columns): void
    {
        if (! Schema::hasTable($table) || strlen($name) > 64 || $this->indexExists($table, $name) || $this->columnsIndexed($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $columns): void {
            $blueprint->index($columns, $name);
        });
    }

    private function indexExists(string $table, string $name): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? '') === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function columnsIndexed(string $table, array $columns): bool
    {
        $wanted = array_values($columns);
        foreach (Schema::getIndexes($table) as $index) {
            if (array_values($index['columns'] ?? []) === $wanted) {
                return true;
            }
        }

        return false;
    }
};
