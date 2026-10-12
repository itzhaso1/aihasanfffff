<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Models\Conversation;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class AssignConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation
            && $this->user()?->can('manageHandoff', $conversation) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $workspaceId = app(WorkspaceContext::class)->workspaceId();

        return [
            'user_id' => [
                'nullable',
                'integer',
                'required_without:worker_engagement_id',
                'exists:users,id',
            ],
            'worker_engagement_id' => [
                'nullable',
                'integer',
                'required_without:user_id',
                Rule::exists('worker_engagements', 'id')->where(
                    fn ($query) => $query->where('workspace_id', $workspaceId),
                ),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('user_id') && $this->filled('worker_engagement_id')) {
                $validator->errors()->add(
                    'worker_engagement_id',
                    'Choose a company staff user or an independent worker engagement.',
                );
            }
        });
    }
}
