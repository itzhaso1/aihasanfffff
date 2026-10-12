<?php

declare(strict_types=1);

namespace App\AI\Inbox;

use App\AI\Core\AiRequest;
use App\AI\Core\AiResponse;
use App\AI\Evaluation\EvaluationResult;

/**
 * Outcome of one Inbox Hasim run. An empty reply is not a customer-facing fallback;
 * callers must not send it.
 */
final readonly class HasimInboxResult
{
    public function __construct(
        public string $reply,
        public bool $deliverable,
        public ?AiRequest $request,
        public ?AiResponse $response,
        public ?EvaluationResult $evaluation,
    ) {}

    public static function fromRuntime(
        AiRequest $request,
        AiResponse $response,
        ?EvaluationResult $evaluation,
    ): self {
        $deliverable = $response->isDeliverable();

        return new self(
            reply: $deliverable ? (string) $response->text : '',
            deliverable: $deliverable,
            request: $request,
            response: $response,
            evaluation: $evaluation,
        );
    }

    public static function undeliverable(?AiRequest $request = null, ?AiResponse $response = null): self
    {
        return new self('', false, $request, $response, null);
    }
}
