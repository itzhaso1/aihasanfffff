<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'tax_rate')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->decimal('tax_rate', 8, 2)->nullable()->after('tax_amount');
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('order_items', 'taxable_amount')) {
                    $table->decimal('taxable_amount', 12, 2)->nullable()->after('discount_amount');
                }
                if (! Schema::hasColumn('order_items', 'tax_rate')) {
                    $table->decimal('tax_rate', 8, 2)->nullable()->after('taxable_amount');
                }
                if (! Schema::hasColumn('order_items', 'tax_amount')) {
                    $table->decimal('tax_amount', 12, 2)->nullable()->after('tax_rate');
                }
            });
        }

        if (Schema::hasTable('pos_cashier_invoices')) {
            Schema::table('pos_cashier_invoices', function (Blueprint $table): void {
                if (! Schema::hasColumn('pos_cashier_invoices', 'taxable_amount')) {
                    $table->decimal('taxable_amount', 12, 2)->nullable()->after('discount_amount');
                }
                if (! Schema::hasColumn('pos_cashier_invoices', 'tax_rate')) {
                    $table->decimal('tax_rate', 8, 2)->nullable()->after('taxable_amount');
                }
                if (! Schema::hasColumn('pos_cashier_invoices', 'tax_amount')) {
                    $table->decimal('tax_amount', 12, 2)->nullable()->after('tax_rate');
                }
            });
        }

        if (Schema::hasTable('pos_cashier_invoice_items')) {
            Schema::table('pos_cashier_invoice_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('pos_cashier_invoice_items', 'taxable_amount')) {
                    $table->decimal('taxable_amount', 12, 2)->nullable()->after('discount_amount');
                }
                if (! Schema::hasColumn('pos_cashier_invoice_items', 'tax_rate')) {
                    $table->decimal('tax_rate', 8, 2)->nullable()->after('taxable_amount');
                }
                if (! Schema::hasColumn('pos_cashier_invoice_items', 'tax_amount')) {
                    $table->decimal('tax_amount', 12, 2)->nullable()->after('tax_rate');
                }
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table): void {
                if (! Schema::hasColumn('customers', 'building_number')) {
                    $table->string('building_number', 32)->nullable()->after('address');
                }
                if (! Schema::hasColumn('customers', 'street')) {
                    $table->string('street', 191)->nullable()->after('building_number');
                }
                if (! Schema::hasColumn('customers', 'district')) {
                    $table->string('district', 191)->nullable()->after('street');
                }
                if (! Schema::hasColumn('customers', 'city')) {
                    $table->string('city', 191)->nullable()->after('district');
                }
                if (! Schema::hasColumn('customers', 'postal_code')) {
                    $table->string('postal_code', 16)->nullable()->after('city');
                }
                if (! Schema::hasColumn('customers', 'country_code')) {
                    $table->string('country_code', 2)->nullable()->after('postal_code');
                }
                if (! Schema::hasColumn('customers', 'additional_number')) {
                    $table->string('additional_number', 32)->nullable()->after('country_code');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'tax_rate')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropColumn('tax_rate');
            });
        }

        if (Schema::hasTable('order_items')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $drop = array_values(array_filter([
                    Schema::hasColumn('order_items', 'taxable_amount') ? 'taxable_amount' : null,
                    Schema::hasColumn('order_items', 'tax_rate') ? 'tax_rate' : null,
                    Schema::hasColumn('order_items', 'tax_amount') ? 'tax_amount' : null,
                ]));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('pos_cashier_invoices')) {
            Schema::table('pos_cashier_invoices', function (Blueprint $table): void {
                $drop = array_values(array_filter([
                    Schema::hasColumn('pos_cashier_invoices', 'taxable_amount') ? 'taxable_amount' : null,
                    Schema::hasColumn('pos_cashier_invoices', 'tax_rate') ? 'tax_rate' : null,
                    Schema::hasColumn('pos_cashier_invoices', 'tax_amount') ? 'tax_amount' : null,
                ]));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('pos_cashier_invoice_items')) {
            Schema::table('pos_cashier_invoice_items', function (Blueprint $table): void {
                $drop = array_values(array_filter([
                    Schema::hasColumn('pos_cashier_invoice_items', 'taxable_amount') ? 'taxable_amount' : null,
                    Schema::hasColumn('pos_cashier_invoice_items', 'tax_rate') ? 'tax_rate' : null,
                    Schema::hasColumn('pos_cashier_invoice_items', 'tax_amount') ? 'tax_amount' : null,
                ]));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table): void {
                $drop = array_values(array_filter([
                    Schema::hasColumn('customers', 'building_number') ? 'building_number' : null,
                    Schema::hasColumn('customers', 'street') ? 'street' : null,
                    Schema::hasColumn('customers', 'district') ? 'district' : null,
                    Schema::hasColumn('customers', 'city') ? 'city' : null,
                    Schema::hasColumn('customers', 'postal_code') ? 'postal_code' : null,
                    Schema::hasColumn('customers', 'country_code') ? 'country_code' : null,
                    Schema::hasColumn('customers', 'additional_number') ? 'additional_number' : null,
                ]));
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
