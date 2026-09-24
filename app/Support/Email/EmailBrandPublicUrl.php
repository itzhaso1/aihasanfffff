<?php

namespace App\Support\Email;

use Illuminate\Support\Facades\Storage;

class EmailBrandPublicUrl
{
    public static function forWorkspacePath(int $workspaceId, ?string $path): ?string
    {
        $path = str_replace('\\', '/', trim((string) $path));

        if ($path === '' || ! self::isOwnedPublicPath($workspaceId, $path)) {
            return null;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        $url = (string) Storage::disk('public')->url($path);

        if (! preg_match('#^https?://#i', $url)) {
            $base = rtrim((string) config('app.url'), '/');
            $url = $base.'/'.ltrim($url, '/');
        }

        return self::isSafeRemoteUrl($url) ? $url : null;
    }

    public static function isOwnedPublicPath(int $workspaceId, string $path): bool
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '' || str_contains($path, '..') || str_contains($path, ':')) {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, 'file:') || str_starts_with($path, 'data:')) {
            return false;
        }

        return (bool) preg_match('#^workspaces/'.$workspaceId.'/(email-branding|email-logos)/[^/]+$#', $path);
    }

    public static function isSafeRemoteUrl(?string $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        if (! preg_match('#^https?://[^/]+/.+#i', $url)) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        return in_array($scheme, ['http', 'https'], true)
            && $host !== ''
            && ! str_contains($url, '..');
    }
}
