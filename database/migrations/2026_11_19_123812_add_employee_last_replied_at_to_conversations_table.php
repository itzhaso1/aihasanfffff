<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'employee_last_replied_at')) {
                $table->timestamp('employee_last_replied_at')
                    ->nullable()
                    ->after('last_message_at')
                    ->comment('Last time a human employee sent an outbound message in this conversation');
            }

            // Index to efficiently find idle handoff conversations
            $table->index(
                ['handoff_status', 'employee_last_replied_at'],
                'conversations_idle_handoff_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_idle_handoff_index');
            $table->dropColumn('employee_last_replied_at');
        });
    }
};
