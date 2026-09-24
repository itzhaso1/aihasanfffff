<?php

namespace App\Services\Media\Provider;

use App\Exceptions\Media\MediaDownloadRetryableException;
use Illuminate\Support\Facades\Http;

final class WhatsAppProviderMediaClient
{
    public function download(string $providerMediaId): ProviderMediaDownloadResult
    {
        $token = config('services.whatsapp.token');
        if (! is_string($token) || $token === '') {
            throw new MediaDownloadRetryableException('whatsapp_token_missing');
        }

        $version = (string) config('whatsapp.api_version', 'v20.0');
        $meta = Http::acceptJson()
            ->withToken($token)
            ->timeout(20)
            ->get('https://graph.facebook.com/'.$version.'/'.$providerMediaId);

        if ($meta->failed()) {
            if ($meta->serverError() || $meta->status() === 429) {
                throw new MediaDownloadRetryableException('whatsapp_media_meta_'.$meta->status());
            }

            throw new \RuntimeException('whatsapp_media_meta_'.$meta->status());
        }

        $url = trim((string) $meta->json('url'));
        $mime = trim((string) ($meta->json('mime_type') ?: 'application/octet-stream'));
        if ($url === '') {
            throw new \RuntimeException('whatsapp_media_url_missing');
        }

        $binary = Http::withToken($token)
            ->timeout(60)
            ->get($url);

        if ($binary->failed()) {
            if ($binary->serverError() || $binary->status() === 429) {
                throw new MediaDownloadRetryableException('whatsapp_media_download_'.$binary->status());
            }

            throw new \RuntimeException('whatsapp_media_download_'.$binary->status());
        }

        $body = $binary->body();
        if ($body === '') {
            throw new \RuntimeException('whatsapp_media_empty');
        }

        return new ProviderMediaDownloadResult(
            binary: $body,
            mimeType: $mime !== '' ? $mime : 'application/octet-stream',
            originalFilename: null,
        );
    }
}
