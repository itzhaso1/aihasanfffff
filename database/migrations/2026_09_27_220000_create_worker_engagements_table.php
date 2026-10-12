<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_engagements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')
                ->constrained('worker_profiles')
                ->cascadeOnDelete();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->string('job_title', 255)->nullable();
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->json('compensation_terms_json')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('offered_at')->nullable();
            $table->dateTime('accepted_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->dateTime('active_at')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('terminated_at')->nullable();
            $table->text('termination_reason')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
            $table->index(['worker_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_engagements');
    }
};
