<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contracts')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE contracts MODIFY status VARCHAR(32) NOT NULL DEFAULT 'draft'");
            } elseif ($driver === 'pgsql') {
                DB::statement('ALTER TABLE contracts ALTER COLUMN status TYPE VARCHAR(32)');
            }

            Schema::table('contracts', function (Blueprint $table): void {
                if (! Schema::hasColumn('contracts', 'description')) {
                    $table->text('description')->nullable()->after('title');
                }
                if (! Schema::hasColumn('contracts', 'payment_terms')) {
                    $table->string('payment_terms', 255)->nullable()->after('currency');
                }
                if (! Schema::hasColumn('contracts', 'parent_contract_id')) {
                    $table->foreignId('parent_contract_id')->nullable()->after('project_id')->constrained('contracts')->nullOnDelete();
                }
                if (! Schema::hasColumn('contracts', 'signature_status')) {
                    $table->string('signature_status', 32)->default('unsigned')->after('cancelled_at');
                }
                if (! Schema::hasColumn('contracts', 'customer_signed_at')) {
                    $table->timestamp('customer_signed_at')->nullable()->after('signature_status');
                }
                if (! Schema::hasColumn('contracts', 'company_signed_at')) {
                    $table->timestamp('company_signed_at')->nullable()->after('customer_signed_at');
                }
                if (! Schema::hasColumn('contracts', 'customer_signed_by')) {
                    $table->string('customer_signed_by', 255)->nullable()->after('company_signed_at');
                }
                if (! Schema::hasColumn('contracts', 'company_signed_by')) {
                    $table->foreignId('company_signed_by')->nullable()->after('customer_signed_by')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('contracts', 'ending_soon_notified_at')) {
                    $table->timestamp('ending_soon_notified_at')->nullable()->after('company_signed_by');
                }
            });
        }

        if (Schema::hasTable('contract_items')) {
            Schema::table('contract_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('contract_items', 'product_id') && Schema::hasTable('products')) {
                    $table->foreignId('product_id')->nullable()->after('contract_id')->constrained('products')->nullOnDelete();
                }
                if (! Schema::hasColumn('contract_items', 'item_kind')) {
                    $table->string('item_kind', 32)->default('service')->after('title');
                }
                if (! Schema::hasColumn('contract_items', 'unit')) {
                    $table->string('unit', 32)->nullable()->after('item_kind');
                }
                if (! Schema::hasColumn('contract_items', 'discount')) {
                    $table->decimal('discount', 14, 2)->default(0)->after('unit_price');
                }
                if (! Schema::hasColumn('contract_items', 'discount_type')) {
                    $table->string('discount_type', 16)->default('amount')->after('discount');
                }
                if (! Schema::hasColumn('contract_items', 'tax_rate')) {
                    $table->decimal('tax_rate', 8, 2)->default(0)->after('discount_type');
                }
                if (! Schema::hasColumn('contract_items', 'tax_type')) {
                    $table->string('tax_type', 32)->default('standard')->after('tax_rate');
                }
                if (! Schema::hasColumn('contract_items', 'taxable_amount')) {
                    $table->decimal('taxable_amount', 14, 2)->default(0)->after('tax_type');
                }
                if (! Schema::hasColumn('contract_items', 'tax_amount')) {
                    $table->decimal('tax_amount', 14, 2)->default(0)->after('taxable_amount');
                }
            });
        }

        if (Schema::hasTable('contract_attachments')) {
            Schema::table('contract_attachments', function (Blueprint $table): void {
                if (! Schema::hasColumn('contract_attachments', 'uploaded_by')) {
                    $table->foreignId('uploaded_by')->nullable()->after('file_size')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('contract_attachments', 'disk')) {
                    $table->string('disk', 32)->default('public')->after('uploaded_by');
                }
            });
        }

        if (Schema::hasTable('finance_billing_schedules') && ! Schema::hasColumn('finance_billing_schedules', 'description')) {
            Schema::table('finance_billing_schedules', function (Blueprint $table): void {
                $table->string('description', 500)->nullable()->after('title');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_billing_schedules') && Schema::hasColumn('finance_billing_schedules', 'description')) {
            Schema::table('finance_billing_schedules', function (Blueprint $table): void {
                $table->dropColumn('description');
            });
        }

        if (Schema::hasTable('contract_attachments')) {
            Schema::table('contract_attachments', function (Blueprint $table): void {
                if (Schema::hasColumn('contract_attachments', 'uploaded_by')) {
                    $table->dropConstrainedForeignId('uploaded_by');
                }
                if (Schema::hasColumn('contract_attachments', 'disk')) {
                    $table->dropColumn('disk');
                }
            });
        }

        if (Schema::hasTable('contract_items')) {
            Schema::table('contract_items', function (Blueprint $table): void {
                foreach (['tax_amount', 'taxable_amount', 'tax_type', 'tax_rate', 'discount_type', 'discount', 'unit', 'item_kind'] as $column) {
                    if (Schema::hasColumn('contract_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
                if (Schema::hasColumn('contract_items', 'product_id')) {
                    $table->dropConstrainedForeignId('product_id');
                }
            });
        }

        if (Schema::hasTable('contracts')) {
            Schema::table('contracts', function (Blueprint $table): void {
                foreach ([
                    'ending_soon_notified_at',
                    'customer_signed_by',
                    'company_signed_at',
                    'customer_signed_at',
                    'signature_status',
                    'payment_terms',
                    'description',
                ] as $column) {
                    if (Schema::hasColumn('contracts', $column)) {
                        $table->dropColumn($column);
                    }
                }
                if (Schema::hasColumn('contracts', 'company_signed_by')) {
                    $table->dropConstrainedForeignId('company_signed_by');
                }
                if (Schema::hasColumn('contracts', 'parent_contract_id')) {
                    $table->dropConstrainedForeignId('parent_contract_id');
                }
            });
        }
    }
};
