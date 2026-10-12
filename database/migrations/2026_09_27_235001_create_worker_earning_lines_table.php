<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_earning_lines', function (Blueprint $table): void {
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
            $table->string('line_type', 32);
            $table->string('source_type', 64);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference', 191)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('description', 500)->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'worker_engagement_id'], 'wel_workspace_engagement_idx');
            $table->index(['worker_earning_period_id', 'line_type'], 'wel_period_type_idx');
            $table->index(['worker_engagement_id', 'created_at'], 'wel_engagement_created_idx');
            $table->index(['source_type', 'source_id'], 'wel_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_earning_lines');
    }
};
