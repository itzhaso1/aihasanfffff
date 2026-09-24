<?php

namespace App\Support\Site;

use App\Models\PlatformSiteSetting;
use App\Support\Ui\BrandLogo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class PublicSite
{
    private static ?self $current = null;

    /**
     * @param  array<string, string|null>  $values
     */
    private function __construct(private readonly array $values) {}

    public static function current(): self
    {
        return self::$current ??= new self(self::loadValues());
    }

    public static function flush(): void
    {
        self::$current = null;
    }

    public function nameAr(): string
    {
        return $this->string('name_ar', (string) config('legal.company_name', 'حاسم الذكي'));
    }

    public function nameEn(): string
    {
        return $this->string('name_en', 'HASIM SMART');
    }

    public function appName(): string
    {
        return $this->string('app_name', (string) config('app.name', 'HASEM'));
    }

    public function tagline(): string
    {
        return $this->string('tagline', 'HASIM SMART');
    }

    public function description(): string
    {
        return $this->string('description', 'منصة عربية لإدارة المحادثات والطلبات والعملاء والفريق من مكان واحد.');
    }

    public function phone(): string
    {
        return $this->string('phone', (string) config('legal.phone', '+966 59 539 7059'));
    }

    public function phoneHref(): string
    {
        $stored = $this->nullable('phone_href');
        if ($stored !== null) {
            return $stored;
        }

        return self::hrefFromPhone($this->phone());
    }

    public function commercialRegistration(): string
    {
        return $this->string('commercial_registration', (string) config('legal.commercial_registration', '7054911743'));
    }

    public function address(): string
    {
        return $this->string('address', (string) config('legal.address', 'الرياض، حي المونسية، طريق الأمير محمد بن سلمان بن عبدالعزيز'));
    }

    public function email(): ?string
    {
        return $this->nullable('email') ?? (filled(config('legal.email')) ? (string) config('legal.email') : null);
    }

    public function taxNumber(): ?string
    {
        return $this->nullable('tax_number') ?? (filled(config('legal.tax_number')) ? (string) config('legal.tax_number') : null);
    }

    public function footerCompany(): string
    {
        return $this->string('footer_company', 'شركة '.$this->nameAr());
    }

    public function logoPath(): ?string
    {
        return $this->nullable('logo_path');
    }

    public function logoUrl(): string
    {
        return $this->storedPublicUrl($this->logoPath()) ?? BrandLogo::bundledUrl();
    }

    public function faviconPath(): ?string
    {
        return $this->nullable('favicon_path');
    }

    public function faviconUrl(): string
    {
        return $this->storedPublicUrl($this->faviconPath()) ?? $this->logoUrl();
    }

    public function downloadUrl(string $app, string $platform): ?string
    {
        return $this->nullable($app.'_'.$platform.'_url');
    }

    /**
     * @return array<string, mixed>
     */
    public function legalFacts(): array
    {
        $legal = config('legal', []);
        if (! is_array($legal)) {
            $legal = [];
        }

        $legal['company_name'] = $this->nameAr();
        $legal['phone'] = $this->phone();
        $legal['phone_href'] = $this->phoneHref();
        $legal['commercial_registration'] = $this->commercialRegistration();
        $legal['address'] = $this->address();
        $legal['email'] = $this->email();
        $legal['tax_number'] = $this->taxNumber();

        return $legal;
    }

    /**
     * @return array<string, string|null>
     */
    public function formValues(): array
    {
        return [
            'name_ar' => $this->nameAr(),
            'name_en' => $this->nameEn(),
            'app_name' => $this->appName(),
            'tagline' => $this->tagline(),
            'description' => $this->description(),
            'footer_company' => $this->footerCompany(),
            'phone' => $this->phone(),
            'commercial_registration' => $this->commercialRegistration(),
            'address' => $this->address(),
            'email' => $this->email(),
            'tax_number' => $this->taxNumber(),
            'logo_path' => $this->logoPath(),
            'favicon_path' => $this->faviconPath(),
            'chat_google_play_url' => $this->downloadUrl('chat', 'google_play') ?: config('downloads.apps.chat.google_play_url'),
            'chat_windows_url' => $this->downloadUrl('chat', 'windows') ?: config('downloads.apps.chat.windows_url'),
            'chat_app_store_url' => $this->downloadUrl('chat', 'app_store') ?: config('downloads.apps.chat.app_store_url'),
            'finance_google_play_url' => $this->downloadUrl('finance', 'google_play') ?: config('downloads.apps.finance.google_play_url'),
            'finance_windows_url' => $this->downloadUrl('finance', 'windows') ?: config('downloads.apps.finance.windows_url'),
            'finance_app_store_url' => $this->downloadUrl('finance', 'app_store') ?: config('downloads.apps.finance.app_store_url'),
            'cashier_google_play_url' => $this->downloadUrl('cashier', 'google_play') ?: config('downloads.apps.cashier.google_play_url'),
            'cashier_windows_url' => $this->downloadUrl('cashier', 'windows') ?: config('downloads.apps.cashier.windows_url'),
            'cashier_app_store_url' => $this->downloadUrl('cashier', 'app_store') ?: config('downloads.apps.cashier.app_store_url'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function loadValues(): array
    {
        if (! Schema::hasTable('platform_site_settings')) {
            return [];
        }

        return PlatformSiteSetting::query()->pluck('value', 'key')->all();
    }

    private function string(string $key, string $default): string
    {
        $value = $this->nullable($key);

        return $value ?? $default;
    }

    private function storedPublicUrl(?string $path): ?string
    {
        if ($path !== null && Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->url($path);
        }

        return null;
    }

    private function nullable(string $key): ?string
    {
        $value = $this->values[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    public static function hrefFromPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '05') && strlen($digits) === 10) {
            $digits = '966'.substr($digits, 1);
        }
        if ($digits === '') {
            return (string) config('legal.phone_href', '+966595397059');
        }

        return '+'.$digits;
    }
}
