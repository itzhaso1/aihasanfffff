<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            if (! Schema::hasColumn('products', 'product_kind')) {
                $table->string('product_kind', 20)->default('physical')->after('status')->index();
            }
            if (! Schema::hasColumn('products', 'digital_type')) {
                $table->string('digital_type', 32)->nullable()->after('product_kind');
            }
            if (! Schema::hasColumn('products', 'download_limit')) {
                $table->unsignedInteger('download_limit')->nullable()->after('digital_type');
            }
            if (! Schema::hasColumn('products', 'digital_asset_disk')) {
                $table->string('digital_asset_disk', 64)->nullable()->after('download_limit');
            }
            if (! Schema::hasColumn('products', 'digital_asset_path')) {
                $table->string('digital_asset_path')->nullable()->after('digital_asset_disk');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            foreach (['digital_asset_path', 'digital_asset_disk', 'download_limit', 'digital_type', 'product_kind'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
