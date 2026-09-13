<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_attachments')) {
            Schema::table('email_attachments', function (Blueprint $table): void {
                if (! Schema::hasColumn('email_attachments', 'workspace_id')) {
                    $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->nullOnDelete();
                    $table->index(['workspace_id', 'message_id']);
                }
                if (! Schema::hasColumn('email_attachments', 'disk')) {
                    $table->string('disk', 32)->default('public')->after('file_path');
                }
            });

            if (Schema::hasColumn('email_attachments', 'workspace_id')) {
                DB::table('email_attachments')
                    ->whereNull('workspace_id')
                    ->orderBy('id')
                    ->chunkById(200, function ($rows): void {
                        foreach ($rows as $row) {
                            $workspaceId = DB::table('email_messages')
                                ->where('id', $row->message_id)
                                ->value('workspace_id');
                            if ($workspaceId === null) {
                                continue;
                            }
                            DB::table('email_attachments')
                                ->where('id', $row->id)
                                ->whereNull('workspace_id')
                                ->update(['workspace_id' => $workspaceId]);
                        }
                    });
            }
        }

        if (Schema::hasTable('email_campaign_recipients') && ! Schema::hasColumn('email_campaign_recipients', 'workspace_id')) {
            Schema::table('email_campaign_recipients', function (Blueprint $table): void {
                $table->foreignId('workspace_id')->nullable()->after('id')->constrained()->nullOnDelete();
                $table->index(['workspace_id', 'email_campaign_id']);
            });

            DB::table('email_campaign_recipients')
                ->whereNull('workspace_id')
                ->orderBy('id')
                ->chunkById(200, function ($rows): void {
                    foreach ($rows as $row) {
                        $workspaceId = DB::table('email_campaigns')
                            ->where('id', $row->email_campaign_id)
                            ->value('workspace_id');
                        if ($workspaceId === null) {
                            continue;
                        }
                        DB::table('email_campaign_recipients')
                            ->where('id', $row->id)
                            ->whereNull('workspace_id')
                            ->update(['workspace_id' => $workspaceId]);
                    }
                });
        }

        if (Schema::hasTable('device_push_tokens')) {
            $this->dropDeviceTokenUserUnique();

            Schema::table('device_push_tokens', function (Blueprint $table): void {
                $table->unique(['user_id', 'token', 'workspace_id'], 'device_push_tokens_user_token_workspace_unique');
                $table->index(['workspace_id', 'user_id', 'revoked_at'], 'device_push_tokens_workspace_user_revoked_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('device_push_tokens')) {
            Schema::table('device_push_tokens', function (Blueprint $table): void {
                $table->dropUnique('device_push_tokens_user_token_workspace_unique');
                $table->dropIndex('device_push_tokens_workspace_user_revoked_index');
                $table->unique(['user_id', 'token']);
            });
        }

        if (Schema::hasTable('email_campaign_recipients') && Schema::hasColumn('email_campaign_recipients', 'workspace_id')) {
            Schema::table('email_campaign_recipients', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('workspace_id');
            });
        }

        if (Schema::hasTable('email_attachments')) {
            Schema::table('email_attachments', function (Blueprint $table): void {
                if (Schema::hasColumn('email_attachments', 'workspace_id')) {
                    $table->dropConstrainedForeignId('workspace_id');
                }
                if (Schema::hasColumn('email_attachments', 'disk')) {
                    $table->dropColumn('disk');
                }
            });
        }
    }

    private function dropDeviceTokenUserUnique(): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('device_push_tokens', function (Blueprint $table): void {
                $table->dropUnique(['user_id', 'token']);
            });

            return;
        }

        $sm = $connection->getSchemaBuilder();
        $indexes = $sm->getIndexes('device_push_tokens');
        foreach ($indexes as $index) {
            $columns = $index['columns'] ?? [];
            if (($index['unique'] ?? false) && $columns === ['user_id', 'token']) {
                Schema::table('device_push_tokens', function (Blueprint $table) use ($index): void {
                    $table->dropUnique($index['name']);
                });

                return;
            }
        }

        Schema::table('device_push_tokens', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'token']);
        });
    }
};
