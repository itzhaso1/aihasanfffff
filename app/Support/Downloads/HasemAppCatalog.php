<?php

namespace App\Support\Downloads;

use App\Support\Site\PublicSite;

final class HasemAppCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values(array_map(
            static fn (array $app): array => self::hydrate($app),
            config('downloads.apps', []),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        $app = config('downloads.apps.'.$key);

        return is_array($app) ? self::hydrate($app) : null;
    }

    /**
     * @param  array<string, mixed>  $app
     * @return array<string, mixed>
     */
    private static function hydrate(array $app): array
    {
        $downloads = [];

        $android = self::firstUrl(
            PublicSite::current()->downloadUrl((string) ($app['key'] ?? ''), 'google_play'),
            $app['google_play_url'] ?? null,
        );
        $windows = self::firstUrl(
            PublicSite::current()->downloadUrl((string) ($app['key'] ?? ''), 'windows'),
            $app['windows_url'] ?? null,
        );
        $ios = self::firstUrl(
            PublicSite::current()->downloadUrl((string) ($app['key'] ?? ''), 'app_store'),
            $app['app_store_url'] ?? null,
        );

        if ($android !== null) {
            $downloads[] = [
                'key' => 'android',
                'label' => str_contains($android, 'play.google.com') ? 'تحميل من Google Play' : 'تحميل لأندرويد',
                'hint' => 'Android',
                'url' => $android,
            ];
        }

        if ($windows !== null) {
            $downloads[] = [
                'key' => 'windows',
                'label' => 'تحميل لـ Windows',
                'hint' => 'Windows',
                'url' => $windows,
            ];
        }

        if ($ios !== null) {
            $downloads[] = [
                'key' => 'ios',
                'label' => str_contains($ios, 'apps.apple.com') ? 'تحميل من App Store' : 'تحميل لـ iOS',
                'hint' => 'iOS',
                'url' => $ios,
            ];
        }

        $app['downloads'] = $downloads;

        return $app;
    }

    private static function firstUrl(?string $preferred, mixed $fallback): ?string
    {
        if (filled($preferred)) {
            return (string) $preferred;
        }

        return filled($fallback) ? (string) $fallback : null;
    }
}
