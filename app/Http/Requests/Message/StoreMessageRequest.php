<?php

namespace App\Http\Requests\Message;

use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMessageRequest extends FormRequest
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

        return [
            'conversation_id' => [
                'required',
                'integer',
                Rule::exists('conversations', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
            'customer_id' => [
                'nullable',
                'integer',
                Rule::exists('customers', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId)
                ),
            ],
            'direction' => ['required', 'in:inbound,outbound,internal_note'],
            'message_type' => ['nullable', 'in:text,image,file,system'],
            'content' => ['nullable', 'string'],
            'external_message_id' => ['nullable', 'string', 'max:255'],
            'ai_generated' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
