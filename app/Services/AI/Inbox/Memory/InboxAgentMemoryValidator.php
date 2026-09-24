<?php

namespace App\Services\AI\Inbox\Memory;

use App\Enums\InboxAgentMemoryEvidenceType;
use App\Enums\InboxAgentMemoryScope;
use App\Enums\InboxAgentMemoryValueType;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workspace;

final class InboxAgentMemoryValidator
{
    public function __construct(
        private readonly InboxAgentMemoryPolicy $policy,
        private readonly InboxAgentMemoryInstructionGuard $instructionGuard,
        private readonly InboxAgentMemoryAuthorityGuard $authorityGuard,
        private readonly InboxAgentMemoryValueCodec $codec,
    ) {}

    public function validateCandidate(
        InboxAgentMemoryCandidate $candidate,
        Workspace $workspace,
        Conversation $conversation,
        ?Customer $customer,
        ?Message $sourceMessage,
    ): InboxAgentMemoryCandidate {
        if ($candidate->confidence < $this->policy->minConfidence()) {
            throw InboxAgentMemoryValidationException::forField('confidence', 'below minimum threshold.');
        }

        if ($candidate->evidenceType === InboxAgentMemoryEvidenceType::Inferred && ! $this->policy->allowInferred()) {
            throw InboxAgentMemoryValidationException::forField('evidence_type', 'inferred memories are disabled.');
        }

        if (! InboxAgentMemoryCatalog::isAllowedKey($candidate->key, $candidate->scope->value)) {
            throw InboxAgentMemoryValidationException::forField('key', 'unsupported for scope.');
        }

        if ($candidate->scope === InboxAgentMemoryScope::Customer && $customer === null) {
            throw InboxAgentMemoryValidationException::forField('scope', 'customer memory requires a customer.');
        }

        if ($candidate->scope === InboxAgentMemoryScope::Conversation && $conversation->id === null) {
            throw InboxAgentMemoryValidationException::forField('scope', 'conversation memory requires a conversation.');
        }

        if ($sourceMessage !== null && (int) $sourceMessage->workspace_id !== (int) $workspace->id) {
            throw InboxAgentMemoryValidationException::forField('source', 'message workspace mismatch.');
        }

        if ($customer !== null && (int) $customer->workspace_id !== (int) $workspace->id) {
            throw InboxAgentMemoryValidationException::forField('source', 'customer workspace mismatch.');
        }

        if ((int) $conversation->workspace_id !== (int) $workspace->id) {
            throw InboxAgentMemoryValidationException::forField('source', 'conversation workspace mismatch.');
        }

        $this->assertValue($candidate);

        return $candidate;
    }

    private function assertValue(InboxAgentMemoryCandidate $candidate): void
    {
        $display = $this->codec->toDisplayString($candidate->value, $candidate->valueType);
        if ($display === '') {
            throw InboxAgentMemoryValidationException::forField('value', 'cannot be empty.');
        }

        if (mb_strlen($display) > $this->policy->maxValueChars()) {
            throw InboxAgentMemoryValidationException::forField('value', 'exceeds max length.');
        }

        if ($this->instructionGuard->looksLikeInstruction($display)) {
            throw InboxAgentMemoryValidationException::forField('value', 'looks like an instruction, not a memory fact.');
        }

        if ($this->authorityGuard->looksLikeAuthorityClaim($display)) {
            throw InboxAgentMemoryValidationException::forField('value', 'looks like an authority claim, not a memory fact.');
        }

        match ($candidate->valueType) {
            InboxAgentMemoryValueType::String => $this->assertString($candidate->value),
            InboxAgentMemoryValueType::Number => $this->assertNumber($candidate->value),
            InboxAgentMemoryValueType::Boolean => $this->assertBoolean($candidate->value),
            InboxAgentMemoryValueType::Json => $this->assertJson($candidate->value),
        };
    }

    private function assertString(mixed $value): void
    {
        if (! is_string($value)) {
            throw InboxAgentMemoryValidationException::forField('value', 'must be a string.');
        }
    }

    private function assertNumber(mixed $value): void
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw InboxAgentMemoryValidationException::forField('value', 'must be numeric.');
        }
    }

    private function assertBoolean(mixed $value): void
    {
        if (! is_bool($value)) {
            throw InboxAgentMemoryValidationException::forField('value', 'must be boolean.');
        }
    }

    private function assertJson(mixed $value): void
    {
        if (! is_array($value)) {
            throw InboxAgentMemoryValidationException::forField('value', 'must be a JSON object/array.');
        }
    }
}
