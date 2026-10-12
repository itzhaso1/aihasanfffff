<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_earning_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->foreignId('worker_earning_period_id')
                ->constrained('worker_earning_periods')
                ->cascadeOnDelete();
            $table->foreignId('approved_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->dateTime('approved_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'worker_engagement_id'], 'wea_workspace_engagement_idx');
            $table->index(['worker_earning_period_id', 'approved_at'], 'wea_period_approved_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_earning_approvals');
    }
};
