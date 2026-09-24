<?php

namespace App\Services\Telegram;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceTelegramConnection;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramConnectService
{
    public function __construct(
        private readonly TelegramApiClient $apiClient,
    ) {}

    public function connectBotToken(Workspace $workspace, User $user, string $botToken): WorkspaceTelegramConnection
    {
        $botToken = trim($botToken);
        if ($botToken === '') {
            throw new RuntimeException('Telegram bot token is required.');
        }

        $me = $this->apiClient->getMe($botToken);
        $botUserId = (string) ($me['id'] ?? '');
        if ($botUserId === '') {
            throw new RuntimeException('Telegram getMe did not return bot id.');
        }

        $otherWorkspace = WorkspaceTelegramConnection::withoutGlobalScopes()
            ->where('bot_user_id', $botUserId)
            ->where('workspace_id', '!=', $workspace->id)
            ->whereNull('deleted_at')
            ->exists();
        if ($otherWorkspace) {
            throw new RuntimeException('This Telegram bot is already connected to another workspace.');
        }

        return app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $user, $botToken, $me, $botUserId): WorkspaceTelegramConnection {
            return DB::transaction(function () use ($workspace, $user, $botToken, $me, $botUserId): WorkspaceTelegramConnection {
                $connection = WorkspaceTelegramConnection::withoutGlobalScopes()->firstOrNew([
                    'workspace_id' => $workspace->id,
                    'bot_user_id' => $botUserId,
                ]);

                $webhookKey = $connection->public_webhook_key ?: Str::random(48);
                $webhookSecret = Str::random(32);

                $connection->fill([
                    'bot_username' => is_string($me['username'] ?? null) ? (string) $me['username'] : null,
                    'public_webhook_key' => $webhookKey,
                    'integration_mode' => WorkspaceTelegramConnection::MODE_BOT,
                    'status' => WorkspaceTelegramConnection::STATUS_CONNECTED,
                    'metadata' => [
                        'bot_first_name' => is_string($me['first_name'] ?? null) ? (string) $me['first_name'] : null,
                    ],
                    'connected_by_user_id' => $user->id,
                ]);
                $connection->storeBotToken($botToken);
                $connection->storeWebhookSecret($webhookSecret);
                $connection->save();

                $this->apiClient->setWebhook(
                    $botToken,
                    $this->webhookUrl($webhookKey),
                    $webhookSecret,
                    config('telegram.webhook_allowed_updates', ['message', 'business_message']),
                );

                return $connection;
            });
        });
    }

    public function webhookUrl(string $publicWebhookKey): string
    {
        return rtrim((string) config('app.url'), '/').'/telegram-webhook/'.$publicWebhookKey;
    }
}
