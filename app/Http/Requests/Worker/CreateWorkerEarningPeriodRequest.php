<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Models\Worker\WorkerEngagement;
use Illuminate\Foundation\Http\FormRequest;

class CreateWorkerEarningPeriodRequest extends FormRequest
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
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ];
    }
}
