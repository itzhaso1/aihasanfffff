<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_agent_memories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 32);
            $table->string('key', 64);
            $table->json('value');
            $table->string('value_type', 16);
            $table->string('status', 16)->default('active');
            $table->decimal('confidence', 4, 3);
            $table->string('evidence_type', 16)->default('explicit');
            $table->foreignId('source_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('source_conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_confirmed_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'customer_id', 'scope', 'status'], 'inbox_mem_ws_customer_scope_status');
            $table->index(['workspace_id', 'conversation_id', 'scope', 'status'], 'inbox_mem_ws_conv_scope_status');
            $table->index(['workspace_id', 'scope', 'status'], 'inbox_mem_ws_scope_status');
            $table->index(['workspace_id', 'key', 'status'], 'inbox_mem_ws_key_status');
            $table->unique(
                ['workspace_id', 'source_message_id', 'scope', 'key'],
                'inbox_mem_ws_source_scope_key'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_agent_memories');
    }
};
