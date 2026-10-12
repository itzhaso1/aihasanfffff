<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Enums\WorkerEarningLineType;
use App\Enums\WorkerEarningSourceType;
use App\Models\Worker\WorkerEngagement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateWorkerEarningLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof WorkerEngagement
            && $this->user()?->can('manageEarnings', $engagement) === true;
    }

    public function rules(): array
    {
        return [
            'line_type' => ['required', Rule::enum(WorkerEarningLineType::class)],
            'source_type' => ['required', Rule::enum(WorkerEarningSourceType::class)],
            'source_id' => ['nullable', 'integer', 'min:1'],
            'source_reference' => ['nullable', 'string', 'max:191'],
            'amount_minor' => ['required', 'integer', 'not_in:0'],
            'currency' => ['required', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/'],
            'description' => ['nullable', 'string', 'max:500'],
            'adjustment_reason' => ['nullable', 'string', 'max:2000', 'required_if:line_type,adjustment'],
        ];
    }
}
