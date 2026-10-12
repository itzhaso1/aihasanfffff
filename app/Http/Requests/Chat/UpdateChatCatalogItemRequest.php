<?php

namespace App\Http\Requests\Chat;

class UpdateChatCatalogItemRequest extends StoreChatCatalogItemRequest
{
    public function authorize(): bool
    {
        $item = $this->route('item');

        return $item ? ($this->user()?->can('update', $item) ?? false) : false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];
        $rules['price'] = ['sometimes', 'decimal:0,2', 'min:0'];

        return $rules;
    }
}
