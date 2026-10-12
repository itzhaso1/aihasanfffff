<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_payout_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_profile_id')
                ->constrained('worker_profiles')
                ->cascadeOnDelete();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->foreignId('worker_earning_period_id')
                ->constrained('worker_earning_periods')
                ->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 32)->default('pending');
            $table->string('provider_reference', 191)->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('created_by_platform_admin_id')
                ->nullable()
                ->constrained('platform_admins')
                ->nullOnDelete();
            $table->dateTime('requested_at');
            $table->dateTime('processing_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status'], 'wpr_workspace_status_idx');
            $table->index(['worker_profile_id', 'worker_engagement_id'], 'wpr_profile_engagement_idx');
            $table->index(['worker_engagement_id', 'status'], 'wpr_engagement_status_idx');
            $table->index(['worker_earning_period_id', 'status'], 'wpr_period_status_idx');
            $table->index(['status', 'requested_at'], 'wpr_status_requested_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_payout_records');
    }
};
