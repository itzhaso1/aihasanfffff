<?php

namespace App\Services\Telegram;

use App\Models\WorkspaceTelegramConnection;

final class TelegramWebhookVerifier
{
    public function connectionForRequest(string $publicWebhookKey, ?string $secretHeader): ?WorkspaceTelegramConnection
    {
        $connection = WorkspaceTelegramConnection::withoutGlobalScopes()
            ->where('public_webhook_key', $publicWebhookKey)
            ->where('status', WorkspaceTelegramConnection::STATUS_CONNECTED)
            ->whereNull('deleted_at')
            ->first();

        if (! $connection) {
            return null;
        }

        $expected = $connection->webhookSecret();
        if ($expected !== null && $expected !== '') {
            if (! is_string($secretHeader) || ! hash_equals($expected, $secretHeader)) {
                return null;
            }
        }

        return $connection;
    }
}
