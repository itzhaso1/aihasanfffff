<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use App\Enums\WorkerPayoutStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkerPayoutStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('platform_admin') !== null;
    }

    public function rules(): array
    {
        $status = $this->input('status');

        return [
            'status' => ['required', Rule::enum(WorkerPayoutStatus::class)],
            'provider_reference' => [
                'nullable',
                'string',
                'max:191',
                Rule::requiredIf($status === WorkerPayoutStatus::Paid->value),
            ],
            'reason' => [
                'nullable',
                'string',
                'max:2000',
                Rule::requiredIf(in_array($status, [
                    WorkerPayoutStatus::Failed->value,
                    WorkerPayoutStatus::Cancelled->value,
                ], true)),
            ],
        ];
    }
}
