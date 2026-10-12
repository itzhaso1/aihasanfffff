<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'order_type')) {
                $table->string('order_type', 32)->nullable()->after('source');
            }
            if (! Schema::hasColumn('orders', 'client_reference')) {
                $table->string('client_reference', 120)->nullable()->after('order_number');
            }
            if (! Schema::hasColumn('orders', 'tax_amount')) {
                $table->decimal('tax_amount', 12, 2)->default(0)->after('discount_amount');
            }
        });

        // Unique client_reference per workspace (NULLs allowed / ignored by engines).
        Schema::table('orders', function (Blueprint $table): void {
            $table->unique(['workspace_id', 'client_reference'], 'orders_workspace_client_reference_uidx');
            $table->index(['workspace_id', 'order_type'], 'orders_workspace_order_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_workspace_client_reference_uidx');
            $table->dropIndex('orders_workspace_order_type_idx');
            $drop = [];
            foreach (['order_type', 'client_reference', 'tax_amount'] as $column) {
                if (Schema::hasColumn('orders', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
