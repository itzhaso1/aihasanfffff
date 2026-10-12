<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_profile_languages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')
                ->constrained('worker_profiles')
                ->cascadeOnDelete();
            $table->string('language_code', 8);
            $table->timestamps();

            $table->unique(['worker_profile_id', 'language_code'], 'wpl_profile_language_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_profile_languages');
    }
};
