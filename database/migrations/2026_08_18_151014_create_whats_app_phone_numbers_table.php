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
        Schema::create('whats_app_phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_app_account_id')->constrained()->cascadeOnDelete();
            $table->string('phone_number_id')->unique();
            $table->string('display_phone_number');
            $table->string('verified_name')->nullable();
            $table->enum('status', ['connected', 'pending', 'disconnected'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whats_app_phone_numbers');
    }
};
