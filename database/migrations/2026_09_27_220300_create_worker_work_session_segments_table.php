<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_work_session_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_work_session_id')
                ->constrained('worker_work_sessions')
                ->cascadeOnDelete();
            $table->string('segment_type', 32);
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->index(['worker_work_session_id', 'started_at'], 'wwss_session_started_idx');
            $table->index(['worker_work_session_id', 'ended_at'], 'wwss_session_ended_idx');
            $table->index(['workspace_id', 'started_at'], 'wwss_workspace_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_work_session_segments');
    }
};
