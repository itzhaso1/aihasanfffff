<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncWorkerSkillsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->workerProfile !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('skill_ids')) {
            $this->merge(['skill_ids' => []]);
        }
    }

    public function rules(): array
    {
        return [
            'skill_ids' => ['present', 'array'],
            'skill_ids.*' => ['integer', Rule::exists('worker_skills', 'id')],
        ];
    }
}
