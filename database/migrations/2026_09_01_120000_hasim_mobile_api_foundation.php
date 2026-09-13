<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table): void {
                if (! Schema::hasColumn('personal_access_tokens', 'device_name')) {
                    $table->string('device_name')->nullable()->after('name');
                }
                if (! Schema::hasColumn('personal_access_tokens', 'device_type')) {
                    $table->string('device_type', 32)->nullable()->after('device_name');
                }
                if (! Schema::hasColumn('personal_access_tokens', 'user_agent')) {
                    $table->string('user_agent', 512)->nullable()->after('device_type');
                }
                if (! Schema::hasColumn('personal_access_tokens', 'ip_address')) {
                    $table->string('ip_address', 45)->nullable()->after('user_agent');
                }
            });
        }

        if (! Schema::hasTable('conversation_user_states')) {
            Schema::create('conversation_user_states', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('last_read_message_id')->nullable()->index();
                $table->timestamp('last_read_at')->nullable();
                $table->timestamp('muted_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                $table->unique(['conversation_id', 'user_id']);
                $table->index(['workspace_id', 'user_id', 'archived_at']);
            });
        }

        if (! Schema::hasTable('message_attachments')) {
            Schema::create('message_attachments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('message_id')->constrained()->cascadeOnDelete();
                $table->string('disk', 32)->default('local');
                $table->string('path');
                $table->string('original_name');
                $table->string('mime_type', 128)->nullable();
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('kind', 32)->default('file'); // image|file|audio|video
                $table->string('thumbnail_path')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'message_id']);
            });
        }

        if (! Schema::hasTable('device_push_tokens')) {
            Schema::create('device_push_tokens', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
                $table->string('provider', 32)->default('fcm'); // fcm|apns
                $table->string('token', 512);
                $table->string('platform', 32)->nullable(); // ios|android|web
                $table->string('device_name')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'token']);
                $table->index(['user_id', 'revoked_at']);
            });
        }

        if (! Schema::hasTable('api_idempotency_keys')) {
            Schema::create('api_idempotency_keys', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->string('key', 128);
                $table->string('route', 191);
                $table->unsignedSmallInteger('status_code');
                $table->json('response_body');
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->unique(['user_id', 'key', 'route']);
            });
        }

        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('messages')->default(true);
                $table->boolean('bookings')->default(true);
                $table->boolean('email')->default(true);
                $table->boolean('marketing')->default(false);
                $table->timestamps();

                $table->unique(['user_id', 'workspace_id']);
            });
        }

        if (Schema::hasTable('email_messages')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                if (! Schema::hasColumn('email_messages', 'read_at')) {
                    $table->timestamp('read_at')->nullable()->after('delivered_at');
                }
                if (! Schema::hasColumn('email_messages', 'starred_at')) {
                    $table->timestamp('starred_at')->nullable()->after('read_at');
                }
                if (! Schema::hasColumn('email_messages', 'folder')) {
                    $table->string('folder', 32)->nullable()->after('type')->index();
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::dropIfExists('device_push_tokens');
        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('conversation_user_states');

        if (Schema::hasTable('email_messages')) {
            Schema::table('email_messages', function (Blueprint $table): void {
                foreach (['read_at', 'starred_at', 'folder'] as $column) {
                    if (Schema::hasColumn('email_messages', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('personal_access_tokens')) {
            Schema::table('personal_access_tokens', function (Blueprint $table): void {
                foreach (['device_name', 'device_type', 'user_agent', 'ip_address'] as $column) {
                    if (Schema::hasColumn('personal_access_tokens', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
