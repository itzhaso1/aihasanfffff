<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')->constrained('worker_profiles')->cascadeOnDelete();
            $table->string('status', 32)->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['worker_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_verifications');
    }
};
