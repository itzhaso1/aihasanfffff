<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramApiClient
{
    public function getMe(string $botToken): array
    {
        return $this->call($botToken, 'getMe');
    }

    /**
     * @param  list<string>  $allowedUpdates
     */
    public function setWebhook(string $botToken, string $url, ?string $secretToken, array $allowedUpdates): array
    {
        return $this->call($botToken, 'setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => $allowedUpdates,
        ]);
    }

    public function deleteWebhook(string $botToken): array
    {
        return $this->call($botToken, 'deleteWebhook', [
            'drop_pending_updates' => false,
        ]);
    }

    public function sendMessage(
        string $botToken,
        string $chatId,
        string $text,
        ?string $businessConnectionId = null,
    ): array {
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($businessConnectionId !== null && $businessConnectionId !== '') {
            $payload['business_connection_id'] = $businessConnectionId;
        }

        return $this->call($botToken, 'sendMessage', $payload);
    }

    public function getFile(string $botToken, string $fileId): array
    {
        return $this->call($botToken, 'getFile', ['file_id' => $fileId]);
    }

    public function downloadFile(string $botToken, string $filePath): string
    {
        $base = rtrim((string) config('telegram.api_base', 'https://api.telegram.org'), '/');
        $response = Http::timeout(60)->get($base.'/file/bot'.$botToken.'/'.$filePath);
        if (! $response->successful()) {
            throw new RuntimeException('Telegram file download failed.');
        }

        return (string) $response->body();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(string $botToken, string $method, array $params = []): array
    {
        $base = rtrim((string) config('telegram.api_base', 'https://api.telegram.org'), '/');
        $response = Http::acceptJson()
            ->asJson()
            ->timeout(30)
            ->post($base.'/bot'.$botToken.'/'.$method, array_filter($params, fn ($v) => $v !== null));

        $payload = $response->json();
        if (! is_array($payload) || ! ($payload['ok'] ?? false)) {
            throw new RuntimeException('Telegram API error: '.((string) ($payload['description'] ?? 'unknown')));
        }

        $result = $payload['result'] ?? [];

        return is_array($result) ? $result : [];
    }
}
