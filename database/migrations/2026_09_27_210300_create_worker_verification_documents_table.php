<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_verification_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('worker_profile_id')->constrained('worker_profiles')->cascadeOnDelete();
            $table->foreignId('worker_verification_id')->nullable()->constrained('worker_verifications')->nullOnDelete();
            $table->string('document_type_code', 64);
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('status', 32)->default('submitted')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['worker_profile_id', 'status'], 'wv_docs_profile_status_idx');
            $table->index(['worker_verification_id', 'status'], 'wv_docs_verification_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_verification_documents');
    }
};
