<?php

namespace App\Http\Requests\Order;

use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        $workspaceRule = static fn (string $table): array => $workspaceId
            ? [Rule::exists($table, 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId))]
            : ['exists:'.$table.',id'];

        return [
            'customer_id' => ['nullable', 'integer', ...$workspaceRule('customers')],
            'dining_table_id' => ['nullable', 'integer', ...$workspaceRule('dining_tables')],
            'table_session_id' => ['nullable', 'integer', ...$workspaceRule('table_sessions')],
            'currency' => ['required', 'string', 'size:3'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,confirmed,cancelled,completed'],
            'source' => ['nullable', 'in:manual,pos,qr_menu,api'],
            'pos_status' => ['nullable', 'in:new,accepted,preparing,ready,delivered,completed,cancelled'],
            'metadata' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', ...$workspaceRule('products')],
            'items.*.product_variant_id' => ['nullable', 'integer', ...$workspaceRule('product_variants')],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
