<?php

namespace App\Support\Email;

use App\Models\EmailBranding;
use App\Support\Tenancy\WorkspaceContext;

class EmailLibraryBranding
{
    public const DEFAULT_PRIMARY = '#0F172A';

    public const DEFAULT_SECONDARY = '#2563EB';

    public const DEFAULT_BACKGROUND = '#F4F6FB';

    public const DEFAULT_TEXT = '#111827';

    /**
     * Same Blade names as EmailBrandingHtmlBuilder, for library views only.
     *
     * @return array{
     *     companyName: string,
     *     companyDescription: string,
     *     logoUrl: ?string,
     *     primaryColor: string,
     *     secondaryColor: string,
     *     backgroundColor: string,
     *     textColor: string,
     *     ctaLabel: string,
     *     ctaUrl: ?string,
     *     showCta: bool
     * }
     */
    public static function forCurrentWorkspace(): array
    {
        $workspaceId = (int) (app(WorkspaceContext::class)->workspaceId() ?? 0);
        if ($workspaceId < 1) {
            return self::defaults();
        }

        $branding = EmailBranding::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();

        if (! $branding) {
            return self::defaults();
        }

        return [
            'companyName' => trim((string) $branding->company_name) ?: 'شركتك',
            'companyDescription' => trim((string) $branding->company_description),
            'logoUrl' => EmailBrandPublicUrl::forWorkspacePath($workspaceId, $branding->logo_path),
            'primaryColor' => self::hex($branding->primary_color, self::DEFAULT_PRIMARY),
            'secondaryColor' => self::hex($branding->secondary_color, self::DEFAULT_SECONDARY),
            'backgroundColor' => self::hex($branding->background_color, self::DEFAULT_BACKGROUND),
            'textColor' => self::hex($branding->text_color, self::DEFAULT_TEXT),
            'ctaLabel' => trim((string) $branding->cta_label) ?: 'ابدأ الآن',
            'ctaUrl' => self::httpUrl($branding->cta_url),
            'showCta' => (bool) $branding->show_cta,
        ];
    }

    /**
     * @return array{
     *     companyName: string,
     *     companyDescription: string,
     *     logoUrl: ?string,
     *     primaryColor: string,
     *     secondaryColor: string,
     *     backgroundColor: string,
     *     textColor: string,
     *     ctaLabel: string,
     *     ctaUrl: ?string,
     *     showCta: bool
     * }
     */
    public static function defaults(): array
    {
        return [
            'companyName' => 'شركتك',
            'companyDescription' => '',
            'logoUrl' => null,
            'primaryColor' => self::DEFAULT_PRIMARY,
            'secondaryColor' => self::DEFAULT_SECONDARY,
            'backgroundColor' => self::DEFAULT_BACKGROUND,
            'textColor' => self::DEFAULT_TEXT,
            'ctaLabel' => 'ابدأ الآن',
            'ctaUrl' => null,
            'showCta' => false,
        ];
    }

    public static function swatch(mixed $value, string $fallback): string
    {
        $color = self::hex($value, $fallback);
        if (preg_match('/^#([A-F0-9]{3})$/', $color, $match) === 1) {
            $digits = $match[1];
            $color = '#'.$digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        }

        return strtolower($color);
    }

    private static function hex(mixed $value, string $fallback): string
    {
        $color = strtoupper(trim((string) $value));

        return preg_match('/^#([A-F0-9]{6}|[A-F0-9]{3})$/', $color) === 1 ? $color : $fallback;
    }

    private static function httpUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ?: null;
    }
}
