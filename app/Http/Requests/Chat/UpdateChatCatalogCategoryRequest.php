<?php

namespace App\Http\Requests\Chat;

class UpdateChatCatalogCategoryRequest extends StoreChatCatalogCategoryRequest
{
    public function authorize(): bool
    {
        $category = $this->route('category');

        return $category ? ($this->user()?->can('update', $category) ?? false) : false;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = ['sometimes', 'required', 'string', 'max:255'];

        return $rules;
    }
}
