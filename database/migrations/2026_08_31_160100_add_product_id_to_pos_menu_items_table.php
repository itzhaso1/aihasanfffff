<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('pos_menu_items', 'product_id')) {
                $table->foreignId('product_id')
                    ->nullable()
                    ->after('pos_item_category_id')
                    ->constrained('products')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table): void {
            if (Schema::hasColumn('pos_menu_items', 'product_id')) {
                $table->dropConstrainedForeignId('product_id');
            }
        });
    }
};
