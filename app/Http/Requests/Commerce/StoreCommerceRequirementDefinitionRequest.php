<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommerceRequirementDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[a-z0-9_]{2,64}$/'],
            'label' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_type' => ['required', 'string', 'in:string,integer,decimal,boolean,enum,date,datetime,measurement,dimension'],
            'unit' => ['nullable', 'string', 'max:32'],
            'is_required' => ['nullable', 'boolean'],
            'validation_rules' => ['nullable', 'array'],
            'applicability' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
