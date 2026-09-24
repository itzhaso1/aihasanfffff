<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_telegram_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('bot_user_id', 64);
            $table->string('bot_username')->nullable();
            $table->string('public_webhook_key', 64);
            $table->string('integration_mode', 32)->default('bot');
            $table->string('status', 32)->default('connected');
            $table->json('metadata')->nullable();
            $table->text('bot_token_ciphertext');
            $table->text('webhook_secret_ciphertext')->nullable();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'bot_user_id'], 'workspace_telegram_connections_tenant_bot_unique');
            $table->unique('public_webhook_key', 'workspace_telegram_connections_webhook_key_unique');
            $table->index(['bot_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_telegram_connections');
    }
};
