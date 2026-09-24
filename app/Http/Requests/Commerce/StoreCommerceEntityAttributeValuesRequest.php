<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommerceEntityAttributeValuesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attributes' => ['required', 'array', 'min:1'],
            'attributes.*.definition_id' => ['required', 'integer'],
            'attributes.*.value' => ['nullable'],
            'attributes.*.unit' => ['nullable', 'string', 'max:32'],
            'attributes.*.source' => ['nullable', 'string', 'in:merchant_provided,system_derived,customer_provided,ai_inferred'],
        ];
    }
}
