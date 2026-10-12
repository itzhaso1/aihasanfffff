<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_invoices')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_invoices', 'contract_id')) {
                    $table->foreignId('contract_id')->nullable()->after('supplier_id')->constrained('contracts')->nullOnDelete();
                }
                if (! Schema::hasColumn('finance_invoices', 'amount_credited')) {
                    $table->decimal('amount_credited', 14, 2)->default(0)->after('amount_due');
                }
                if (! Schema::hasColumn('finance_invoices', 'amount_debited')) {
                    $table->decimal('amount_debited', 14, 2)->default(0)->after('amount_credited');
                }
                if (! Schema::hasColumn('finance_invoices', 'issued_by')) {
                    $table->foreignId('issued_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('finance_invoices', 'last_reminder_sent_at')) {
                    $table->timestamp('last_reminder_sent_at')->nullable()->after('cancelled_at');
                }
                if (! Schema::hasColumn('finance_invoices', 'reminder_stage')) {
                    $table->string('reminder_stage', 32)->nullable()->after('last_reminder_sent_at');
                }
            });
        }

        if (! Schema::hasTable('finance_billing_schedules')) {
            Schema::create('finance_billing_schedules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
                $table->string('title');
                $table->string('status', 32)->default('draft');
                $table->string('frequency', 32)->default('monthly');
                $table->unsignedInteger('interval_count')->default(1);
                $table->unsignedInteger('total_occurrences')->nullable();
                $table->unsignedInteger('generated_count')->default(0);
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('SAR');
                $table->date('start_date');
                $table->date('end_date')->nullable();
                $table->date('next_run_on')->nullable();
                $table->boolean('auto_issue')->default(false);
                $table->string('invoice_type', 16)->default('sales');
                $table->text('notes')->nullable();
                $table->json('item_snapshot')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'status', 'next_run_on'], 'fin_bill_sched_ws_status_run_idx');
                $table->index(['workspace_id', 'contract_id'], 'fin_bill_sched_ws_contract_idx');
            });
        }

        if (Schema::hasTable('finance_invoices') && ! Schema::hasColumn('finance_invoices', 'billing_schedule_id')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->foreignId('billing_schedule_id')->nullable()->after('contract_id')->constrained('finance_billing_schedules')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('finance_invoice_attachments')) {
            Schema::create('finance_invoice_attachments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invoice_id')->constrained('finance_invoices')->cascadeOnDelete();
                $table->string('file_path');
                $table->string('file_name')->nullable();
                $table->string('file_type')->nullable();
                $table->unsignedBigInteger('file_size')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['workspace_id', 'invoice_id'], 'fin_inv_att_ws_inv_idx');
            });
        }

        if (! Schema::hasTable('finance_credit_notes')) {
            Schema::create('finance_credit_notes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('invoice_id')->constrained('finance_invoices')->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->string('note_number', 64);
                $table->string('type', 16); // credit | debit
                $table->string('status', 32)->default('draft');
                $table->string('reason')->nullable();
                $table->date('issue_date');
                $table->string('currency', 3)->default('SAR');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('discount', 14, 2)->default(0);
                $table->decimal('taxable_amount', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->string('tax_profile_type', 32)->default('standard');
                $table->decimal('tax_rate', 5, 2)->default(0);
                $table->text('notes')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['workspace_id', 'note_number'], 'fin_cn_ws_number_uidx');
                $table->index(['workspace_id', 'invoice_id', 'type', 'status'], 'fin_cn_ws_inv_type_status_idx');
            });
        }

        if (! Schema::hasTable('finance_credit_note_items')) {
            Schema::create('finance_credit_note_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('credit_note_id')->constrained('finance_credit_notes')->cascadeOnDelete();
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

                $table->index(['workspace_id', 'credit_note_id'], 'fin_cn_item_ws_note_idx');
            });
        }

        if (Schema::hasTable('finance_invoice_payments')) {
            Schema::table('finance_invoice_payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_invoice_payments', 'status')) {
                    $table->string('status', 32)->default('posted')->after('amount');
                }
                if (! Schema::hasColumn('finance_invoice_payments', 'reversed_at')) {
                    $table->timestamp('reversed_at')->nullable()->after('status');
                }
                if (! Schema::hasColumn('finance_invoice_payments', 'reversed_by')) {
                    $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('finance_invoice_payments', 'reversal_reason')) {
                    $table->string('reversal_reason')->nullable()->after('reversed_by');
                }
            });

            DB::table('finance_invoice_payments')
                ->where(function ($query): void {
                    $query->whereNull('reference')->orWhere('reference', '');
                })
                ->update(['reference' => null]);

            $duplicates = DB::table('finance_invoice_payments')
                ->select('workspace_id', 'invoice_id', 'reference')
                ->whereNotNull('reference')
                ->groupBy('workspace_id', 'invoice_id', 'reference')
                ->havingRaw('COUNT(*) > 1')
                ->get();

            foreach ($duplicates as $duplicate) {
                $rows = DB::table('finance_invoice_payments')
                    ->where('workspace_id', $duplicate->workspace_id)
                    ->where('invoice_id', $duplicate->invoice_id)
                    ->where('reference', $duplicate->reference)
                    ->orderBy('id')
                    ->get();

                foreach ($rows as $index => $row) {
                    if ($index === 0) {
                        continue;
                    }

                    DB::table('finance_invoice_payments')
                        ->where('id', $row->id)
                        ->update(['reference' => $duplicate->reference.'-'.$row->id]);
                }
            }

            if (! $this->indexExists('finance_invoice_payments', 'fin_inv_pay_ws_inv_ref_uidx')) {
                Schema::table('finance_invoice_payments', function (Blueprint $table): void {
                    $table->unique(['workspace_id', 'invoice_id', 'reference'], 'fin_inv_pay_ws_inv_ref_uidx');
                });
            }
        }

        if (Schema::hasTable('finance_settings')) {
            Schema::table('finance_settings', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_settings', 'credit_note_prefix')) {
                    $table->string('credit_note_prefix', 16)->nullable()->after('next_invoice_sequence');
                }
                if (! Schema::hasColumn('finance_settings', 'next_credit_note_sequence')) {
                    $table->unsignedInteger('next_credit_note_sequence')->default(1)->after('credit_note_prefix');
                }
                if (! Schema::hasColumn('finance_settings', 'debit_note_prefix')) {
                    $table->string('debit_note_prefix', 16)->nullable()->after('next_credit_note_sequence');
                }
                if (! Schema::hasColumn('finance_settings', 'next_debit_note_sequence')) {
                    $table->unsignedInteger('next_debit_note_sequence')->default(1)->after('debit_note_prefix');
                }
                if (! Schema::hasColumn('finance_settings', 'next_contract_sequence')) {
                    $table->unsignedInteger('next_contract_sequence')->default(1)->after('next_debit_note_sequence');
                }
            });
        }

        if (Schema::hasTable('finance_journal_entries') && ! Schema::hasColumn('finance_journal_entries', 'reverses_entry_id')) {
            Schema::table('finance_journal_entries', function (Blueprint $table): void {
                $table->foreignId('reverses_entry_id')->nullable()->after('reference_id')->constrained('finance_journal_entries')->nullOnDelete();
            });
        }

        $this->widenJournalEntryTypeColumn();
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_invoices') && Schema::hasColumn('finance_invoices', 'billing_schedule_id')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('billing_schedule_id');
            });
        }

        Schema::dropIfExists('finance_credit_note_items');
        Schema::dropIfExists('finance_credit_notes');
        Schema::dropIfExists('finance_invoice_attachments');
        Schema::dropIfExists('finance_billing_schedules');

        if (Schema::hasTable('finance_invoices')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                foreach (['contract_id', 'amount_credited', 'amount_debited', 'issued_by', 'last_reminder_sent_at', 'reminder_stage'] as $column) {
                    if (Schema::hasColumn('finance_invoices', $column)) {
                        if (in_array($column, ['contract_id', 'issued_by'], true)) {
                            $table->dropConstrainedForeignId($column);
                        } else {
                            $table->dropColumn($column);
                        }
                    }
                }
            });
        }
    }

    private function widenJournalEntryTypeColumn(): void
    {
        if (! Schema::hasTable('finance_journal_entries')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE finance_journal_entries MODIFY type VARCHAR(64) NOT NULL DEFAULT 'manual'");

            return;
        }

        try {
            Schema::table('finance_journal_entries', function (Blueprint $table): void {
                $table->string('type', 64)->default('manual')->change();
            });
        } catch (\Throwable) {
            // SQLite without doctrine change support still stores enum values as strings.
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $index) {
                if (($index->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'mysql') {
            $rows = DB::select('SHOW INDEX FROM '.$table.' WHERE Key_name = ?', [$indexName]);

            return $rows !== [];
        }

        return false;
    }
};
