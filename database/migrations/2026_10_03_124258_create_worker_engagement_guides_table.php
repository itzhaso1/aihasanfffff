<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_engagement_guides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
            $table->foreignId('worker_engagement_id')
                ->constrained('worker_engagements')
                ->cascadeOnDelete();
            $table->text('company_overview')->nullable();
            $table->text('products_context')->nullable();
            $table->json('responsibilities')->nullable();
            $table->json('workflow_steps')->nullable();
            $table->json('important_rules')->nullable();
            $table->json('customer_guidance')->nullable();
            $table->json('escalation_rules')->nullable();
            $table->text('work_schedule_notes')->nullable();
            $table->text('important_notes')->nullable();
            $table->json('faq_items')->nullable();
            $table->timestamps();

            $table->unique(
                ['worker_engagement_id'],
                'worker_engagement_guides_engagement_unique',
            );
            $table->index(
                ['workspace_id', 'worker_engagement_id'],
                'worker_engagement_guides_workspace_engagement_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_engagement_guides');
    }
};
