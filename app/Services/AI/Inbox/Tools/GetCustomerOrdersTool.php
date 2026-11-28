<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Customer\CustomerOrderCaptureService;

/**
 * Retrieve all orders (active, cancellable, and historical) belonging strictly to the current customer or conversation.
 */
final class GetCustomerOrdersTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CustomerOrderCaptureService $captureService,
    ) {}

    public function name(): string
    {
        return 'get_customer_orders';
    }

    public function description(): string
    {
        return 'List orders belonging strictly to the current customer and conversation, including order_number, status, items, shipping_address, totals, and whether each order is cancellable or address-modifiable. Always call this before guessing which order a customer means when they have multiple orders.';
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
            'active_only' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Set true to return only active (non-cancelled, non-completed, non-fulfilled) orders.',
            ],
            'order_number' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional order number filter (e.g. ORD-00000001).',
            ],
            'query_hint' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional product name, address fragment, or date keyword to narrow matching orders.',
            ],
            'limit' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Maximum number of orders to return (default 10).',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $activeOnly = (bool) ($arguments['active_only'] ?? false);
        $orderNumber = isset($arguments['order_number']) && is_string($arguments['order_number'])
            ? trim($arguments['order_number'])
            : null;
        $queryHint = isset($arguments['query_hint']) && is_string($arguments['query_hint'])
            ? trim($arguments['query_hint'])
            : null;
        $limit = max(1, min(20, (int) ($arguments['limit'] ?? 10)));

        $orders = $this->captureService->listCustomerOrders(
            workspace: $context->workspace,
            conversation: $context->conversation,
            activeOnly: $activeOnly,
            orderNumber: $orderNumber !== '' ? $orderNumber : null,
            queryHint: $queryHint !== '' ? $queryHint : null,
            limit: $limit,
        );

        $activeCount = count(array_filter($orders, static fn (array $o): bool => ($o['is_active'] ?? false) === true));
        $cancellableCount = count(array_filter($orders, static fn (array $o): bool => ($o['is_cancellable'] ?? false) === true));

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_customer_orders',
            [
                'found' => $orders !== [],
                'count' => count($orders),
                'active_count' => $activeCount,
                'cancellable_count' => $cancellableCount,
                'orders' => $orders,
                'message' => $orders === []
                    ? 'لا توجد طلبات مسجلة لهذا العميل في هذه المحادثة.'
                    : null,
            ],
        );
    }
}
