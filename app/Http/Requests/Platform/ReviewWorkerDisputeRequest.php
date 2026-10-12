<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Models\PlatformAdmin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class ReviewWorkerDisputeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform_admin') instanceof PlatformAdmin;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'review_notes' => ['nullable', 'string', 'max:5000'],
            'resolution_summary' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
