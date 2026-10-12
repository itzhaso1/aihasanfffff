<?php

namespace App\Http\Requests\Platform;

use App\Models\PlatformAdmin;
use App\Models\Worker\WorkerVerificationIssue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ReviewWorkerVerificationRequest extends FormRequest
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
            'reason' => ['required', 'string', 'max:2000'],
            'issues' => ['sometimes', 'array', 'max:20'],
            'issues.*.target_type' => [
                'required',
                'string',
                Rule::in([
                    WorkerVerificationIssue::TARGET_PROFILE,
                    WorkerVerificationIssue::TARGET_SKILLS,
                    WorkerVerificationIssue::TARGET_LANGUAGES,
                    WorkerVerificationIssue::TARGET_EXPERIENCE,
                    WorkerVerificationIssue::TARGET_IDENTITY,
                    WorkerVerificationIssue::TARGET_DOCUMENT,
                ]),
            ],
            'issues.*.target_id' => ['nullable', 'integer', 'min:1'],
            'issues.*.field_key' => ['nullable', 'string', 'max:128'],
            'issues.*.reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
