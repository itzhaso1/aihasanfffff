<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Models\Conversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $conversation = $this->route('conversation');

        return $conversation instanceof Conversation
            && $this->user()?->can('update', $conversation) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:open,closed,archived'],
            'ai_enabled' => ['nullable', 'boolean'],
            'metadata_json' => ['nullable', 'string'],
        ];
    }
}
