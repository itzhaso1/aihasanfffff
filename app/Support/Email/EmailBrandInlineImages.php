<?php

namespace App\Support\Email;

use Illuminate\Support\Facades\Storage;

class EmailBrandInlineImages
{
    public const LOGO_CID = 'workspace-logo';

    /**
     * @return array<string, string>
     */
    public static function socialFiles(): array
    {
        return [
            'LinkedIn' => 'linkedin.png',
            'X' => 'x.png',
            'Facebook' => 'facebook.png',
            'Instagram' => 'instagram.png',
            'YouTube' => 'youtube.png',
        ];
    }

    public static function socialCid(string $label): ?string
    {
        return match ($label) {
            'LinkedIn' => 'social-linkedin',
            'X' => 'social-x',
            'Facebook' => 'social-facebook',
            'Instagram' => 'social-instagram',
            'YouTube' => 'social-youtube',
            default => null,
        };
    }

    public static function socialPublicUrl(string $label): ?string
    {
        $file = self::socialFiles()[$label] ?? null;

        return $file ? url('images/email-social/'.$file) : null;
    }

    public static function socialAbsolutePath(string $label): ?string
    {
        $file = self::socialFiles()[$label] ?? null;
        if ($file === null) {
            return null;
        }

        $path = public_path('images/email-social/'.$file);

        return is_file($path) ? $path : null;
    }

    public static function logoAbsolutePath(int $workspaceId, ?string $path): ?string
    {
        $path = str_replace('\\', '/', trim((string) $path));
        if ($path === '' || ! EmailBrandPublicUrl::isOwnedPublicPath($workspaceId, $path)) {
            return null;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        $absolute = Storage::disk('public')->path($path);

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * @param  array<string, mixed>  $viewData
     * @return array<string, mixed>
     */
    public static function withCids(array $viewData, int $workspaceId, ?string $logoPath): array
    {
        if (self::logoAbsolutePath($workspaceId, $logoPath)) {
            $viewData['logoUrl'] = 'cid:'.self::LOGO_CID;
        }

        foreach ($viewData['socialLinks'] ?? [] as $index => $link) {
            if (! is_array($link)) {
                continue;
            }

            $label = (string) ($link['label'] ?? '');
            $cid = self::socialCid($label);
            if ($cid && self::socialAbsolutePath($label)) {
                $viewData['socialLinks'][$index]['iconUrl'] = 'cid:'.$cid;
            }
        }

        return $viewData;
    }

    /**
     * @param  list<array{label?:string,url?:string}>  $socialLinks
     * @return list<array{cid:string,path:string,mime:string}>
     */
    public static function embeds(int $workspaceId, ?string $logoPath, array $socialLinks): array
    {
        $embeds = [];
        $logo = self::logoAbsolutePath($workspaceId, $logoPath);
        if ($logo) {
            $embeds[] = [
                'cid' => self::LOGO_CID,
                'path' => $logo,
                'mime' => self::mime($logo),
            ];
        }

        foreach ($socialLinks as $link) {
            if (! is_array($link) || empty($link['url'])) {
                continue;
            }

            $label = (string) ($link['label'] ?? '');
            $cid = self::socialCid($label);
            $path = self::socialAbsolutePath($label);
            if (! $cid || ! $path) {
                continue;
            }

            $embeds[] = [
                'cid' => $cid,
                'path' => $path,
                'mime' => 'image/png',
            ];
        }

        return $embeds;
    }

    private static function mime(string $path): string
    {
        return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }
}
