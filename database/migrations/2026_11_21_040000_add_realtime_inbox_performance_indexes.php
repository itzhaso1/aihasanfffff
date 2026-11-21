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
        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasIndex('messages', 'idx_messages_conv_dir_id')) {
                $table->index(['conversation_id', 'direction', 'id'], 'idx_messages_conv_dir_id');
            }
        });

        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasIndex('conversations', 'idx_conv_ws_handoff_status')) {
                $table->index(['workspace_id', 'handoff_status', 'status'], 'idx_conv_ws_handoff_status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasIndex('messages', 'idx_messages_conv_dir_id')) {
                $table->dropIndex('idx_messages_conv_dir_id');
            }
        });

        Schema::table('conversations', function (Blueprint $table) {
            if (Schema::hasIndex('conversations', 'idx_conv_ws_handoff_status')) {
                $table->dropIndex('idx_conv_ws_handoff_status');
            }
        });
    }
};
