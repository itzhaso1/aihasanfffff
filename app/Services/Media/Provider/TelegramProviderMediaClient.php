<?php

namespace App\Services\Media\Provider;

use App\Models\Message;
use App\Models\WorkspaceTelegramConnection;
use App\Services\Media\ProviderMediaDownloadResult;
use App\Services\Telegram\TelegramApiClient;

final class TelegramProviderMediaClient
{
    public function __construct(
        private readonly TelegramApiClient $telegramApi,
    ) {}

    public function downloadForMessage(Message $message, string $fileId): ProviderMediaDownloadResult
    {
        $connection = WorkspaceTelegramConnection::withoutGlobalScopes()
            ->where('workspace_id', $message->workspace_id)
            ->where('status', WorkspaceTelegramConnection::STATUS_CONNECTED)
            ->first();

        $token = $connection?->botToken();
        if ($token === null || $token === '') {
            throw new \RuntimeException('telegram_media_missing_connection');
        }

        $file = $this->telegramApi->getFile($token, $fileId);
        $path = (string) ($file['file_path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('telegram_media_missing_path');
        }

        $binary = $this->telegramApi->downloadFile($token, $path);

        return new ProviderMediaDownloadResult(
            binary: $binary,
            mimeType: null,
            originalFilename: basename($path),
        );
    }
}
