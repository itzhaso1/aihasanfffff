<?php

namespace App\Services\Customer;

use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\CustomerNote;
use App\Models\CustomerTag;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Collection;

class CustomerCrmService
{
    public function attachTag(Customer $customer, CustomerTag $tag): void
    {
        $this->assertSameWorkspace($customer->workspace_id, $tag->workspace_id);
        $customer->tags()->syncWithoutDetaching([$tag->id]);
    }

    public function detachTag(Customer $customer, CustomerTag $tag): void
    {
        $this->assertSameWorkspace($customer->workspace_id, $tag->workspace_id);
        $customer->tags()->detach($tag->id);
    }

    public function attachGroup(Customer $customer, CustomerGroup $group): void
    {
        $this->assertSameWorkspace($customer->workspace_id, $group->workspace_id);
        $customer->groups()->syncWithoutDetaching([$group->id]);
    }

    public function detachGroup(Customer $customer, CustomerGroup $group): void
    {
        $this->assertSameWorkspace($customer->workspace_id, $group->workspace_id);
        $customer->groups()->detach($group->id);
    }

    public function addNote(Customer $customer, string $body, ?User $author = null): CustomerNote
    {
        return app(\App\Support\Tenancy\WorkspaceContext::class)->runForId((int) $customer->workspace_id, function () use ($customer, $body, $author): CustomerNote {
        return CustomerNote::withoutGlobalScopes()->create([
            'workspace_id' => $customer->workspace_id,
            'customer_id' => $customer->id,
            'user_id' => $author?->id,
            'body' => $body,
        ]);
        });
    }

    /**
     * Unified CRM timeline from existing relations (orders, payments, conversations, notes).
     *
     * @return Collection<int, array{type:string,id:int|string,at:?string,summary:string,meta:array<string,mixed>}>
     */
    public function timeline(Customer $customer, int $limit = 50): Collection
    {
        $events = collect();

        foreach ($customer->orders()->latest('id')->limit($limit)->get() as $order) {
            $events->push([
                'type' => 'order',
                'id' => $order->id,
                'at' => optional($order->placed_at ?? $order->created_at)?->toIso8601String(),
                'summary' => __('طلب :number — :status', [
                    'number' => $order->order_number ?? '#'.$order->id,
                    'status' => $order->status,
                ]),
                'meta' => [
                    'total_amount' => $order->total_amount,
                    'payment_status' => $order->payment_status,
                ],
            ]);
        }

        $payments = Payment::withoutGlobalScopes()
            ->where('workspace_id', $customer->workspace_id)
            ->whereIn('order_id', $customer->orders()->select('id'))
            ->latest('id')
            ->limit($limit)
            ->get();

        foreach ($payments as $payment) {
            $events->push([
                'type' => 'payment',
                'id' => $payment->id,
                'at' => optional($payment->paid_at ?? $payment->created_at)?->toIso8601String(),
                'summary' => __('دفعة :amount :currency — :status', [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status,
                ]),
                'meta' => [
                    'order_id' => $payment->order_id,
                    'provider' => $payment->provider,
                ],
            ]);
        }

        foreach ($customer->conversations()->latest('id')->limit($limit)->get() as $conversation) {
            $events->push([
                'type' => 'conversation',
                'id' => $conversation->id,
                'at' => optional($conversation->last_message_at ?? $conversation->created_at)?->toIso8601String(),
                'summary' => __('محادثة :channel — :status', [
                    'channel' => $conversation->channel,
                    'status' => $conversation->status,
                ]),
                'meta' => [
                    'channel' => $conversation->channel,
                    'external_id' => $conversation->external_id,
                ],
            ]);
        }

        foreach ($customer->notes()->latest('id')->limit($limit)->get() as $note) {
            $events->push([
                'type' => 'note',
                'id' => $note->id,
                'at' => optional($note->created_at)?->toIso8601String(),
                'summary' => \Illuminate\Support\Str::limit((string) $note->body, 120),
                'meta' => [
                    'user_id' => $note->user_id,
                ],
            ]);
        }

        return $events
            ->sortByDesc(fn (array $event): string => (string) ($event['at'] ?? ''))
            ->values()
            ->take($limit);
    }

    private function assertSameWorkspace(int|string|null $customerWorkspaceId, int|string|null $relatedWorkspaceId): void
    {
        if ((int) $customerWorkspaceId !== (int) $relatedWorkspaceId) {
            throw new \InvalidArgumentException('CRM relation must belong to the same workspace.');
        }
    }
}
