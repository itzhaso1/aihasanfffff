<?php

namespace App\Events\Conversation;

use App\Models\Conversation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ConversationHandoffRequested
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly array $summary,
    ) {}
}
