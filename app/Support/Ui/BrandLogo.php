<?php

namespace App\Support\Ui;

use App\Models\Workspace;
use App\Support\Site\PublicSite;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Storage;

class BrandLogo
{
    public static function url(?Workspace $workspace = null): string
    {
        $workspace ??= app(WorkspaceContext::class)->workspace();
        $path = is_array($workspace?->settings) ? ($workspace->settings['logo_path'] ?? null) : null;

        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return self::defaultUrl();
    }

    public static function defaultUrl(): string
    {
        return PublicSite::current()->logoUrl();
    }

    public static function bundledUrl(): string
    {
        if (is_file(public_path('images/hasim-logo.png'))) {
            return asset('images/hasim-logo.png');
        }

        return asset('images/hasim-logo.jpg');
    }

    public static function customPath(?Workspace $workspace = null): ?string
    {
        $workspace ??= app(WorkspaceContext::class)->workspace();
        $path = is_array($workspace?->settings) ? ($workspace->settings['logo_path'] ?? null) : null;

        return is_string($path) && $path !== '' ? $path : null;
    }
}
