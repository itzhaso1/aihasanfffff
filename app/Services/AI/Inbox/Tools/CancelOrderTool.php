<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Customer\CustomerOrderCaptureService;
use App\Services\Customer\Exceptions\AmbiguousOrderSelectionException;
use InvalidArgumentException;
use Throwable;

/**
 * Dedicated multi-order-safe order cancellation tool requiring explicit target resolution and customer confirmation.
 */
final class CancelOrderTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CustomerOrderCaptureService $captureService,
    ) {}

    public function name(): string
    {
        return 'cancel_order';
    }

    public function description(): string
    {
        return 'Cancel a specific eligible order strictly owned by this customer/conversation. Never assumes latest order when multiple active orders exist; requires explicit customer_confirmed=true before cancelling.';
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
                'description' => 'Exact order number to cancel (e.g. ORD-00000001). Mandatory whenever the customer has more than one active order.',
            ],
            'order_hint' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional description from the customer; if ambiguous (e.g. "طلبي الآخر"), backend rejects guessing and lists all candidate orders.',
            ],
            'reason' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Reason for cancellation.',
            ],
            'customer_confirmed' => [
                'type' => 'boolean',
                'required' => true,
                'description' => 'Set false first to verify target order and ask explicit customer confirmation. Set true ONLY after the customer explicitly confirms cancelling that specific order number.',
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
        $confirmed = (bool) ($arguments['customer_confirmed'] ?? false);

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
                        'cancel_order',
                        'order_not_found',
                        'لم يتم العثور على طلب قابل للإلغاء ضمن طلباتك في هذه المحادثة.',
                    );
                }

                if (($target['is_cancellable'] ?? false) !== true) {
                    return InboxAgentToolExecutionResult::failure(
                        $this->name(),
                        'cancel_order',
                        'cancellation_not_allowed',
                        (string) ($target['cannot_cancel_reason'] ?? 'لا يمكن إلغاء هذا الطلب آلياً.'),
                    );
                }

                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'cancel_order',
                    [
                        'success' => false,
                        'cancelled' => false,
                        'confirmation_required' => true,
                        'order_number' => $target['order_number'],
                        'target_order' => $target,
                        'message' => 'يرجى تأكيد رغبة العميل صراحةً في إلغاء الطلب رقم '.$target['order_number'].' قبل تنفيذ الإلغاء.',
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
                'cancel_order',
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
                'cancel_order',
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
                'cancel_order',
                'validation_failed',
                $validationException->getMessage(),
            );
        } catch (Throwable) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'cancel_order',
                'execution_failed',
                'تعذر تنفيذ إلغاء الطلب في الوقت الحالي، يرجى التواصل مع فريق الدعم.',
            );
        }
    }
}
