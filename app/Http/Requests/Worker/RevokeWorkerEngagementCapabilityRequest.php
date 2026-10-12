<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Models\Worker\WorkerEngagement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RevokeWorkerEngagementCapabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        $engagement = $this->route('engagement');

        return $engagement instanceof WorkerEngagement
            && $this->user()?->can('manageCapabilities', $engagement) === true;
    }

    public function rules(): array
    {
        return [
            'capability_key' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
                Rule::exists('workforce_capability_definitions', 'capability_key'),
            ],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'capability_key' => $this->route('capability'),
        ]);
    }
}
