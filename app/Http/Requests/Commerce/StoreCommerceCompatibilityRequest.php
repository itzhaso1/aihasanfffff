<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommerceCompatibilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_entity_type' => ['nullable', 'string', 'max:64'],
            'source_entity_id' => ['required', 'integer'],
            'target_type' => ['nullable', 'string', 'in:entity,reference_model,specification'],
            'target_entity_type' => ['nullable', 'string', 'max:64'],
            'target_entity_id' => ['nullable', 'integer'],
            'target_reference' => ['nullable', 'array'],
            'compatibility_type' => ['required', 'string', 'in:compatible,incompatible,requires_adapter,conditional'],
            'constraints' => ['nullable', 'array'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'provenance' => ['nullable', 'string', 'in:merchant_provided,system_derived,ai_inferred'],
        ];
    }
}
