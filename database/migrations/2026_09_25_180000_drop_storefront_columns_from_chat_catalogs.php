<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_catalogs')) {
            return;
        }

        Schema::table('chat_catalogs', function (Blueprint $table): void {
            foreach (['storefront_theme', 'storefront_layout', 'storefront_preset'] as $column) {
                if (Schema::hasColumn('chat_catalogs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('chat_catalogs')) {
            return;
        }

        Schema::table('chat_catalogs', function (Blueprint $table): void {
            if (! Schema::hasColumn('chat_catalogs', 'storefront_preset')) {
                $table->string('storefront_preset', 64)->nullable();
            }
            if (! Schema::hasColumn('chat_catalogs', 'storefront_layout')) {
                $table->json('storefront_layout')->nullable();
            }
            if (! Schema::hasColumn('chat_catalogs', 'storefront_theme')) {
                $table->json('storefront_theme')->nullable();
            }
        });
    }
};
