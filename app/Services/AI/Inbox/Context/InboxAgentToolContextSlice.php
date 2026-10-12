<?php

namespace App\Services\AI\Inbox\Context;

final class InboxAgentToolContextSlice
{
    /**
     * @param  list<string>  $availableToolNames
     */
    public function __construct(
        public readonly bool $toolsEnabled,
        public readonly array $availableToolNames,
    ) {}
}
