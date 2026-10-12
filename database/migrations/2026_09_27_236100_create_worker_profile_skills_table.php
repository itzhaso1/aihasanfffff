<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_profile_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')
                ->constrained('worker_profiles')
                ->cascadeOnDelete();
            $table->foreignId('worker_skill_id')
                ->constrained('worker_skills')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['worker_profile_id', 'worker_skill_id'], 'wps_profile_skill_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_profile_skills');
    }
};
