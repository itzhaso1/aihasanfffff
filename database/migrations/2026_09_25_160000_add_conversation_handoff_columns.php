<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->string('handoff_status', 32)->default('none')->after('ai_enabled');
            $table->string('handoff_reason', 64)->nullable()->after('handoff_status');
            $table->timestamp('handoff_requested_at')->nullable()->after('handoff_reason');
            $table->timestamp('handoff_assigned_at')->nullable()->after('handoff_requested_at');
            $table->timestamp('handoff_resolved_at')->nullable()->after('handoff_assigned_at');
            $table->foreignId('assigned_user_id')->nullable()->after('handoff_resolved_at')->constrained('users')->nullOnDelete();

            $table->index(['workspace_id', 'handoff_status', 'last_message_at'], 'conversations_handoff_status_index');
            $table->index(['workspace_id', 'assigned_user_id'], 'conversations_assigned_user_index');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_handoff_status_index');
            $table->dropIndex('conversations_assigned_user_index');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn([
                'handoff_status',
                'handoff_reason',
                'handoff_requested_at',
                'handoff_assigned_at',
                'handoff_resolved_at',
            ]);
        });
    }
};
