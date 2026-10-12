<?php

namespace App\Services\Email;

use App\Models\EmailTemplatePreference;
use App\Models\Workspace;
use App\Support\Email\EmailLibraryBranding;
use App\Support\Email\EmailTemplateLibrary;
use Illuminate\Support\Facades\View;

class EmailTemplateLibraryService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function categories(): array
    {
        $items = [];

        foreach (EmailTemplateLibrary::categories() as $slug => $category) {
            $templates = EmailTemplateLibrary::all($slug);
            $items[] = [
                'slug' => $slug,
                'name' => $category['name'] ?? $slug,
                'name_en' => $category['name_en'] ?? $slug,
                'description' => $category['description'] ?? '',
                'template_count' => count($templates),
            ];
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function templatesFor(string $category): array
    {
        abort_unless(array_key_exists($category, EmailTemplateLibrary::categories()), 404);

        return array_values(array_map(function (array $template): array {
            $preview = $template['preview'] ?? null;
            $previewPath = is_string($preview) ? public_path($preview) : null;

            return $template + [
                'usable' => true,
                'preview_url' => is_string($previewPath) && is_file($previewPath) ? asset((string) $preview) : null,
            ];
        }, EmailTemplateLibrary::all($category)));
    }

    /**
     * @return array<string, mixed>
     */
    public function templateOrFail(string $key): array
    {
        $template = EmailTemplateLibrary::get($key);
        abort_unless($template, 404);

        return $template;
    }

    public function preference(Workspace $workspace): ?EmailTemplatePreference
    {
        return EmailTemplatePreference::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();
    }

    public function select(Workspace $workspace, string $key): EmailTemplatePreference
    {
        $this->templateOrFail($key);

        $preference = $this->preference($workspace);
        $settings = $preference && $preference->template_key === $key
            ? (array) $preference->settings
            : [];

        if ($preference) {
            $preference->fill([
                'template_key' => $key,
                'settings' => $settings,
            ])->save();

            return $preference->fresh() ?? $preference;
        }

        return EmailTemplatePreference::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'template_key' => $key,
            'settings' => $settings,
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(Workspace $workspace, array $settings): EmailTemplatePreference
    {
        $preference = $this->preference($workspace);
        abort_unless($preference, 404);

        $template = $this->templateOrFail($preference->template_key);

        $preference->fill([
            'settings' => $this->sanitizeSettings($template['key'], $settings),
        ])->save();

        return $preference->fresh() ?? $preference;
    }

    /**
     * Preview the workspace's selected library template.
     * Colors come from the template defaults and its saved settings, not from
     * the workspace branding page — unless the live form is editing those settings.
     * Returns null when the live workspace-branded blade should be used instead.
     *
     * @param  array<string, mixed>  $formInput
     */
    public function renderSelectedForBranding(Workspace $workspace, array $formInput = []): ?string
    {
        $preference = $this->preference($workspace);
        if (! $preference) {
            return null;
        }

        $template = $this->templateOrFail($preference->template_key);
        if (($template['view'] ?? null) === 'emails.workspace-branded') {
            return null;
        }

        return $this->render(
            $preference->template_key,
            array_merge(
                (array) $preference->settings,
                $this->identityOverridesFromBrandingForm($formInput),
                $this->colorOverridesFromBrandingForm($formInput),
            )
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function render(string $key, array $overrides = []): string
    {
        $template = $this->templateOrFail($key);
        $view = (string) $template['view'];
        abort_unless(View::exists($view), 404);

        return view($view, $this->viewData($key, $overrides))->render();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function overridesFromBrandingForm(array $input): array
    {
        return array_merge(
            $this->identityOverridesFromBrandingForm($input),
            $this->colorOverridesFromBrandingForm($input),
        );
    }

    /**
     * Company identity from the branding form may still wrap the selected template.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function identityOverridesFromBrandingForm(array $input): array
    {
        return $this->mapBrandingForm($input, [
            'company_name' => 'companyName',
            'company_description' => 'companyDescription',
            'cta_label' => 'ctaLabel',
            'cta_url' => 'ctaUrl',
            'show_cta' => 'showCta',
            'preview_subject' => 'subject',
            'preview_body' => 'body',
        ]);
    }

    /**
     * Live color fields from the branding form apply only when they were posted
     * as a preview/save of the selected template — not from a raw branding row.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function colorOverridesFromBrandingForm(array $input): array
    {
        if (! $this->isLiveBrandingPreview($input)) {
            return [];
        }

        return $this->mapBrandingForm($input, [
            'primary_color' => 'primaryColor',
            'secondary_color' => 'secondaryColor',
            'background_color' => 'backgroundColor',
            'text_color' => 'textColor',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function viewData(string $key, array $overrides = []): array
    {
        $branding = EmailLibraryBranding::forCurrentWorkspace();
        $identity = [
            'companyName' => $branding['companyName'],
            'companyDescription' => $branding['companyDescription'],
            'logoUrl' => $branding['logoUrl'],
            'ctaLabel' => $branding['ctaLabel'],
            'ctaUrl' => $branding['ctaUrl'],
            'showCta' => $branding['showCta'],
        ];
        $sample = [
            'subject' => 'مرحباً من '.($overrides['companyName'] ?? $identity['companyName']),
            'body' => 'هذه معاينة تجريبية للقالب باستخدام بيانات الهوية الحالية.',
            'customerName' => 'عميل تجريبي',
            'headerStyle' => 'solid',
            'headerLinks' => [],
            'socialLinks' => [],
            'showHeaderLogo' => true,
            'showHeaderCompanyName' => true,
            'showFooterCompanyName' => true,
            'showFooterEmail' => false,
            'showFooterPhone' => false,
            'showFooterWebsite' => false,
            'showFooterAddress' => false,
            'showFooterSocial' => false,
            'showFooterCopyright' => false,
            'footerEmail' => null,
            'footerPhone' => null,
            'footerWebsite' => null,
            'footerAddress' => null,
            'footerCopyright' => null,
        ];

        return array_merge(
            $sample,
            $identity,
            $this->templateDefaults($key),
            $this->sanitizeSettings($key, $overrides),
        );
    }

    /**
     * @return list<array{key:string,type:string,label:string}>
     */
    public function editorFields(string $key): array
    {
        $fields = [];

        foreach (EmailTemplateLibrary::variables($key) as $variable) {
            $meta = $this->fieldMap()[$variable] ?? null;
            if ($meta) {
                $fields[] = ['key' => $variable] + $meta;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function sanitizeSettings(string $key, array $settings): array
    {
        $allowed = EmailTemplateLibrary::variables($key);
        $clean = [];

        foreach ($allowed as $variable) {
            if (! array_key_exists($variable, $settings)) {
                continue;
            }

            $meta = $this->fieldMap()[$variable] ?? null;
            if (! $meta || $meta['type'] === 'logo') {
                continue;
            }

            $value = $settings[$variable];
            $clean[$variable] = match ($meta['type']) {
                'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'color' => $this->hex($value) ?? EmailLibraryBranding::DEFAULT_PRIMARY,
                'url' => $this->httpUrl($value),
                default => trim((string) $value),
            };
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    public function templateDefaults(string $key): array
    {
        $template = $this->templateOrFail($key);
        $defaults = $template['defaults'] ?? [];

        return is_array($defaults) ? $this->sanitizeSettings($key, $defaults) : [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $pairs
     * @return array<string, mixed>
     */
    private function mapBrandingForm(array $input, array $pairs): array
    {
        $overrides = [];
        foreach ($pairs as $from => $to) {
            if (array_key_exists($from, $input)) {
                $overrides[$to] = $input[$from];
            }
        }

        return $overrides;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function isLiveBrandingPreview(array $input): bool
    {
        return array_key_exists('current_tab', $input)
            || array_key_exists('preview_subject', $input)
            || array_key_exists('preview_body', $input);
    }

    /**
     * @return array<string, array{type:string,label:string}>
     */
    private function fieldMap(): array
    {
        return [
            'companyName' => ['type' => 'text', 'label' => 'اسم الشركة'],
            'companyDescription' => ['type' => 'text', 'label' => 'وصف الشركة'],
            'customerName' => ['type' => 'text', 'label' => 'اسم العميل'],
            'subject' => ['type' => 'text', 'label' => 'العنوان'],
            'body' => ['type' => 'textarea', 'label' => 'المحتوى'],
            'ctaLabel' => ['type' => 'text', 'label' => 'نص الزر'],
            'ctaUrl' => ['type' => 'url', 'label' => 'رابط الزر'],
            'logoUrl' => ['type' => 'logo', 'label' => 'الشعار'],
            'primaryColor' => ['type' => 'color', 'label' => 'اللون الرئيسي'],
            'secondaryColor' => ['type' => 'color', 'label' => 'اللون الثانوي'],
            'backgroundColor' => ['type' => 'color', 'label' => 'لون الخلفية'],
            'textColor' => ['type' => 'color', 'label' => 'لون النص'],
            'showCta' => ['type' => 'checkbox', 'label' => 'إظهار الزر'],
            'showHeaderLogo' => ['type' => 'checkbox', 'label' => 'إظهار الشعار'],
            'showHeaderCompanyName' => ['type' => 'checkbox', 'label' => 'إظهار اسم الشركة'],
            'showFooterCompanyName' => ['type' => 'checkbox', 'label' => 'إظهار اسم الشركة في التذييل'],
            'showFooterEmail' => ['type' => 'checkbox', 'label' => 'إظهار البريد'],
            'showFooterPhone' => ['type' => 'checkbox', 'label' => 'إظهار الهاتف'],
            'showFooterWebsite' => ['type' => 'checkbox', 'label' => 'إظهار الموقع'],
            'showFooterAddress' => ['type' => 'checkbox', 'label' => 'إظهار العنوان'],
            'showFooterSocial' => ['type' => 'checkbox', 'label' => 'إظهار روابط التواصل'],
            'showFooterCopyright' => ['type' => 'checkbox', 'label' => 'إظهار حقوق النشر'],
        ];
    }

    private function hex(mixed $value): ?string
    {
        $color = strtoupper(trim((string) $value));

        return preg_match('/^#([A-F0-9]{6}|[A-F0-9]{3})$/', $color) === 1 ? $color : null;
    }

    private function httpUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ?: null;
    }
}
