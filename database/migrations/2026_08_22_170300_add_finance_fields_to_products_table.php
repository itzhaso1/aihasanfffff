<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('cost_price', 12, 2)->nullable()->after('sale_price');
            $table->decimal('vat_rate', 5, 2)->default(15)->after('cost_price');
            $table->string('barcode')->nullable()->after('sku');
            $table->boolean('inventory_tracking')->default(true)->after('stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'cost_price',
                'vat_rate',
                'barcode',
                'inventory_tracking',
            ]);
        });
    }
};
