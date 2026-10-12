<?php

namespace App\Services\Platform;

use App\Models\PlatformSiteSetting;
use App\Support\Site\PublicSite;
use App\Support\Uploads\SecureUpload;
use Illuminate\Http\UploadedFile;

class PublicSiteSettingsService
{
    /**
     * @var list<string>
     */
    public const TEXT_KEYS = [
        'name_ar',
        'name_en',
        'app_name',
        'tagline',
        'description',
        'footer_company',
        'phone',
        'commercial_registration',
        'address',
        'email',
        'tax_number',
        'chat_google_play_url',
        'chat_windows_url',
        'chat_app_store_url',
    ];

    public function __construct(private readonly SecureUpload $uploads) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        array $input,
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
        ?UploadedFile $favicon = null,
        bool $removeFavicon = false,
    ): void {
        foreach (self::TEXT_KEYS as $key) {
            $value = $input[$key] ?? null;
            $this->put($key, is_string($value) ? trim($value) : null);
        }

        $phone = is_string($input['phone'] ?? null) ? trim((string) $input['phone']) : '';
        $this->put('phone_href', $phone !== '' ? PublicSite::hrefFromPhone($phone) : null);

        $this->replaceImage('logo_path', $logo, $removeLogo);
        $this->replaceImage('favicon_path', $favicon, $removeFavicon);

        PublicSite::flush();
    }

    private function replaceImage(string $key, ?UploadedFile $file, bool $remove): void
    {
        $current = PlatformSiteSetting::query()->where('key', $key)->value('value');
        $current = is_string($current) && $current !== '' ? $current : null;

        if ($remove && $current) {
            $this->uploads->delete($current);
            $this->put($key, null);

            return;
        }

        if (! $file instanceof UploadedFile) {
            return;
        }

        $path = $this->uploads->store(
            $file,
            'platform/site',
            'public',
            2048,
            SecureUpload::IMAGE_EXTENSIONS,
            SecureUpload::IMAGE_MIMES,
        );
        if ($current && $current !== $path) {
            $this->uploads->delete($current);
        }
        $this->put($key, $path);
    }

    private function put(string $key, ?string $value): void
    {
        $stored = ($value !== null && $value !== '') ? $value : null;

        PlatformSiteSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored],
        );
    }
}
