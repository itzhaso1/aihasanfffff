<?php

namespace App\Services\AI\Inbox\Context;

final class InboxAgentCatalogContextSlice
{
    /**
     * @param  list<array<string, mixed>>  $prefetchRows
     * @param  list<string>  $matchedNames
     * @param  array<string, mixed>  $untrustedCommunication
     */
    public function __construct(
        public readonly array $prefetchRows,
        public readonly array $matchedNames,
        public readonly bool $matched,
        public readonly array $untrustedCommunication = [],
    ) {}
}
