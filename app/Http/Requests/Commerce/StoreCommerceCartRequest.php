<?php

namespace App\Http\Requests\Commerce;

use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCommerceCartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        return [
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId),
                ),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'channel' => ['nullable', 'in:chat,whatsapp,instagram,web,marketplace'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'metadata' => ['nullable', 'array', 'max:30'],
        ];
    }
}
