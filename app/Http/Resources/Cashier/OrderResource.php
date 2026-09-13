<?php

namespace App\Http\Resources\Cashier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Order */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'source' => $this->source,
            'order_type' => $this->order_type,
            'pos_status' => $this->pos_status,
            'payment_status' => $this->payment_status,
            'status' => $this->status,
            'currency' => $this->currency,
            'subtotal' => (float) $this->subtotal,
            'discount_amount' => (float) $this->discount_amount,
            'tax_amount' => (float) $this->tax_amount,
            'total_amount' => (float) $this->total_amount,
            'payment_method' => data_get($this->metadata, 'payment_method'),
            'notes' => $this->notes,
            'client_reference' => $this->client_reference,
            'dining_table_id' => $this->dining_table_id,
            'table_session_id' => $this->table_session_id,
            'pos_cashier_invoice_id' => $this->pos_cashier_invoice_id,
            'customer_id' => $this->customer_id,
            'placed_at' => optional($this->placed_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'table' => $this->whenLoaded('table', fn () => $this->table ? [
                'id' => $this->table->id,
                'name' => $this->table->name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'pos_menu_item_id' => $item->pos_menu_item_id,
                'product_name' => $item->product_name,
                'variant_name' => $item->variant_name,
                'item_type' => $item->item_type,
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'discount_amount' => (float) $item->discount_amount,
                'total_amount' => (float) $item->total_amount,
            ])->values()),
        ];
    }
}
