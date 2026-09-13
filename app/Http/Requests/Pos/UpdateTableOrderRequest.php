<?php

namespace App\Http\Requests\Pos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTableOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            // Existing line (id) OR new catalog line (pos_menu_item_id).
            'items.*.id' => ['nullable', 'integer', 'required_without:items.*.pos_menu_item_id'],
            'items.*.pos_menu_item_id' => ['nullable', 'integer', 'required_without:items.*.id'],
            'items.*.quantity' => ['required_unless:items.*.remove,1,true', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.remove' => ['nullable', 'boolean'],
        ];
    }
}
