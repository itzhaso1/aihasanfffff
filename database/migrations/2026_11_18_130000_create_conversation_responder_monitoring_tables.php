<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'responder_type')) {
                $table->string('responder_type', 32)->default('ai')->after('handoff_status');
            }
            if (! Schema::hasColumn('conversations', 'responder_name')) {
                $table->string('responder_name', 120)->nullable()->after('responder_type');
            }
            if (! Schema::hasColumn('conversations', 'responder_assigned_at')) {
                $table->timestamp('responder_assigned_at')->nullable()->after('responder_name');
            }

            $table->index(['workspace_id', 'responder_type'], 'conversations_responder_type_index');
        });

        Schema::create('conversation_responder_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('previous_responder_type', 32)->nullable();
            $table->foreignId('previous_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('previous_responder_name', 120)->nullable();
            $table->string('new_responder_type', 32);
            $table->foreignId('new_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('new_responder_name', 120);
            $table->string('action', 64);
            $table->string('actor_type', 32)->default('system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'conversation_id', 'created_at'], 'conv_resp_logs_lookup_index');
            $table->index(['workspace_id', 'new_user_id'], 'conv_resp_logs_user_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_responder_logs');

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_responder_type_index');
            $table->dropColumn([
                'responder_type',
                'responder_name',
                'responder_assigned_at',
            ]);
        });
    }
};
