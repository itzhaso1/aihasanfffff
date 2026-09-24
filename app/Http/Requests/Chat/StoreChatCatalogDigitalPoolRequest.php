<?php

namespace App\Http\Requests\Chat;

use App\Support\Chat\ChatCatalogAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreChatCatalogDigitalPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = app(WorkspaceContext::class)->workspace();

        return $workspace !== null
            && $this->user() !== null
            && app(ChatCatalogAccess::class)->canManageDigitalInventory($this->user(), $workspace);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        return [
            'chat_catalog_item_id' => [
                'required',
                'integer',
                Rule::exists('chat_catalog_items', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'chat_catalog_item_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('chat_catalog_item_variants', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'name' => ['required', 'string', 'max:255'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'reservation_timeout_seconds' => ['nullable', 'integer', 'between:60,86400'],
        ];
    }
}
