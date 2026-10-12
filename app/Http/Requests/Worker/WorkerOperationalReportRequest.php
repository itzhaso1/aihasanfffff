<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Enums\WorkerActivityEventType;
use App\Enums\WorkerEngagementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class WorkerOperationalReportRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(WorkerEngagementStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'event_type' => ['nullable', Rule::enum(WorkerActivityEventType::class)],
            'session_id' => ['nullable', 'integer', 'min:1'],
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
                    'The operational report date range cannot exceed 31 days.',
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

        return CarbonImmutable::createFromFormat('Y-m-d', trim($value)) ?: null;
    }
}
