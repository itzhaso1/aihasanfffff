<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_outbound_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->enum('status', ['queued', 'sending', 'sent', 'failed'])->default('queued');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider_message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'message_id'], 'channel_outbound_deliveries_tenant_message_unique');
            $table->index(['workspace_id', 'channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_outbound_deliveries');
    }
};
