<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->foreignId('pos_menu_item_id')->nullable()->after('product_variant_id')->constrained('pos_menu_items')->nullOnDelete();
            $table->string('item_type', 100)->nullable()->after('variant_name');

            $table->index(['workspace_id', 'pos_menu_item_id'], 'order_items_pos_menu_item_idx');
            $table->index(['workspace_id', 'item_type'], 'order_items_item_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropIndex('order_items_pos_menu_item_idx');
            $table->dropIndex('order_items_item_type_idx');
            $table->dropConstrainedForeignId('pos_menu_item_id');
            $table->dropColumn('item_type');
        });
    }
};
