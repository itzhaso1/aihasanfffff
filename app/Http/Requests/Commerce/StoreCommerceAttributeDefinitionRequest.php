<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommerceAttributeDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[a-z0-9_]{2,64}$/'],
            'display_name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:1000'],
            'data_type' => ['required', 'string', 'in:string,integer,decimal,boolean,enum,date,datetime,measurement,dimension,range,reference,multi_value'],
            'unit' => ['nullable', 'string', 'max:32'],
            'is_required' => ['nullable', 'boolean'],
            'is_searchable' => ['nullable', 'boolean'],
            'is_filterable' => ['nullable', 'boolean'],
            'is_customer_visible' => ['nullable', 'boolean'],
            'is_ai_readable' => ['nullable', 'boolean'],
            'validation_constraints' => ['nullable', 'array'],
            'enum_options' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
