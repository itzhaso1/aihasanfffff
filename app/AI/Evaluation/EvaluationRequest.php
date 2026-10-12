<?php

declare(strict_types=1);

namespace App\AI\Evaluation;

use App\AI\Core\AiDecision;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiResponse;
use App\AI\Core\AiRunMode;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\ModelFinishReason;

final readonly class EvaluationRequest
{
    public function __construct(
        public AiDecision $decision,
        public ?string $text = null,
        public ?string $customerMessage = null,
        public EvaluationExpectations $expectations = new EvaluationExpectations,
        public ?ModelFinishReason $finishReason = null,
    ) {}

    public static function fromResponse(
        AiResponse $response,
        EvaluationExpectations $expectations = new EvaluationExpectations,
        ?string $customerMessage = null,
        ?ModelFinishReason $finishReason = null,
    ): self {
        return new self($response->decision, $response->text, $customerMessage, $expectations, $finishReason);
    }

    public function candidateText(): string
    {
        return trim((string) $this->text);
    }

    public function guardInput(): GuardInput
    {
        $message = $this->customerMessage !== null && trim($this->customerMessage) !== ''
            ? $this->customerMessage
            : ($this->candidateText() !== '' ? $this->candidateText() : 'n/a');

        return new GuardInput(
            request: new AiRequest(
                workspaceId: 1,
                conversationId: $this->decision->runMode === AiRunMode::Live ? 1 : null,
                employeeId: $this->decision->employeeId,
                message: new AiInboundMessage($message),
                runMode: $this->decision->runMode,
            ),
            text: $this->candidateText() !== '' ? $this->candidateText() : 'n/a',
            facts: $this->decision->facts,
            toolCalls: $this->decision->toolCalls,
            confirmedActions: $this->decision->confirmedActions,
            finishReason: $this->finishReason,
        );
    }
}
