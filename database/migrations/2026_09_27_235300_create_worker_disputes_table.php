<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_disputes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->string('reporter_type', 32);
            $table->foreignId('reported_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('reported_by_platform_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->string('category', 64);
            $table->text('description');
            $table->string('status', 32)->default('open');
            $table->foreignId('reviewer_platform_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->dateTime('opened_at');
            $table->dateTime('under_review_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'created_at'], 'wd_workspace_status_created_idx');
            $table->index(['worker_engagement_id', 'status', 'created_at'], 'wd_engagement_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_disputes');
    }
};
