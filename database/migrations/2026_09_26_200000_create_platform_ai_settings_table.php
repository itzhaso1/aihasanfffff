<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->string('fallback_provider', 64)->nullable();
            $table->string('fallback_model', 128)->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_ai_settings');
    }
};
