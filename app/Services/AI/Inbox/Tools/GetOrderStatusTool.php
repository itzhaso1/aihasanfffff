<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\Order;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Support\Tenancy\WorkspaceContext;

/**
 * Retrieve order status strictly scoped to the current workspace AND authenticated conversation customer.
 */
final class GetOrderStatusTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function name(): string
    {
        return 'get_order_status';
    }

    public function description(): string
    {
        return 'Look up the status of an order belonging ONLY to the current customer or active conversation. Never guesses or reveals other customers\' orders.';
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
                'description' => 'Optional order number to filter this customer\'s orders.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $customerId = $context->conversation->customer_id !== null ? (int) $context->conversation->customer_id : null;
        $conversationId = $context->conversation->id > 0 ? (int) $context->conversation->id : null;
        $orderNumber = isset($arguments['order_number']) && is_string($arguments['order_number'])
            ? trim($arguments['order_number'])
            : null;

        if (($customerId === null || $customerId <= 0) && ($conversationId === null || $conversationId <= 0)) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'get_order_status',
                [
                    'found' => false,
                    'status' => 'customer_unverified',
                    'orders' => [],
                    'message' => 'لا يمكن التحقق من طلبات بدون ربط المحادثة بملف عميل أو طلب.',
                ],
            );
        }

        return $this->workspaceContext->runFor($context->workspace, function () use ($context, $customerId, $conversationId, $orderNumber): InboxAgentToolExecutionResult {
            $query = Order::withoutGlobalScopes()
                ->where('workspace_id', $context->workspace->id)
                ->where(function ($ownerQuery) use ($customerId, $conversationId): void {
                    if ($customerId !== null && $customerId > 0) {
                        $ownerQuery->where('customer_id', $customerId);
                    }
                    if ($conversationId !== null && $conversationId > 0) {
                        if ($customerId !== null && $customerId > 0) {
                            $ownerQuery->orWhere('conversation_id', $conversationId);
                        } else {
                            $ownerQuery->where('conversation_id', $conversationId);
                        }
                    }
                })
                ->with(['items:id,order_id,product_name,variant_name,quantity,unit_price,total_amount,currency'])
                ->orderByDesc('id');

            if ($orderNumber !== null && $orderNumber !== '') {
                $normalizedNum = ltrim($orderNumber, '#');
                $query->where(function ($numQ) use ($orderNumber, $normalizedNum): void {
                    $numQ->where('order_number', $orderNumber)
                        ->orWhere('order_number', $normalizedNum)
                        ->orWhere('order_number', '#'.$normalizedNum);
                });
            }

            $orders = $query->limit(5)->get();

            if ($orders->isEmpty()) {
                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'get_order_status',
                    [
                        'found' => false,
                        'status' => 'not_found',
                        'orders' => [],
                        'message' => 'لم يتم العثور على طلب مطابق مرتبط بهذه المحادثة.',
                    ],
                );
            }

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'get_order_status',
                [
                    'found' => true,
                    'status' => 'found',
                    'count' => $orders->count(),
                    'orders' => $orders->map(static fn (Order $order): array => [
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'payment_status' => $order->payment_status,
                        'fulfillment_status' => $order->fulfillment_status,
                        'shipping_status' => $order->shipping_status,
                        'total_amount' => (string) $order->total_amount,
                        'currency' => $order->currency ?: 'SAR',
                        'placed_at' => $order->placed_at?->toIso8601String(),
                        'items' => $order->items->map(static fn ($item): array => [
                            'product_name' => $item->product_name,
                            'variant_name' => $item->variant_name,
                            'quantity' => (int) $item->quantity,
                        ])->values()->all(),
                    ])->values()->all(),
                ],
            );
        });
    }
}
