<?php

namespace App\Http\Requests\Chat;

use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldLevel;
use App\Enums\ChatCatalog\ChatCatalogProductTemplateFieldType;
use App\Enums\ChatCatalog\ChatCatalogProductTemplateStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatCatalogProductTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:128'],
            'classification_hint' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', Rule::enum(ChatCatalogProductTemplateStatus::class)],
            'fields' => ['nullable', 'array', 'max:50'],
            'fields.*.field_key' => ['required_with:fields', 'string', 'max:64'],
            'fields.*.label' => ['nullable', 'array'],
            'fields.*.label.ar' => ['nullable', 'string', 'max:255'],
            'fields.*.label.en' => ['nullable', 'string', 'max:255'],
            'fields.*.field_type' => ['required_with:fields', Rule::enum(ChatCatalogProductTemplateFieldType::class)],
            'fields.*.level' => ['nullable', Rule::enum(ChatCatalogProductTemplateFieldLevel::class)],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.searchable' => ['nullable', 'boolean'],
            'fields.*.filterable' => ['nullable', 'boolean'],
            'fields.*.customer_visible' => ['nullable', 'boolean'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.validation' => ['nullable', 'array'],
            'fields.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
