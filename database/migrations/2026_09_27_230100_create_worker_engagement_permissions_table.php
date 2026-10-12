<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_engagement_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->string('capability_key', 120);
            $table->boolean('allowed')->default(false);
            $table->timestamps();

            $table->unique(['worker_engagement_id', 'capability_key'], 'wep_engagement_capability_unique');
            $table->index(['worker_engagement_id', 'allowed'], 'wep_engagement_allowed_idx');
            $table->foreign('capability_key')
                ->references('capability_key')
                ->on('workforce_capability_definitions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_engagement_permissions');
    }
};
