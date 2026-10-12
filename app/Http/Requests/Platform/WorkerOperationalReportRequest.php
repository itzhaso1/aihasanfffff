<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\WorkerEngagementStatus;
use App\Models\PlatformAdmin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class WorkerOperationalReportRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(WorkerEngagementStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
