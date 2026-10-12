<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table): void {
            $table->foreignId('pos_item_category_id')
                ->nullable()
                ->after('workspace_id')
                ->constrained('pos_item_categories')
                ->nullOnDelete();
            $table->string('size_label', 100)->nullable()->after('item_type');
            $table->text('description')->nullable()->after('size_label');

            $table->index(['workspace_id', 'pos_item_category_id'], 'pos_menu_items_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pos_menu_items', function (Blueprint $table): void {
            $table->dropIndex('pos_menu_items_category_idx');
            $table->dropConstrainedForeignId('pos_item_category_id');
            $table->dropColumn(['size_label', 'description']);
        });
    }
};
