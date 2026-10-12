<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_verification_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')->constrained('worker_profiles')->cascadeOnDelete();
            $table->foreignId('worker_verification_id')->nullable()->constrained('worker_verifications')->nullOnDelete();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('field_key', 128)->nullable();
            $table->text('reason');
            $table->string('status', 32)->default('open')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['worker_profile_id', 'status'], 'worker_verification_issues_profile_status_idx');
            $table->index(['worker_verification_id', 'status'], 'worker_verification_issues_verification_status_idx');
            $table->index(
                ['worker_profile_id', 'target_type', 'target_id', 'field_key', 'status'],
                'worker_verification_issues_target_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_verification_issues');
    }
};
