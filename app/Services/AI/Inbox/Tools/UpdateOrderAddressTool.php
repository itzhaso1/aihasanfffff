<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Customer\CustomerOrderCaptureService;
use App\Services\Customer\Exceptions\AmbiguousOrderSelectionException;
use App\Services\Customer\Exceptions\OrderFeeConfirmationRequiredException;
use InvalidArgumentException;
use Throwable;

/**
 * Dedicated tool to update delivery address and/or GPS coordinates of a verified active order.
 */
final class UpdateOrderAddressTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly CustomerOrderCaptureService $captureService,
    ) {}

    public function name(): string
    {
        return 'update_order_address';
    }

    public function description(): string
    {
        return 'Update the delivery address (and optional GPS latitude/longitude or confirmed location ID) on a verified active order belonging to this customer. Re-checks shipping zone and delivery fees before committing.';
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
                'description' => 'Target order number (e.g. ORD-00000001). Required when customer has multiple active orders.',
            ],
            'address' => [
                'type' => 'string',
                'required' => false,
                'description' => 'New full delivery address text (city, district, street/landmark).',
            ],
            'latitude' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Optional GPS latitude when customer shared new coordinates.',
            ],
            'longitude' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Optional GPS longitude when customer shared new coordinates.',
            ],
            'delivery_location_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Optional verified CustomerLocation ID from this conversation.',
            ],
            'recipient_name' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional updated recipient name for this order.',
            ],
            'phone' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional updated recipient phone for this order.',
            ],
            'notes' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional additional delivery instructions.',
            ],
            'order_hint' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional product/date clue if order_number was not provided.',
            ],
            'confirm_fee_change' => [
                'type' => 'boolean',
                'required' => false,
                'description' => 'Set true ONLY after informing the customer and getting agreement when shipping fee changes for the new address.',
            ],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $orderNumber = isset($arguments['order_number']) && is_string($arguments['order_number']) ? trim($arguments['order_number']) : null;
        $address = isset($arguments['address']) && is_string($arguments['address']) ? trim($arguments['address']) : null;
        $latitude = isset($arguments['latitude']) && is_numeric($arguments['latitude']) ? (float) $arguments['latitude'] : null;
        $longitude = isset($arguments['longitude']) && is_numeric($arguments['longitude']) ? (float) $arguments['longitude'] : null;
        $deliveryLocationId = isset($arguments['delivery_location_id']) && is_numeric($arguments['delivery_location_id'])
            ? (int) $arguments['delivery_location_id']
            : null;
        $recipientName = isset($arguments['recipient_name']) && is_string($arguments['recipient_name']) ? trim($arguments['recipient_name']) : null;
        $phone = isset($arguments['phone']) && is_string($arguments['phone']) ? trim($arguments['phone']) : null;
        $notes = isset($arguments['notes']) && is_string($arguments['notes']) ? trim($arguments['notes']) : null;
        $orderHint = isset($arguments['order_hint']) && is_string($arguments['order_hint']) ? trim($arguments['order_hint']) : null;
        $confirmFeeChange = (bool) ($arguments['confirm_fee_change'] ?? false);

        try {
            $result = $this->captureService->updateOrderAddress(
                workspace: $context->workspace,
                conversation: $context->conversation,
                orderNumber: $orderNumber !== '' ? $orderNumber : null,
                address: $address !== '' ? $address : null,
                latitude: $latitude,
                longitude: $longitude,
                deliveryLocationId: $deliveryLocationId,
                recipientName: $recipientName !== '' ? $recipientName : null,
                phone: $phone !== '' ? $phone : null,
                notes: $notes !== '' ? $notes : null,
                orderHint: $orderHint !== '' ? $orderHint : null,
                confirmFeeChange: $confirmFeeChange,
            );

            $order = $result['order'];
            $summary = $this->captureService->serializeOrderSummary($order);

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'update_order_address',
                [
                    'success' => true,
                    'updated' => true,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'old_address' => $result['old_address'],
                    'shipping_address' => $summary['shipping_address'],
                    'coordinates' => $summary['coordinates'],
                    'delivery_location_id' => $order->delivery_location_id ? (int) $order->delivery_location_id : null,
                    'shipping_changed' => $result['shipping_changed'],
                    'shipping_amount' => (string) ($order->shipping_amount ?? '0.00'),
                    'order_total' => (string) $order->total_amount,
                    'currency' => $order->currency ?: 'SAR',
                ],
            );
        } catch (AmbiguousOrderSelectionException $ambiguous) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'update_order_address',
                [
                    'success' => false,
                    'updated' => false,
                    'ambiguous_order_selection' => true,
                    'candidate_orders' => $ambiguous->candidates,
                    'message' => $ambiguous->getMessage(),
                ],
            );
        } catch (OrderFeeConfirmationRequiredException $feeException) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'update_order_address',
                [
                    'success' => false,
                    'updated' => false,
                    'requires_fee_confirmation' => ! $feeException->outOfZone,
                    'out_of_delivery_zone' => $feeException->outOfZone,
                    'order_number' => $feeException->orderNumber,
                    'proposed_address' => $feeException->proposedAddress,
                    'zone_name' => $feeException->zoneName,
                    'old_shipping_amount' => $feeException->oldShippingAmount,
                    'new_shipping_amount' => $feeException->newShippingAmount,
                    'new_total_amount' => $feeException->newTotalAmount,
                    'currency' => $feeException->currency,
                    'message' => $feeException->getMessage(),
                ],
            );
        } catch (InvalidArgumentException $validationException) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'update_order_address',
                'validation_failed',
                $validationException->getMessage(),
            );
        } catch (Throwable) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'update_order_address',
                'execution_failed',
                'تعذر تحديث عنوان الطلب في الوقت الحالي، يرجى المحاولة لاحقاً أو التواصل مع الدعم.',
            );
        }
    }
}
