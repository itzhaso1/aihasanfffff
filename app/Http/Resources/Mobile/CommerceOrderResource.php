<?php

namespace App\Http\Resources\Mobile;

use App\Enums\Commerce\CommerceOrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class CommerceOrderResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        $commerceStatus = $this->commerce_status instanceof CommerceOrderStatus
            ? $this->commerce_status->value
            : $this->commerce_status;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'cart_id' => $this->commerce_cart_id,
            'customer_id' => $this->customer_id,
            'status' => $commerceStatus,
            'payment_status' => $this->payment_status,
            'fulfillment_status' => $this->fulfillment_status,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_amount' => $this->discount_amount,
            'tax_amount' => $this->tax_amount,
            'shipping_amount' => $this->shipping_amount,
            'total_amount' => $this->total_amount,
            'channel' => $this->commerce_channel,
            'required_next_step' => $this->nextStep($commerceStatus),
            'placed_at' => $this->placed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'catalog_item_id' => $item->chat_catalog_item_id,
                'catalog_variant_id' => $item->chat_catalog_item_variant_id,
                'seller_id' => $item->commerce_seller_id,
                'seller_display_name' => $item->seller_display_name,
                'name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'classification' => $item->classification,
                'fulfillment_classification' => $item->fulfillment_classification,
                'quantity' => (int) $item->quantity,
                'unit_price' => $item->unit_price,
                'discount_amount' => $item->discount_amount,
                'taxable_amount' => $item->taxable_amount,
                'tax_amount' => $item->tax_amount,
                'total_amount' => $item->total_amount,
                'currency' => $item->currency,
                'tax_presentation' => $item->tax_presentation,
                'description' => data_get($item->catalog_snapshot, 'customer_description'),
                'selected_options' => $item->selected_options ?? [],
            ])->values()->all()),
            'seller_summaries' => $this->whenLoaded('sellerAllocations', fn () => $this->sellerAllocations->map(fn ($allocation) => [
                'seller_id' => $allocation->commerce_seller_id,
                'seller_display_name' => $allocation->seller?->display_name ?: $allocation->seller?->name,
                'order_item_id' => $allocation->order_item_id,
                'gross_amount' => $allocation->gross_amount,
                'platform_fee_amount' => $allocation->platform_fee_amount,
                'seller_net_amount' => $allocation->seller_net_amount,
                'currency' => $allocation->currency,
                'settlement_status' => $allocation->settlement_status?->value ?? $allocation->settlement_status,
            ])->values()->all()),
            'reservations' => $this->whenLoaded('inventoryReservations', fn () => $this->inventoryReservations->map(fn ($reservation) => [
                'order_item_id' => $reservation->order_item_id,
                'kind' => $reservation->reservation_kind,
                'status' => $reservation->status?->value ?? $reservation->status,
                'quantity' => (int) $reservation->quantity,
                'expires_at' => $reservation->expires_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }

    private function nextStep(?string $status): string
    {
        return match ($status) {
            CommerceOrderStatus::PendingPayment->value => 'payment_required',
            CommerceOrderStatus::Cancelled->value => 'none',
            default => 'review_required',
        };
    }
}
