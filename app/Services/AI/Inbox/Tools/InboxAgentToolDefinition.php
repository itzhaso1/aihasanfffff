<?php

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentTool;

/**
 * Provider-neutral tool definition exposed to LLM adapters later.
 */
final class InboxAgentToolDefinition
{
    /**
     * @param  array<string, array{type: string, required?: bool, description?: string}>  $inputSchema
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly InboxAgentToolRiskLevel $riskLevel = InboxAgentToolRiskLevel::Read,
        public readonly InboxAgentToolAccessMode $accessMode = InboxAgentToolAccessMode::Read,
        public readonly bool $requiresConfirmation = false,
        public readonly int $maxCallsPerRun = 3,
    ) {}

    public static function fromTool(InboxAgentTool $tool): self
    {
        $profile = InboxAgentToolSecurityProfile::forTool($tool);

        return new self(
            name: $tool->name(),
            description: $tool->description(),
            inputSchema: $tool->inputSchema(),
            riskLevel: $profile->riskLevel,
            accessMode: $profile->accessMode,
            requiresConfirmation: $profile->requiresConfirmation,
            maxCallsPerRun: $profile->maxCallsPerRun,
        );
    }

    /**
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
            'risk_level' => $this->riskLevel->value,
            'access_mode' => $this->accessMode->value,
            'requires_confirmation' => $this->requiresConfirmation,
            'max_calls_per_run' => $this->maxCallsPerRun,
        ];
    }
}
