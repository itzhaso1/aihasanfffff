<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('conversations')) {
            Schema::table('conversations', function (Blueprint $table): void {
                if (! Schema::hasColumn('conversations', 'handoff_version')) {
                    $table->unsignedBigInteger('handoff_version')->default(1)->after('handoff_resolved_at');
                }

                if (! Schema::hasIndex('conversations', 'idx_conv_ws_status_lastmsg')) {
                    $table->index(['workspace_id', 'status', 'last_message_at', 'id'], 'idx_conv_ws_status_lastmsg');
                }

                if (! Schema::hasIndex('conversations', 'idx_conv_ws_worker_eng')) {
                    $table->index(['workspace_id', 'assigned_worker_engagement_id', 'status'], 'idx_conv_ws_worker_eng');
                }
            });
        }

        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table): void {
                if (! Schema::hasIndex('messages', 'idx_msg_ws_conv_created')) {
                    $table->index(['workspace_id', 'conversation_id', 'created_at'], 'idx_msg_ws_conv_created');
                }

                if (! Schema::hasIndex('messages', 'idx_msg_ws_conv_ai_dir')) {
                    $table->index(['workspace_id', 'conversation_id', 'ai_generated', 'direction', 'id'], 'idx_msg_ws_conv_ai_dir');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table): void {
                if (Schema::hasIndex('messages', 'idx_msg_ws_conv_ai_dir')) {
                    $table->dropIndex('idx_msg_ws_conv_ai_dir');
                }

                if (Schema::hasIndex('messages', 'idx_msg_ws_conv_created')) {
                    $table->dropIndex('idx_msg_ws_conv_created');
                }
            });
        }

        if (Schema::hasTable('conversations')) {
            Schema::table('conversations', function (Blueprint $table): void {
                if (Schema::hasIndex('conversations', 'idx_conv_ws_worker_eng')) {
                    $table->dropIndex('idx_conv_ws_worker_eng');
                }

                if (Schema::hasIndex('conversations', 'idx_conv_ws_status_lastmsg')) {
                    $table->dropIndex('idx_conv_ws_status_lastmsg');
                }

                if (Schema::hasColumn('conversations', 'handoff_version')) {
                    $table->dropColumn('handoff_version');
                }
            });
        }
    }
};
