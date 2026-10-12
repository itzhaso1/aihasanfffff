<?php

declare(strict_types=1);

namespace App\AI\Tools;

use App\AI\Core\Decision\ConfirmedAction;
use App\AI\Core\Decision\FactRecord;
use App\AI\Core\Support\TypedList;

final readonly class RuntimeToolResult
{
    /** @var list<FactRecord> */
    public array $facts;

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<FactRecord>  $facts
     */
    public function __construct(
        public bool $success,
        public array $payload,
        public ?string $errorCode = null,
        array $facts = [],
        public ?ConfirmedAction $confirmedAction = null,
    ) {
        if ($success && $errorCode !== null) {
            throw ToolRegistryException::invalid('A successful tool result cannot carry an error code.');
        }
        $this->facts = TypedList::of($facts, FactRecord::class, 'Runtime tool facts');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function ok(array $payload, array $facts = [], ?ConfirmedAction $confirmedAction = null): self
    {
        return new self(true, $payload, null, $facts, $confirmedAction);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fail(string $errorCode, string $message, array $payload = []): self
    {
        return new self(false, $payload === [] ? ['message' => $message] : $payload, $errorCode);
    }
}
