<?php

namespace Tests\Support;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\AI\Inbox\Tools\InboxAgentToolExecutionResult;
use App\Services\AI\Inbox\Tools\InboxAgentToolSecurityProfile;

/**
 * Architecture-only test tool (not registered in production).
 */
final class InboxAgentArchitectureEchoTool implements InboxAgentSecuredTool
{
    public function name(): string
    {
        return 'architecture_echo';
    }

    public function description(): string
    {
        return 'Echoes a message for architecture tests.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Read,
            accessMode: InboxAgentToolAccessMode::Read,
            requiresConfirmation: false,
            maxCallsPerRun: 5,
        );
    }

    public function inputSchema(): array
    {
        return [
            'message' => ['type' => 'string', 'required' => true],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'echo_call',
            [
                'message' => (string) ($arguments['message'] ?? ''),
            ],
        );
    }
}
