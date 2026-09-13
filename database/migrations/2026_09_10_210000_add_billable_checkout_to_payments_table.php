<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders')->nullOnDelete();

            if (! Schema::hasColumn('payments', 'billable_type')) {
                $table->string('billable_type', 64)->nullable()->after('order_id');
            }
            if (! Schema::hasColumn('payments', 'billable_id')) {
                $table->unsignedBigInteger('billable_id')->nullable()->after('billable_type');
            }
            if (! Schema::hasColumn('payments', 'checkout_reference')) {
                $table->string('checkout_reference', 128)->nullable()->after('billable_id');
            }
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['workspace_id', 'billable_type', 'billable_id'], 'payments_ws_billable_idx');
            $table->index(['workspace_id', 'checkout_reference'], 'payments_ws_checkout_ref_idx');
            $table->index(['provider', 'checkout_reference'], 'payments_provider_checkout_ref_idx');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex('payments_ws_billable_idx');
            $table->dropIndex('payments_ws_checkout_ref_idx');
            $table->dropIndex('payments_provider_checkout_ref_idx');
        });

        Schema::table('payments', function (Blueprint $table): void {
            if (Schema::hasColumn('payments', 'checkout_reference')) {
                $table->dropColumn('checkout_reference');
            }
            if (Schema::hasColumn('payments', 'billable_id')) {
                $table->dropColumn('billable_id');
            }
            if (Schema::hasColumn('payments', 'billable_type')) {
                $table->dropColumn('billable_type');
            }
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['order_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('order_id')->nullable(false)->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
    }
};
