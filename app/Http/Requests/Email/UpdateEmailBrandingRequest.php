<?php

namespace App\Http\Requests\Email;

use App\Models\EmailBranding;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();
        if (! $workspace || ! $this->user()) {
            return false;
        }

        $branding = EmailBranding::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();

        return $branding
            ? ($this->user()->can('update', $branding))
            : ($this->user()->can('create', EmailBranding::class));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hex = ['required', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'];
        $httpUrl = ['nullable', 'string', 'max:255', 'url:http,https'];

        return [
            'company_name' => ['required', 'string', 'max:120'],
            'company_description' => ['nullable', 'string', 'max:500'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_logo' => ['nullable', 'boolean'],
            'primary_color' => $hex,
            'secondary_color' => $hex,
            'background_color' => $hex,
            'text_color' => $hex,
            'header_style' => ['required', Rule::in(EmailBranding::HEADER_STYLES)],
            'show_header_logo' => ['nullable', 'boolean'],
            'show_header_company_name' => ['nullable', 'boolean'],
            'header_links' => ['nullable', 'array', 'max:5'],
            'header_links.*.label' => ['nullable', 'string', 'max:40'],
            'header_links.*.url' => $httpUrl,
            'header_links.*.visible' => ['nullable', 'boolean'],
            'footer_email' => ['nullable', 'email', 'max:255'],
            'footer_phone' => ['nullable', 'string', 'max:40'],
            'footer_website' => $httpUrl,
            'footer_address' => ['nullable', 'string', 'max:255'],
            'footer_copyright' => ['nullable', 'string', 'max:255'],
            'show_footer_company_name' => ['nullable', 'boolean'],
            'show_footer_email' => ['nullable', 'boolean'],
            'show_footer_phone' => ['nullable', 'boolean'],
            'show_footer_website' => ['nullable', 'boolean'],
            'show_footer_address' => ['nullable', 'boolean'],
            'show_footer_social' => ['nullable', 'boolean'],
            'show_footer_copyright' => ['nullable', 'boolean'],
            'social_linkedin' => $httpUrl,
            'social_x' => $httpUrl,
            'social_facebook' => $httpUrl,
            'social_instagram' => $httpUrl,
            'social_youtube' => $httpUrl,
            'cta_label' => ['nullable', 'string', 'max:40'],
            'cta_url' => $httpUrl,
            'show_cta' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['primary_color', 'secondary_color', 'background_color', 'text_color'] as $colorField) {
            $normalized = $this->normalizeHex($this->input($colorField));
            if ($normalized !== null) {
                $this->merge([$colorField => $normalized]);
            }
        }

        foreach (['footer_website', 'cta_url', 'social_linkedin', 'social_x', 'social_facebook', 'social_instagram', 'social_youtube'] as $urlField) {
            if (trim((string) $this->input($urlField, '')) === '') {
                $this->merge([$urlField => null]);
            }
        }

        $links = $this->input('header_links', []);
        if (is_array($links)) {
            foreach ($links as $index => $link) {
                if (is_array($link) && trim((string) ($link['url'] ?? '')) === '') {
                    $links[$index]['url'] = null;
                }
            }
            $this->merge(['header_links' => $links]);
        }

        foreach ([
            'show_header_logo',
            'show_header_company_name',
            'show_footer_company_name',
            'show_footer_email',
            'show_footer_phone',
            'show_footer_website',
            'show_footer_address',
            'show_footer_social',
            'show_footer_copyright',
            'show_cta',
            'remove_logo',
        ] as $flag) {
            if ($this->exists($flag)) {
                $this->merge([$flag => $this->boolean($flag)]);
            }
        }
    }

    private function normalizeHex(mixed $value): ?string
    {
        $color = strtoupper(trim((string) $value));
        if ($color === '') {
            return null;
        }

        if ($color[0] !== '#') {
            $color = '#'.$color;
        }

        if (preg_match('/^#([A-F0-9]{3})$/', $color, $match) === 1) {
            $digits = $match[1];

            return '#'.$digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        }

        return $color;
    }
}
