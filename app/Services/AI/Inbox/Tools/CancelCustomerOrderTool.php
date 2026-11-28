<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Customer\CustomerOrderCaptureService;
use App\Services\Customer\Exceptions\AmbiguousOrderSelectionException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
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
                'description' => 'Order number to cancel (e.g. ORD-00000001). Mandatory when customer has more than one active order.',
            ],
            'order_hint' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional clue when order_number is not yet known.',
            ],
            'reason' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Customer reason for cancellation.',
            ],
            'customer_confirmed' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Set false to inspect target order and request confirmation before cancelling.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $orderNumber = isset($arguments['order_number']) && is_string($arguments['order_number'])
            ? trim($arguments['order_number'])
            : null;
        $orderHint = isset($arguments['order_hint']) && is_string($arguments['order_hint'])
            ? trim($arguments['order_hint'])
            : null;
        $reason = isset($arguments['reason']) && is_string($arguments['reason'])
            ? trim($arguments['reason'])
            : null;
        $hasConfirmArg = array_key_exists('customer_confirmed', $arguments);
        $confirmed = $hasConfirmArg ? (bool) $arguments['customer_confirmed'] : true;

        try {
            if (! $confirmed) {
                $target = $this->captureService->getOwnedOrderDetails(
                    workspace: $context->workspace,
                    conversation: $context->conversation,
                    orderNumber: $orderNumber !== '' ? $orderNumber : null,
                    orderHint: $orderHint !== '' ? $orderHint : null,
                );

                if ($target === null) {
                    return InboxAgentToolExecutionResult::failure(
                        $this->name(),
                        'cancel_customer_order',
                        'order_not_found',
                        'لم يتم العثور على طلب قابل للإلغاء ضمن طلباتك في هذه المحادثة.',
                    );
                }

                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'cancel_customer_order',
                    [
                        'success' => false,
                        'cancelled' => false,
                        'confirmation_required' => true,
                        'order_number' => $target['order_number'],
                        'target_order' => $target,
                        'message' => 'يرجى تأكيد العميل على إلغاء الطلب رقم '.$target['order_number'].' أولاً.',
                    ],
                );
            }

            $order = $this->captureService->cancelOrder(
                workspace: $context->workspace,
                conversation: $context->conversation,
                orderNumber: $orderNumber !== '' ? $orderNumber : null,
                reason: $reason !== '' ? $reason : null,
                orderHint: $orderHint !== '' ? $orderHint : null,
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
        } catch (AmbiguousOrderSelectionException $ambiguous) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'cancel_customer_order',
                [
                    'success' => false,
                    'cancelled' => false,
                    'ambiguous_order_selection' => true,
                    'candidate_orders' => $ambiguous->candidates,
                    'message' => $ambiguous->getMessage(),
                ],
            );
        } catch (InvalidArgumentException $validationException) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'cancel_customer_order',
                'validation_failed',
                $validationException->getMessage(),
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
                'تعذر إلغاء الطلب في الوقت الحالي، يرجى التحويل لفريق الدعم.',
            );
        }
    }
}
