<?php

namespace App\Services\Email;

use App\Models\EmailBranding;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmailBrandingService
{
    public function __construct(
        private readonly EmailBrandingHtmlBuilder $htmlBuilder,
    ) {}

    public function forWorkspace(Workspace $workspace): EmailBranding
    {
        $existing = EmailBranding::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return EmailBranding::withoutGlobalScopes()->create($this->defaults($workspace));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Workspace $workspace, array $data, ?UploadedFile $logo = null, bool $removeLogo = false): EmailBranding
    {
        return DB::transaction(function () use ($workspace, $data, $logo, $removeLogo): EmailBranding {
            $branding = $this->forWorkspace($workspace);
            $payload = $this->payload($workspace, $data);
            $previousLogo = $branding->logo_path;

            if ($removeLogo && $previousLogo) {
                Storage::disk('public')->delete($previousLogo);
                $payload['logo_path'] = null;
            }

            if ($logo) {
                if ($previousLogo) {
                    Storage::disk('public')->delete($previousLogo);
                }
                $payload['logo_path'] = $logo->store('workspaces/'.$workspace->id.'/email-branding', 'public');
            }

            $updated = EmailBranding::withoutGlobalScopes()
                ->whereKey($branding->id)
                ->where('workspace_id', $workspace->id)
                ->first();

            if (! $updated) {
                throw new \RuntimeException('Email branding update failed.');
            }

            $updated->fill($payload)->save();

            return $updated->fresh() ?? $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function previewHtml(Workspace $workspace, array $input): string
    {
        $saved = EmailBranding::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();

        return $this->htmlBuilder->render(
            $this->htmlBuilder->forPreview((int) $workspace->id, $input, $saved)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(Workspace $workspace): array
    {
        return [
            'workspace_id' => $workspace->id,
            'company_name' => $workspace->name,
            'company_description' => null,
            'logo_path' => null,
            'primary_color' => '#0F172A',
            'secondary_color' => '#2563EB',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
            'header_style' => 'solid',
            'show_header_logo' => true,
            'show_header_company_name' => true,
            'header_links' => $this->defaultHeaderLinks(),
            'footer_email' => null,
            'footer_phone' => null,
            'footer_website' => null,
            'footer_address' => null,
            'footer_copyright' => null,
            'show_footer_company_name' => true,
            'show_footer_email' => true,
            'show_footer_phone' => true,
            'show_footer_website' => true,
            'show_footer_address' => true,
            'show_footer_social' => true,
            'show_footer_copyright' => true,
            'social_linkedin' => null,
            'social_x' => null,
            'social_facebook' => null,
            'social_instagram' => null,
            'social_youtube' => null,
            'cta_label' => 'ابدأ الآن',
            'cta_url' => null,
            'show_cta' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(Workspace $workspace, array $data): array
    {
        return [
            'company_name' => trim((string) ($data['company_name'] ?? $workspace->name)),
            'company_description' => $this->nullable($data['company_description'] ?? null),
            'primary_color' => $data['primary_color'] ?? '#0F172A',
            'secondary_color' => $data['secondary_color'] ?? '#2563EB',
            'background_color' => $data['background_color'] ?? '#F4F6FB',
            'text_color' => $data['text_color'] ?? '#111827',
            'header_style' => $data['header_style'] ?? 'solid',
            'show_header_logo' => (bool) ($data['show_header_logo'] ?? true),
            'show_header_company_name' => (bool) ($data['show_header_company_name'] ?? true),
            'header_links' => $this->normalizeHeaderLinks($data['header_links'] ?? []),
            'footer_email' => $this->nullable($data['footer_email'] ?? null),
            'footer_phone' => $this->nullable($data['footer_phone'] ?? null),
            'footer_website' => $this->nullable($data['footer_website'] ?? null),
            'footer_address' => $this->nullable($data['footer_address'] ?? null),
            'footer_copyright' => $this->nullable($data['footer_copyright'] ?? null),
            'show_footer_company_name' => (bool) ($data['show_footer_company_name'] ?? true),
            'show_footer_email' => (bool) ($data['show_footer_email'] ?? true),
            'show_footer_phone' => (bool) ($data['show_footer_phone'] ?? true),
            'show_footer_website' => (bool) ($data['show_footer_website'] ?? true),
            'show_footer_address' => (bool) ($data['show_footer_address'] ?? true),
            'show_footer_social' => (bool) ($data['show_footer_social'] ?? true),
            'show_footer_copyright' => (bool) ($data['show_footer_copyright'] ?? true),
            'social_linkedin' => $this->nullable($data['social_linkedin'] ?? null),
            'social_x' => $this->nullable($data['social_x'] ?? null),
            'social_facebook' => $this->nullable($data['social_facebook'] ?? null),
            'social_instagram' => $this->nullable($data['social_instagram'] ?? null),
            'social_youtube' => $this->nullable($data['social_youtube'] ?? null),
            'cta_label' => $this->nullable($data['cta_label'] ?? null),
            'cta_url' => $this->nullable($data['cta_url'] ?? null),
            'show_cta' => (bool) ($data['show_cta'] ?? false),
        ];
    }

    /**
     * @return list<array{label:string,url:string,visible:bool}>
     */
    public function defaultHeaderLinks(): array
    {
        return [
            ['label' => 'الرئيسية', 'url' => '', 'visible' => false],
            ['label' => 'خدماتنا', 'url' => '', 'visible' => false],
            ['label' => 'تواصل معنا', 'url' => '', 'visible' => false],
        ];
    }

    /**
     * @return list<array{label:string,url:string,visible:bool}>
     */
    private function normalizeHeaderLinks(mixed $links): array
    {
        if (! is_array($links)) {
            return $this->defaultHeaderLinks();
        }

        $normalized = [];

        foreach (array_slice($links, 0, 5) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $normalized[] = [
                'label' => trim((string) ($link['label'] ?? '')),
                'url' => trim((string) ($link['url'] ?? '')),
                'visible' => filter_var($link['visible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $normalized !== [] ? $normalized : $this->defaultHeaderLinks();
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
