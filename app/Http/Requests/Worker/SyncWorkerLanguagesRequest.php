<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncWorkerLanguagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->workerProfile !== null;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('language_codes')) {
            $this->merge(['language_codes' => []]);
        }
    }

    public function rules(): array
    {
        $codes = collect(config('worker_professional.languages', []))
            ->pluck('code')
            ->all();

        return [
            'language_codes' => ['present', 'array'],
            'language_codes.*' => ['string', Rule::in($codes)],
        ];
    }
}
