<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_ai_conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_ai_conversations', 'last_turn_seq')) {
                $table->unsignedInteger('last_turn_seq')->default(0)->after('last_message_at');
            }
        });

        Schema::table('finance_ai_messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('finance_ai_messages', 'turn_seq')) {
                $table->unsignedInteger('turn_seq')->default(0)->after('conversation_id');
            }
        });

        $this->backfillTurnSeq();

        Schema::table('finance_ai_messages', function (Blueprint $table): void {
            $table->unique(
                ['workspace_id', 'conversation_id', 'turn_seq', 'role'],
                'finance_ai_messages_workspace_turn_role_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('finance_ai_messages', function (Blueprint $table): void {
            $table->dropUnique('finance_ai_messages_workspace_turn_role_unique');
        });

        Schema::table('finance_ai_messages', function (Blueprint $table): void {
            if (Schema::hasColumn('finance_ai_messages', 'turn_seq')) {
                $table->dropColumn('turn_seq');
            }
        });

        Schema::table('finance_ai_conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('finance_ai_conversations', 'last_turn_seq')) {
                $table->dropColumn('last_turn_seq');
            }
        });
    }

    private function backfillTurnSeq(): void
    {
        $conversationIds = DB::table('finance_ai_conversations')->orderBy('id')->pluck('id');
        foreach ($conversationIds as $conversationId) {
            $seq = 0;
            $messages = DB::table('finance_ai_messages')
                ->where('conversation_id', $conversationId)
                ->orderBy('id')
                ->get(['id', 'role']);

            $pendingAssistant = false;
            foreach ($messages as $message) {
                if ($message->role === 'user' || ! $pendingAssistant) {
                    $seq++;
                    $pendingAssistant = $message->role === 'user';
                } else {
                    $pendingAssistant = false;
                }

                DB::table('finance_ai_messages')->where('id', $message->id)->update(['turn_seq' => $seq]);
            }

            DB::table('finance_ai_conversations')->where('id', $conversationId)->update(['last_turn_seq' => $seq]);
        }
    }
};
