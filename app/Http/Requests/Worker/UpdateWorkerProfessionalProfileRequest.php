<?php

declare(strict_types=1);

namespace App\Http\Requests\Worker;

use App\Models\Worker\WorkerProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkerProfessionalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->workerProfile !== null;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'country_code' => ['required', 'string', Rule::in(WorkerProfile::COUNTRIES)],
            'nationality' => ['required', 'string', 'max:100'],
            'professional_summary' => ['required', 'string', 'max:5000'],
            'sales_experience_level' => ['required', 'string', 'max:32'],
            'availability_status' => [
                'required',
                'string',
                Rule::in([
                    WorkerProfile::AVAILABILITY_AVAILABLE,
                    WorkerProfile::AVAILABILITY_LIMITED,
                    WorkerProfile::AVAILABILITY_UNAVAILABLE,
                ]),
            ],
        ];
    }
}
