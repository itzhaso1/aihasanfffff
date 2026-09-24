<?php

namespace App\Http\Requests\Commerce;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommerceCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'chat_catalog_item_id' => ['required', 'integer'],
            'chat_catalog_item_variant_id' => ['nullable', 'integer'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'selected_options' => ['nullable', 'array', 'max:30'],
            'metadata' => ['nullable', 'array', 'max:30'],
        ];
    }
}
