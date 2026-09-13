<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_document_deliveries')) {
            return;
        }

        Schema::create('finance_document_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->unsignedBigInteger('document_id');
            $table->string('channel', 16)->default('email');
            $table->string('recipient');
            $table->string('recipient_phone', 32)->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 16)->default('sending');
            $table->string('provider_message_id')->nullable();
            $table->foreignId('email_log_id')->nullable()->constrained('email_logs')->nullOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('attachment_disk', 32)->nullable();
            $table->string('attachment_path')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'document_type', 'document_id'], 'finance_deliveries_document_idx');
            $table->index(['workspace_id', 'channel', 'status'], 'finance_deliveries_channel_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_document_deliveries');
    }
};
