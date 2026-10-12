<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Models\Worker\WorkerEngagement;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkerEngagementGuideRequest extends FormRequest
{
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof WorkerEngagement
            && $this->user()?->can('manageGuide', $engagement) === true;
    }

    public function rules(): array
    {
        return WorkerEngagementGuideRules::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return WorkerEngagementGuideRules::messages();
    }

    protected function prepareForValidation(): void
    {
        $guide = WorkerEngagementGuideRules::prepare($this->input('guide'));

        if ($guide !== null) {
            $this->merge(['guide' => $guide]);
        }
    }
}
