<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_earning_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->string('status', 32)->default('draft');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['worker_engagement_id', 'starts_at', 'ends_at'], 'wep_engagement_range_unique');
            $table->index(['workspace_id', 'status'], 'wep_workspace_status_idx');
            $table->index(['worker_engagement_id', 'status'], 'wep_engagement_status_idx');
            $table->index(['worker_engagement_id', 'starts_at', 'ends_at'], 'wep_engagement_range_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_earning_periods');
    }
};
