<?php

namespace App\Http\Requests\Chat;

use App\Models\ChatCatalogCategory;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatCatalogCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ChatCatalogCategory::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        return [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('chat_catalog_categories', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'slug' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'image_path' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:draft,published,archived'],
            'is_visible' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'seo' => ['nullable', 'array', 'max:10'],
            'seo.title' => ['nullable', 'string', 'max:255'],
            'seo.description' => ['nullable', 'string', 'max:500'],
            'seo.canonical_slug' => ['nullable', 'string', 'max:255'],
            'seo.robots' => ['nullable', Rule::in(['index,follow', 'noindex', 'nofollow'])],
            'seo.share_title' => ['nullable', 'string', 'max:255'],
            'seo.share_description' => ['nullable', 'string', 'max:500'],
            'seo.share_image' => ['nullable', 'string', 'max:500'],
            'localization' => ['nullable', 'array', 'max:20'],
            'localization.ar' => ['nullable', 'array:title,name,description'],
            'localization.ar.name' => ['nullable', 'string', 'max:255'],
            'localization.ar.description' => ['nullable', 'string', 'max:5000'],
            'localization.en' => ['nullable', 'array:title,name,description'],
            'localization.en.name' => ['nullable', 'string', 'max:255'],
            'localization.en.description' => ['nullable', 'string', 'max:5000'],
            'aliases' => ['nullable', 'array', 'max:100'],
            'aliases.*' => ['string', 'max:255'],
        ];
    }
}
