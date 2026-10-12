<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformAiModelRequest extends FormRequest
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
        return [
            'provider' => ['required', 'string', 'max:64'],
            'model' => ['required', 'string', 'max:128'],
            'fallback_provider' => ['nullable', 'string', 'max:64'],
            'fallback_model' => ['nullable', 'string', 'max:128'],
        ];
    }
}
