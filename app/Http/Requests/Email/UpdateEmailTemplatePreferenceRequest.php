<?php

namespace App\Http\Requests\Email;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailTemplatePreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        foreach (['primaryColor', 'secondaryColor', 'backgroundColor', 'textColor'] as $colorField) {
            if (! $this->exists($colorField)) {
                continue;
            }

            $normalized = $this->normalizeHex($this->input($colorField));
            if ($normalized !== null) {
                $this->merge([$colorField => $normalized]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'companyName' => ['nullable', 'string', 'max:120'],
            'companyDescription' => ['nullable', 'string', 'max:500'],
            'customerName' => ['nullable', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:180'],
            'body' => ['nullable', 'string', 'max:5000'],
            'ctaLabel' => ['nullable', 'string', 'max:40'],
            'ctaUrl' => ['nullable', 'string', 'max:255'],
            'primaryColor' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'secondaryColor' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'backgroundColor' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'textColor' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
            'showCta' => ['nullable', 'boolean'],
            'showHeaderLogo' => ['nullable', 'boolean'],
            'showHeaderCompanyName' => ['nullable', 'boolean'],
            'showFooterCompanyName' => ['nullable', 'boolean'],
            'showFooterEmail' => ['nullable', 'boolean'],
            'showFooterPhone' => ['nullable', 'boolean'],
            'showFooterWebsite' => ['nullable', 'boolean'],
            'showFooterAddress' => ['nullable', 'boolean'],
            'showFooterSocial' => ['nullable', 'boolean'],
            'showFooterCopyright' => ['nullable', 'boolean'],
        ];
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
