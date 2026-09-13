<?php

namespace App\Http\Requests\Inventory;

use App\Support\Authorization\WorkspaceAccess;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workspace = app(WorkspaceContext::class)->workspace();

        if (! $user || ! $workspace) {
            return false;
        }

        return app(WorkspaceAccess::class)->canManageInventory($user, $workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        return [
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
            'type' => ['required', 'in:add,remove,reserve,release,adjustment,return'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference_type' => ['nullable', 'string', 'max:64'],
            'reference_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
