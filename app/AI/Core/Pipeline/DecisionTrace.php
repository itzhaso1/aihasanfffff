<?php

declare(strict_types=1);

namespace App\AI\Core\Pipeline;

use App\AI\Core\AiDecision;
use App\AI\Core\AiRequest;
use App\AI\Core\AiResponseStatus;
use App\AI\Core\Data\GuardOutcome;
use App\AI\Core\Data\ResolvedEmployee;
use App\AI\Core\Decision\AiFailure;
use App\AI\Core\Decision\FactRecord;

/**
 * Collects what one run did, stage by stage, and turns it into an immutable
 * {@see AiDecision}. Holds identifiers and summaries only, never prompt text.
 */
final class DecisionTrace
{
    private ?ResolvedEmployee $employee = null;

    private ?AssembledPrompt $prompt = null;

    private ?TurnResult $turn = null;

    private ?GuardOutcome $guard = null;

    public function __construct(private readonly AiRequest $request) {}

    public function withEmployee(ResolvedEmployee $employee): void
    {
        $this->employee = $employee;
    }

    public function withPrompt(AssembledPrompt $prompt): void
    {
        $this->prompt = $prompt;
    }

    public function withTurn(TurnResult $turn): void
    {
        $this->turn = $turn;
    }

    public function withGuard(GuardOutcome $guard): void
    {
        $this->guard = $guard;
    }

    public function build(AiResponseStatus $status): AiDecision
    {
        return $this->decision($status, null);
    }

    public function failed(AiFailure $failure): AiDecision
    {
        return $this->decision(AiResponseStatus::Failed, $failure);
    }

    /**
     * Context facts plus tool facts, the business truth a reply may rely on.
     *
     * @return list<FactRecord>
     */
    public function facts(): array
    {
        return [...($this->prompt?->context->facts ?? []), ...($this->turn?->facts ?? [])];
    }

    private function decision(AiResponseStatus $status, ?AiFailure $failure): AiDecision
    {
        return new AiDecision(
            status: $status,
            runMode: $this->request->runMode,
            employeeId: $this->employee?->employeeId ?? $this->request->employeeId,
            employeeConfigVersion: $this->employee?->configVersion,
            constitutionVersion: $this->prompt?->constitutionVersion,
            trainingVersions: $this->prompt?->trainingVersions ?? [],
            intent: $this->prompt?->context->intent,
            resolvedReferences: $this->prompt?->context->references ?? [],
            toolCalls: $this->turn?->toolCalls ?? [],
            facts: $this->facts(),
            confirmedActions: $this->turn?->confirmedActions ?? [],
            guardResults: $this->guard?->results ?? [],
            modelUsage: $this->turn?->usage,
            failure: $failure,
            metadata: $this->metadata(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(): array
    {
        return array_filter([
            'workspace_id' => $this->request->workspaceId,
            'conversation_id' => $this->request->conversationId,
            'message_id' => $this->request->message->messageId,
            'channel' => $this->request->channel,
            'directive' => $this->prompt?->directive()->value,
            'prompt_fingerprint' => $this->prompt?->composition?->fingerprint(),
            'prompt_section_keys' => $this->prompt?->composition?->sectionKeys(),
            'finish_reason' => $this->turn?->finishReason?->value,
            'tool_rounds' => $this->turn?->toolRounds,
            'guard_verdict' => $this->guard?->verdict->value,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
