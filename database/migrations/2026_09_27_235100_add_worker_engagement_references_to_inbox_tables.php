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
        Schema::table('conversations', function (Blueprint $table): void {
            $table->foreignId('assigned_worker_engagement_id')
                ->nullable()
                ->after('assigned_user_id')
                ->constrained('worker_engagements')
                ->restrictOnDelete();
            $table->index(
                ['workspace_id', 'assigned_user_id', 'assigned_worker_engagement_id'],
                'conversations_worker_assignment_index',
            );
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->foreignId('worker_engagement_id')
                ->nullable()
                ->after('user_id')
                ->constrained('worker_engagements')
                ->restrictOnDelete();
            $table->index(
                ['workspace_id', 'conversation_id', 'worker_engagement_id'],
                'messages_worker_engagement_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_worker_engagement_index');
            $table->dropConstrainedForeignId('worker_engagement_id');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_worker_assignment_index');
            $table->dropConstrainedForeignId('assigned_worker_engagement_id');
        });
    }
};
