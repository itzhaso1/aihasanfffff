<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_menu_items', 'sku')) {
                $table->string('sku', 64)->nullable()->after('name');
            }
            if (! Schema::hasColumn('pos_menu_items', 'barcode')) {
                $table->string('barcode', 64)->nullable()->after('sku');
            }
        });

        Schema::table('pos_menu_items', function (Blueprint $table): void {
            $table->index(['workspace_id', 'barcode'], 'pos_menu_items_workspace_barcode_idx');
            $table->index(['workspace_id', 'sku'], 'pos_menu_items_workspace_sku_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table): void {
            $table->dropIndex('pos_menu_items_workspace_barcode_idx');
            $table->dropIndex('pos_menu_items_workspace_sku_idx');
            $drop = [];
            foreach (['sku', 'barcode'] as $column) {
                if (Schema::hasColumn('pos_menu_items', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
