<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_identity_private', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')->unique()->constrained('worker_profiles')->cascadeOnDelete();
            $table->text('legal_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('identity_number')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_identity_private');
    }
};
