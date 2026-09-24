<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePublicSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('platform_admin')->check();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $url = ['nullable', 'string', 'max:255', 'url:http,https'];

        return [
            'name_ar' => ['required', 'string', 'max:80'],
            'name_en' => ['required', 'string', 'max:80'],
            'app_name' => ['required', 'string', 'max:40'],
            'tagline' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'footer_company' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'commercial_registration' => ['required', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:120'],
            'tax_number' => ['nullable', 'string', 'max:32'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
            'favicon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_favicon' => ['sometimes', 'boolean'],
            'chat_google_play_url' => $url,
            'chat_windows_url' => $url,
            'chat_app_store_url' => $url,
            'finance_google_play_url' => $url,
            'finance_windows_url' => $url,
            'finance_app_store_url' => $url,
            'cashier_google_play_url' => $url,
            'cashier_windows_url' => $url,
            'cashier_app_store_url' => $url,
        ];
    }
}
