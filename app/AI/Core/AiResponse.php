<?php

declare(strict_types=1);

namespace App\AI\Core;

use LogicException;

/**
 * Result of one run. The runtime never sends it; callers deliver only when
 * {@see self::isDeliverable()} is true.
 */
final readonly class AiResponse
{
    private function __construct(
        public ?string $text,
        public AiResponseStatus $status,
        public AiDecision $decision,
    ) {
        if ($decision->status !== $status) {
            throw new LogicException('Response status must match its decision status.');
        }
    }

    public static function replied(string $text, AiDecision $decision): self
    {
        if (trim($text) === '') {
            throw new LogicException('A replied response requires text.');
        }

        return new self($text, AiResponseStatus::Replied, $decision);
    }

    public static function skipped(AiDecision $decision): self
    {
        return new self(null, AiResponseStatus::Skipped, $decision);
    }

    /**
     * @param  ?string  $text  Optional draft for staff; it is never marked deliverable.
     */
    public static function handedOff(AiDecision $decision, ?string $text = null): self
    {
        return new self($text, AiResponseStatus::HandedOff, $decision);
    }

    public static function failed(AiDecision $decision): self
    {
        return new self(null, AiResponseStatus::Failed, $decision);
    }

    /** True only for a guarded reply from a live run. */
    public function isDeliverable(): bool
    {
        return $this->status === AiResponseStatus::Replied
            && $this->decision->runMode->producesDeliverableReply()
            && $this->text !== null;
    }
}
