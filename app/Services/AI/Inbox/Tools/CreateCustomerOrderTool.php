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
 * Inbox agent tool to capture or update customer details and record orders automatically.
 */
final class CreateCustomerOrderTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CustomerOrderCaptureService $captureService,
    ) {}

    public function name(): string
    {
        return 'create_customer_order';
    }

    public function description(): string
    {
        return 'Record customer details and create or update customer order when customer decides to purchase or confirms order. Handles customer de-duplication by phone and flags orders needing human review if data is incomplete.';
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
            'customer_name' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Customer full name or display name.',
            ],
            'phone' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Customer phone or WhatsApp number.',
            ],
            'items' => [
                'type' => 'array',
                'required' => true,
                'description' => 'List of products/items being ordered. Each item should include item_id or product_name, and quantity.',
            ],
            'notes' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Additional customer requests, notes or specifications.',
            ],
            'address' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Customer delivery address or city if mentioned.',
            ],
            'is_complete' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'True if all required customer details (name, phone, products, quantity) are clear and ready. False if ambiguous or missing details.',
            ],
            'review_reason' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Reason why this order requires human review (e.g. ناقص العنوان، غير متأكد من المقاس، كمية خاصة).',
            ],
            'required_action' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Action recommended for the human agent.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $customerName = trim((string) ($arguments['customer_name'] ?? ''));
        $phone = trim((string) ($arguments['phone'] ?? ''));
        $items = is_array($arguments['items'] ?? null) ? $arguments['items'] : [];
        $notes = isset($arguments['notes']) ? (string) $arguments['notes'] : null;
        $address = isset($arguments['address']) ? (string) $arguments['address'] : null;
        $isComplete = (bool) ($arguments['is_complete'] ?? true);
        $reviewReason = isset($arguments['review_reason']) ? (string) $arguments['review_reason'] : null;
        $requiredAction = isset($arguments['required_action']) ? (string) $arguments['required_action'] : null;

        if ($phone === '') {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'create_customer_order',
                'invalid_arguments',
                'Phone number is required.',
            );
        }

        try {
            $result = $this->captureService->capture(
                workspace: $context->workspace,
                conversation: $context->conversation,
                customerName: $customerName,
                phone: $phone,
                items: $items,
                notes: $notes,
                address: $address,
                isComplete: $isComplete,
                reviewReason: $reviewReason,
                requiredAction: $requiredAction,
            );

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'create_customer_order',
                [
                    'success' => true,
                    'customer_id' => $result['customer']->id,
                    'customer_name' => $result['customer']->name,
                    'customer_status' => $result['customer']->status->value,
                    'order_number' => $result['order']->order_number,
                    'order_total' => (string) $result['order']->total_amount,
                    'is_review_required' => $result['is_review_required'],
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.create_customer_order_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'error' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'create_customer_order',
                'execution_failed',
                'Failed to record customer order: '.$exception->getMessage(),
            );
        }
    }
}
