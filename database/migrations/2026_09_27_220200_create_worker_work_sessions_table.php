<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_work_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->string('status', 32)->default('started');
            $table->string('active_session_key', 64)->nullable()->unique();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->foreignId('started_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('ended_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['worker_engagement_id', 'status']);
            $table->index(['worker_engagement_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_work_sessions');
    }
};
