<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker_engagements', function (Blueprint $table): void {
            $table->unsignedBigInteger('offered_contract_amount_minor')->nullable();
            $table->string('offered_contract_currency', 3)->nullable();
            $table->foreignId('offered_contract_rate_id')
                ->nullable()
                ->constrained('worker_contract_rates')
                ->nullOnDelete();
            $table->unsignedBigInteger('agreed_contract_amount_minor')->nullable();
            $table->string('agreed_contract_currency', 3)->nullable();
            $table->dateTime('agreed_contract_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('worker_engagements', function (Blueprint $table): void {
            $table->dropForeign(['offered_contract_rate_id']);
            $table->dropColumn([
                'offered_contract_amount_minor',
                'offered_contract_currency',
                'offered_contract_rate_id',
                'agreed_contract_amount_minor',
                'agreed_contract_currency',
                'agreed_contract_at',
            ]);
        });
    }
};
