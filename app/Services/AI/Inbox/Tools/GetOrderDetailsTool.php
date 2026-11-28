<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Customer\CustomerOrderCaptureService;
use App\Services\Customer\Exceptions\AmbiguousOrderSelectionException;
use Throwable;

/**
 * Retrieve complete authoritative details of a single verified order belonging to the current customer.
 */
final class GetOrderDetailsTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CustomerOrderCaptureService $captureService,
    ) {}

    public function name(): string
    {
        return 'get_order_details';
    }

    public function description(): string
    {
        return 'Retrieve complete details for a single verified order (status, items, prices, delivery address, GPS coordinates, shipping fee, cancellability) scoped strictly to the current customer and workspace.';
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
            'order_number' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Order number (e.g. ORD-00000001). Optional only if the customer has a single order or unambiguous hint.',
            ],
            'order_hint' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional distinguishing clue (e.g. product name or date) when order_number is omitted.',
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

        try {
            $details = $this->captureService->getOwnedOrderDetails(
                workspace: $context->workspace,
                conversation: $context->conversation,
                orderNumber: $orderNumber !== '' ? $orderNumber : null,
                orderHint: $orderHint !== '' ? $orderHint : null,
            );

            if ($details === null) {
                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'get_order_details',
                    [
                        'found' => false,
                        'order' => null,
                        'message' => 'لم يتم العثور على هذا الطلب ضمن طلباتك في هذه المحادثة.',
                    ],
                );
            }

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'get_order_details',
                [
                    'found' => true,
                    'order' => $details,
                ],
            );
        } catch (AmbiguousOrderSelectionException $ambiguous) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'get_order_details',
                [
                    'found' => true,
                    'ambiguous_order_selection' => true,
                    'order' => null,
                    'candidate_orders' => $ambiguous->candidates,
                    'message' => $ambiguous->getMessage(),
                ],
            );
        } catch (Throwable $exception) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'get_order_details',
                'order_lookup_failed',
                $exception->getMessage(),
            );
        }
    }
}
