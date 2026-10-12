<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\WorkerEngagementDurationUnit;
use App\Models\PlatformAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkerContractRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform_admin') instanceof PlatformAdmin;
    }

    public function rules(): array
    {
        return [
            'worker_profile_id' => ['required', 'integer', Rule::exists('worker_profiles', 'id')],
            'duration_value' => ['required', 'integer', 'min:1'],
            'duration_unit' => ['required', Rule::in(WorkerEngagementDurationUnit::values())],
            'amount' => ['required', 'decimal:0,2', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'worker_profile_id.required' => 'اختر العامل.',
            'duration_value.required' => 'أدخل قيمة مدة التعاقد.',
            'duration_unit.in' => 'وحدة مدة التعاقد غير مدعومة.',
            'amount.required' => 'أدخل سعر التعاقد.',
            'currency.size' => 'عملة سعر التعاقد يجب أن تكون ثلاثة أحرف.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('currency')) {
            $this->merge([
                'currency' => strtoupper(trim((string) $this->input('currency'))),
            ]);
        }
    }
}
