<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_activity_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->foreignId('worker_profile_id')
                ->constrained('worker_profiles')
                ->cascadeOnDelete();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('worker_work_session_id')
                ->nullable()
                ->constrained('worker_work_sessions')
                ->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('subject_type', 191)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->dateTime('occurred_at');
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'occurred_at'], 'wae_workspace_occurred_idx');
            $table->index(['worker_engagement_id', 'occurred_at'], 'wae_engagement_occurred_idx');
            $table->index(['worker_work_session_id', 'occurred_at'], 'wae_session_occurred_idx');
            $table->index(['worker_profile_id', 'worker_engagement_id'], 'wae_profile_engagement_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_activity_events');
    }
};
