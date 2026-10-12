<?php

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Commerce\Intelligence\CommerceCustomerContextService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbox agent tool to store explicit customer commerce context parameters.
 */
final class SaveCustomerCommerceContextTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CommerceCustomerContextService $customerContextService,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function name(): string
    {
        return 'save_customer_commerce_context';
    }

    public function description(): string
    {
        return 'Store explicit customer commerce context parameters (e.g., measurements, vehicle model, room dimensions) for future recommendation evaluations.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Read,
            accessMode: InboxAgentToolAccessMode::Read,
            requiresConfirmation: false,
            maxCallsPerRun: 3,
        );
    }

    public function inputSchema(): array
    {
        return [
            'context_attributes' => [
                'type' => 'object',
                'required' => true,
                'description' => 'Key-value pairs of explicit customer context (e.g., {"bust_cm": 90, "waist_cm": 70, "vehicle_model": "Camry"}).',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $attributes = is_array($arguments['context_attributes'] ?? null) ? $arguments['context_attributes'] : [];

        if ($attributes === []) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'save_customer_commerce_context',
                'invalid_arguments',
                'Non-empty context_attributes object is required.',
            );
        }

        try {
            return $this->workspaceContext->runFor($context->workspace, function () use ($context, $attributes): InboxAgentToolExecutionResult {
                $customerId = $context->conversation->customer_id ? (int) $context->conversation->customer_id : null;
                $sessionId = (string) ($context->conversation->external_id ?? '');
                $savedKeys = [];

                foreach ($attributes as $key => $val) {
                    if ($val !== null && $val !== '') {
                        $this->customerContextService->setContext(
                            workspace: $context->workspace,
                            customerId: $customerId,
                            sessionId: $sessionId !== '' ? $sessionId : null,
                            key: (string) $key,
                            value: $val,
                        );
                        $savedKeys[] = (string) $key;
                    }
                }

                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'save_customer_commerce_context',
                    [
                        'saved' => true,
                        'keys' => $savedKeys,
                        'customer_id' => $customerId,
                    ],
                );
            });
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.save_customer_commerce_context_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'message' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'save_customer_commerce_context',
                'storage_failed',
                'Saving customer context is temporarily unavailable.',
            );
        }
    }
}
