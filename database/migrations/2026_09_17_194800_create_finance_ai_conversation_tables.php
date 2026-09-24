<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('active');
            $table->string('title', 120)->nullable();
            $table->string('locale', 8)->default('ar');
            $table->date('last_period_from')->nullable();
            $table->date('last_period_to')->nullable();
            $table->json('last_metric_names')->nullable();
            $table->string('gemini_interaction_id', 191)->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'id'], 'finance_ai_conversations_workspace_id_id_unique');
            $table->index(['workspace_id', 'gemini_interaction_id'], 'finance_ai_conversations_workspace_interaction_index');
            $table->index(['workspace_id', 'user_id', 'status', 'updated_at'], 'finance_ai_conversations_owner_status_index');
        });

        Schema::create('finance_ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 16);
            $table->text('body');
            $table->string('locale', 8)->default('ar');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->json('facts_safe')->nullable();
            $table->json('tools_used')->nullable();
            $table->timestamps();

            $table->foreign(['workspace_id', 'conversation_id'], 'finance_ai_messages_workspace_conversation_foreign')
                ->references(['workspace_id', 'id'])
                ->on('finance_ai_conversations')
                ->cascadeOnDelete();
            $table->unique(['workspace_id', 'id'], 'finance_ai_messages_workspace_id_id_unique');
            $table->index(['workspace_id', 'conversation_id', 'id'], 'finance_ai_messages_workspace_conversation_id_index');
            $table->index(['conversation_id', 'created_at'], 'finance_ai_messages_conversation_created_index');
        });

        Schema::create('finance_ai_tool_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('message_id');
            $table->string('tool_name', 64);
            $table->unsignedTinyInteger('round')->default(1);
            $table->json('arguments_safe')->nullable();
            $table->json('result_safe')->nullable();
            $table->string('permission_result', 32)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('success')->default(false);
            $table->timestamps();

            $table->foreign(['workspace_id', 'conversation_id'], 'finance_ai_tool_calls_workspace_conversation_foreign')
                ->references(['workspace_id', 'id'])
                ->on('finance_ai_conversations')
                ->cascadeOnDelete();
            $table->foreign(['workspace_id', 'message_id'], 'finance_ai_tool_calls_workspace_message_foreign')
                ->references(['workspace_id', 'id'])
                ->on('finance_ai_messages')
                ->cascadeOnDelete();
            $table->index(['workspace_id', 'conversation_id', 'message_id'], 'finance_ai_tool_calls_workspace_conversation_message_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_ai_tool_calls');
        Schema::dropIfExists('finance_ai_messages');
        Schema::dropIfExists('finance_ai_conversations');
    }
};
