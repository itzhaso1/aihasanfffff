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
 * Inbox agent tool to update an existing customer order (add/replace items, quantity, variant, address, phone, or notes).
 */
final class UpdateCustomerOrderTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CustomerOrderCaptureService $captureService,
    ) {}

    public function name(): string
    {
        return 'update_customer_order';
    }

    public function description(): string
    {
        return 'Modify an existing non-paid, non-fulfilled order belonging to the current customer or conversation: add items, replace items, or update delivery address, phone, recipient name, or notes.';
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
                'description' => 'Order number to modify (e.g. ORD-00000001). Defaults to the latest active order in this conversation.',
            ],
            'mode' => [
                'type' => 'string',
                'required' => false,
                'description' => 'One of: add_items (append items to existing order), replace_items (replace order items), update_details (update address/phone/notes only).',
            ],
            'items' => [
                'type' => 'array',
                'required' => false,
                'description' => 'Items to add or set on the order with product_name/chat_catalog_item_id, variant_name/product_type, unit_price, and quantity.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'chat_catalog_item_id' => ['type' => 'integer', 'description' => 'Catalog product ID.'],
                        'product_name' => ['type' => 'string', 'description' => 'Product or service name.'],
                        'variant_id' => ['type' => 'integer', 'description' => 'Selected variant ID.'],
                        'variant_name' => ['type' => 'string', 'description' => 'Selected variant or product type.'],
                        'product_type' => ['type' => 'string', 'description' => 'Product type or classification.'],
                        'unit_price' => ['type' => 'number', 'description' => 'Verified unit price.'],
                        'quantity' => ['type' => 'integer', 'description' => 'Requested quantity.'],
                    ],
                ],
            ],
            'customer_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Updated recipient name for this order.',
            ],
            'phone' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Updated recipient phone number for this order.',
            ],
            'address' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Updated delivery address for this order.',
            ],
            'notes' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Updated or additional delivery notes.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $orderNumber = isset($arguments['order_number']) && is_string($arguments['order_number'])
            ? trim($arguments['order_number'])
            : null;
        $mode = isset($arguments['mode']) && is_string($arguments['mode'])
            ? trim($arguments['mode'])
            : 'add_items';
        $items = is_array($arguments['items'] ?? null) ? $arguments['items'] : [];
        $customerName = isset($arguments['customer_name']) && is_string($arguments['customer_name'])
            ? trim($arguments['customer_name'])
            : null;
        $phone = isset($arguments['phone']) && is_string($arguments['phone'])
            ? trim($arguments['phone'])
            : null;
        $address = isset($arguments['address']) && is_string($arguments['address'])
            ? trim($arguments['address'])
            : null;
        $notes = isset($arguments['notes']) && is_string($arguments['notes'])
            ? trim($arguments['notes'])
            : null;

        try {
            $order = $this->captureService->modifyOrder(
                workspace: $context->workspace,
                conversation: $context->conversation,
                orderNumber: $orderNumber !== '' ? $orderNumber : null,
                mode: $mode,
                items: $items,
                customerName: $customerName !== '' ? $customerName : null,
                phone: $phone !== '' ? $phone : null,
                address: $address !== '' ? $address : null,
                notes: $notes !== '' ? $notes : null,
            );

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'update_customer_order',
                [
                    'success' => true,
                    'updated' => true,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'order_total' => (string) $order->total_amount,
                    'currency' => $order->currency,
                    'shipping_address' => $order->metadata['shipping_address'] ?? null,
                    'recipient_name' => $order->metadata['recipient_name'] ?? null,
                    'recipient_phone' => $order->metadata['recipient_phone'] ?? null,
                    'items' => $order->items->map(static fn ($item): array => [
                        'product_name' => $item->product_name,
                        'variant_name' => $item->variant_name,
                        'item_type' => $item->item_type,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (string) $item->unit_price,
                        'total_amount' => (string) $item->total_amount,
                    ])->values()->all(),
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.update_customer_order_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'error' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'update_customer_order',
                'execution_failed',
                $exception->getMessage(),
            );
        }
    }
}
