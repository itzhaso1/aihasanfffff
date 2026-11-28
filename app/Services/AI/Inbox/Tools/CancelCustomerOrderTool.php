<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Customer\CustomerOrderCaptureService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbox agent tool to cancel an existing unpaid, unfulfilled order belonging to the current customer or conversation.
 */
final class CancelCustomerOrderTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CustomerOrderCaptureService $captureService,
    ) {}

    public function name(): string
    {
        return 'cancel_customer_order';
    }

    public function description(): string
    {
        return 'Cancel an existing unpaid, unfulfilled order belonging strictly to the current customer or conversation. Rejects cancelling already paid or fulfilled orders.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Low,
            accessMode: InboxAgentToolAccessMode::Write,
            requiresConfirmation: false,
            maxCallsPerRun: 2,
        );
    }

    public function inputSchema(): array
    {
        return [
            'order_number' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Order number to cancel (e.g. ORD-00000001). Defaults to the latest active order if only one active order exists.',
            ],
            'reason' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Customer reason for cancellation.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $orderNumber = isset($arguments['order_number']) && is_string($arguments['order_number'])
            ? trim($arguments['order_number'])
            : null;
        $reason = isset($arguments['reason']) && is_string($arguments['reason'])
            ? trim($arguments['reason'])
            : null;

        try {
            $order = $this->captureService->cancelOrder(
                workspace: $context->workspace,
                conversation: $context->conversation,
                orderNumber: $orderNumber !== '' ? $orderNumber : null,
                reason: $reason !== '' ? $reason : null,
            );

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'cancel_customer_order',
                [
                    'success' => true,
                    'cancelled' => true,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'cancelled_at' => $order->cancelled_at?->toIso8601String(),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.cancel_customer_order_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'error' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'cancel_customer_order',
                'execution_failed',
                $exception->getMessage(),
            );
        }
    }
}
