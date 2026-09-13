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
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('AI Assistant');
            $table->text('instructions')->nullable();
            $table->string('tone')->nullable();
            $table->string('reply_style')->nullable();
            $table->json('rules')->nullable();
            $table->json('business_information')->nullable();
            $table->string('provider')->default('openai');
            $table->string('model')->default('gpt-4o-mini');
            $table->unsignedInteger('max_tokens')->default(512);
            $table->decimal('temperature', 3, 2)->default(0.4);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
