<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('display_name', 255);
            $table->string('profile_photo')->nullable();
            $table->string('country_code', 2);
            $table->string('nationality', 100);
            $table->string('verification_status', 32)->default('not_requested')->index();
            $table->string('lifecycle_status', 32)->default('registered')->index();
            $table->string('availability_status', 32)->default('available')->index();
            $table->text('professional_summary')->nullable();
            $table->string('sales_experience_level', 32)->nullable();
            $table->timestamps();

            $table->index(['country_code', 'lifecycle_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_profiles');
    }
};
