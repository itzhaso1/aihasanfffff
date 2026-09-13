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
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('vat_number')->nullable()->after('email');
            $table->string('commercial_registration')->nullable()->after('vat_number');
            $table->text('address')->nullable()->after('commercial_registration');
            $table->string('payment_terms')->nullable()->after('address');
            $table->decimal('balance', 14, 2)->default(0)->after('payment_terms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'vat_number',
                'commercial_registration',
                'address',
                'payment_terms',
                'balance',
            ]);
        });
    }
};
