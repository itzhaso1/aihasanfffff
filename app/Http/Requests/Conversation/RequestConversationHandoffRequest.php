<?php

declare(strict_types=1);

namespace App\Http\Requests\Conversation;

use App\Enums\ConversationHandoffReason;
use App\Models\Conversation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RequestConversationHandoffRequest extends FormRequest
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
        return [
            'reason' => ['required', 'string', Rule::enum(ConversationHandoffReason::class)],
        ];
    }
}
