<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\Order;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Location\LocationCaptureService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Generates a secure, tenant- and conversation-bound smart location capture link
 * so customers can confirm their delivery GPS coordinates on an interactive map.
 */
final class RequestLocationCaptureTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly LocationCaptureService $captureService,
    ) {}

    public function name(): string
    {
        return 'request_location_capture';
    }

    public function description(): string
    {
        return 'Create a secure, single-use location capture map link for this conversation so the customer can pinpoint and confirm their delivery location accurately (with GPS + manual pin fallback). Always share the returned capture_url with the customer.';
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
                'description' => 'Optional existing order number to link the captured delivery location to (e.g. ORD-00000001).',
            ],
            'purpose' => [
                'type' => 'string',
                'required' => false,
                'description' => 'One of: delivery (initial capture) or update_delivery (customer wants to change existing delivery location).',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $orderNumber = isset($arguments['order_number']) && is_string($arguments['order_number'])
            ? trim($arguments['order_number'])
            : '';
        $purpose = isset($arguments['purpose']) && is_string($arguments['purpose'])
            ? trim($arguments['purpose'])
            : 'delivery';

        try {
            $order = null;
            if ($orderNumber !== '') {
                $normalized = ltrim($orderNumber, '#');
                $order = Order::withoutGlobalScopes()
                    ->where('workspace_id', $context->workspace->id)
                    ->where(function ($q) use ($context): void {
                        $q->where('conversation_id', $context->conversation->id);
                        if ($context->conversation->customer_id !== null) {
                            $q->orWhere('customer_id', $context->conversation->customer_id);
                        }
                    })
                    ->where(function ($q) use ($orderNumber, $normalized): void {
                        $q->where('order_number', $orderNumber)
                            ->orWhere('order_number', $normalized)
                            ->orWhere('order_number', '#'.$normalized);
                    })
                    ->first();
            }

            $result = $this->captureService->createCaptureSession(
                workspace: $context->workspace,
                conversation: $context->conversation,
                order: $order,
                purpose: $purpose,
                requestedByMessageId: $context->message->id,
            );

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'request_location_capture',
                [
                    'success' => true,
                    'requested' => true,
                    'capture_url' => $result['capture_url'],
                    'expires_at' => $result['expires_at'],
                    'platform' => $result['platform'],
                    'supports_native_location' => $result['supports_native_location'],
                    'suggested_message' => $result['prompt_message'],
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.request_location_capture_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'error' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'request_location_capture',
                'execution_failed',
                'Failed to generate location capture link.',
            );
        }
    }
}
