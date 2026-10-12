<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Enums\WorkerDisputeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class WorkerDisputeFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(WorkerDisputeStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            $from = $this->dateInput('from');
            $to = $this->dateInput('to');

            if ($from !== null && $to !== null && $from->diffInDays($to) > 31) {
                $validator->errors()->add(
                    'to',
                    'The dispute date range cannot exceed 31 days.',
                );
            }
        });
    }

    private function dateInput(string $key): ?CarbonImmutable
    {
        $value = $this->input($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date instanceof CarbonImmutable ? $date : null;
    }
}
