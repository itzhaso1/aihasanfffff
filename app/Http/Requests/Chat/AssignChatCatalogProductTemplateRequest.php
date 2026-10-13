<?php

namespace App\Http\Requests\Chat;

use Illuminate\Foundation\Http\FormRequest;

class AssignChatCatalogProductTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_template_id' => ['required', 'integer', 'exists:chat_catalog_product_templates,id'],
        ];
    }
}
