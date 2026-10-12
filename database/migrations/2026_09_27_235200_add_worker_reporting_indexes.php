<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            $table->index(
                ['workspace_id', 'assigned_worker_engagement_id', 'status'],
                'conversations_worker_reporting_index',
            );
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->index(
                ['workspace_id', 'worker_engagement_id', 'direction', 'created_at'],
                'messages_worker_reporting_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_worker_reporting_index');
        });

        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex('conversations_worker_reporting_index');
        });
    }
};
