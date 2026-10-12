<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_contract_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')
                ->constrained('worker_profiles')
                ->cascadeOnDelete();
            $table->unsignedInteger('duration_value');
            $table->string('duration_unit', 16);
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->dateTime('effective_from')->nullable();
            $table->dateTime('effective_until')->nullable();
            $table->foreignId('created_by_platform_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(
                ['worker_profile_id', 'duration_value', 'duration_unit', 'is_active'],
                'worker_contract_rates_lookup_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_contract_rates');
    }
};
