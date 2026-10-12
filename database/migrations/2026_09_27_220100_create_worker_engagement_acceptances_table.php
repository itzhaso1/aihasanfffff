<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_engagement_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('party', 16);
            $table->string('decision', 16);
            $table->string('terms_version', 64)->default('v1');
            $table->dateTime('accepted_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['worker_engagement_id', 'party', 'decision'], 'wea_engagement_party_decision_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_engagement_acceptances');
    }
};
