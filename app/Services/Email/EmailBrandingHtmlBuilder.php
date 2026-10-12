<?php

namespace App\Services\Email;

use App\Models\EmailAccount;
use App\Models\EmailBranding;
use App\Models\EmailMessage;
use App\Support\Email\EmailBrandInlineImages;
use App\Support\Email\EmailBrandPublicUrl;

class EmailBrandingHtmlBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function forOutbound(EmailMessage $emailMessage, EmailAccount $emailAccount): array
    {
        $workspaceId = (int) $emailMessage->workspace_id;

        if ($workspaceId < 1 || (int) $emailAccount->workspace_id !== $workspaceId) {
            throw new \InvalidArgumentException('Email branding is workspace-scoped.');
        }

        $branding = EmailBranding::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->first();

        return $this->viewData($workspaceId, $branding?->toArray() ?? [], $branding, $emailAccount, [
            'subject' => $emailMessage->subject ?: '',
            'body' => $emailMessage->body ?: '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function forPreview(int $workspaceId, array $input, ?EmailBranding $saved = null, ?EmailAccount $account = null): array
    {
        return $this->viewData($workspaceId, $input, $saved, $account, [
            'subject' => trim((string) ($input['preview_subject'] ?? '')) ?: 'مرحباً من '.$this->companyName($input, $saved, $account),
            'body' => trim((string) ($input['preview_body'] ?? '')) ?: $this->defaultPreviewBody($this->companyName($input, $saved, $account)),
        ]);
    }

    public function render(array $viewData): string
    {
        return view('emails.workspace-branded', $viewData)->render();
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{subject:string,body:string}  $message
     * @return array<string, mixed>
     */
    public function viewData(int $workspaceId, array $input, ?EmailBranding $saved, ?EmailAccount $account, array $message): array
    {
        $companyName = $this->companyName($input, $saved, $account);
        $logoPath = $this->stringValue($input['logo_path'] ?? null) ?: $saved?->logo_path ?: $account?->logo_path;
        $logoUrl = EmailBrandPublicUrl::forWorkspacePath($workspaceId, $logoPath);

        return [
            'subject' => $message['subject'],
            'body' => $message['body'],
            'companyName' => $companyName,
            'companyDescription' => $this->stringValue($input['company_description'] ?? $saved?->company_description),
            'logoUrl' => $logoUrl,
            'showHeaderLogo' => $this->flag($input, 'show_header_logo', $saved?->show_header_logo ?? true),
            'showHeaderCompanyName' => $this->flag($input, 'show_header_company_name', $saved?->show_header_company_name ?? true),
            'headerStyle' => $this->headerStyle($input['header_style'] ?? $saved?->header_style),
            'headerLinks' => $this->headerLinks($input['header_links'] ?? $saved?->header_links),
            'primaryColor' => $this->hex($input['primary_color'] ?? $saved?->primary_color, $account?->brand_color ?: '#0F172A'),
            'secondaryColor' => $this->hex($input['secondary_color'] ?? $saved?->secondary_color, '#2563EB'),
            'backgroundColor' => $this->hex($input['background_color'] ?? $saved?->background_color, '#F4F6FB'),
            'textColor' => $this->hex($input['text_color'] ?? $saved?->text_color, '#111827'),
            'showCta' => $this->flag($input, 'show_cta', $saved?->show_cta ?? false),
            'ctaLabel' => $this->stringValue($input['cta_label'] ?? $saved?->cta_label) ?: 'ابدأ الآن',
            'ctaUrl' => $this->httpUrl($input['cta_url'] ?? $saved?->cta_url),
            'footerEmail' => $this->stringValue($input['footer_email'] ?? $saved?->footer_email) ?: $account?->email,
            'footerPhone' => $this->stringValue($input['footer_phone'] ?? $saved?->footer_phone),
            'footerWebsite' => $this->httpUrl($input['footer_website'] ?? $saved?->footer_website),
            'footerAddress' => $this->stringValue($input['footer_address'] ?? $saved?->footer_address),
            'footerCopyright' => $this->copyright($input['footer_copyright'] ?? $saved?->footer_copyright, $companyName),
            'showFooterCompanyName' => $this->flag($input, 'show_footer_company_name', $saved?->show_footer_company_name ?? true),
            'showFooterEmail' => $this->flag($input, 'show_footer_email', $saved?->show_footer_email ?? true),
            'showFooterPhone' => $this->flag($input, 'show_footer_phone', $saved?->show_footer_phone ?? true),
            'showFooterWebsite' => $this->flag($input, 'show_footer_website', $saved?->show_footer_website ?? true),
            'showFooterAddress' => $this->flag($input, 'show_footer_address', $saved?->show_footer_address ?? true),
            'showFooterSocial' => $this->flag($input, 'show_footer_social', $saved?->show_footer_social ?? true),
            'showFooterCopyright' => $this->flag($input, 'show_footer_copyright', $saved?->show_footer_copyright ?? true),
            'socialLinks' => $this->socialLinks($input, $saved),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function companyName(array $input, ?EmailBranding $saved, ?EmailAccount $account): string
    {
        $name = $this->stringValue($input['company_name'] ?? $saved?->company_name) ?: $account?->name;

        return $name !== '' ? $name : 'شركتك';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function flag(array $input, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }

        return filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    private function hex(mixed $value, string $fallback): string
    {
        $color = strtoupper(trim((string) $value));

        return preg_match('/^#([A-F0-9]{6}|[A-F0-9]{3})$/', $color) === 1 ? $color : $fallback;
    }

    private function headerStyle(mixed $value): string
    {
        $style = strtolower(trim((string) $value));

        return in_array($style, EmailBranding::HEADER_STYLES, true) ? $style : 'solid';
    }

    /**
     * @return list<array{label:string,url:string}>
     */
    private function headerLinks(mixed $links): array
    {
        if (! is_array($links)) {
            return [];
        }

        $normalized = [];

        foreach (array_slice($links, 0, 5) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $label = $this->stringValue($link['label'] ?? '');
            $url = $this->httpUrl($link['url'] ?? null);
            $visible = filter_var($link['visible'] ?? true, FILTER_VALIDATE_BOOLEAN);

            if ($label === '' || $url === null || ! $visible) {
                continue;
            }

            $normalized[] = ['label' => $label, 'url' => $url];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<array{label:string,url:string,iconUrl:?string}>
     */
    private function socialLinks(array $input, ?EmailBranding $saved): array
    {
        $map = [
            'social_linkedin' => 'LinkedIn',
            'social_x' => 'X',
            'social_facebook' => 'Facebook',
            'social_instagram' => 'Instagram',
            'social_youtube' => 'YouTube',
        ];

        $links = [];

        foreach ($map as $key => $label) {
            $url = $this->httpUrl($input[$key] ?? $saved?->{$key});
            if ($url) {
                $links[] = [
                    'label' => $label,
                    'url' => $url,
                    'iconUrl' => EmailBrandInlineImages::socialPublicUrl($label),
                ];
            }
        }

        return $links;
    }

    private function httpUrl(mixed $value): ?string
    {
        $url = $this->stringValue($value);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function copyright(mixed $value, string $companyName): string
    {
        $custom = $this->stringValue($value);
        if ($custom !== '') {
            return $custom;
        }

        return '© '.now()->year.' '.$companyName.' — جميع الحقوق محفوظة.';
    }

    private function defaultPreviewBody(string $companyName): string
    {
        return "أهلاً بك،\n\nنشكرك لانضمامك إلى {$companyName}. نحن ملتزمون بتقديم أفضل الحلول لمساعدتك في تحقيق أهدافك.\n\nمع أطيب التحيات.";
    }
}
