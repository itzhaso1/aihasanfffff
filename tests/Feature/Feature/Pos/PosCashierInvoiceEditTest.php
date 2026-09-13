<?php

namespace Tests\Feature\Feature\Pos;

use App\Models\Order;
use App\Models\PosCashierInvoice;
use App\Models\PosMenuItem;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosCashierInvoiceEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_can_edit_closed_invoice_quantity_price_and_discount(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace, 10);

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 2]],
        ]);

        $invoice = PosCashierInvoice::query()->latest('id')->firstOrFail();
        $line = $invoice->items()->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.invoices.edit', $invoice))
            ->assertOk()
            ->assertSee('تعديل')
            ->assertSee($invoice->invoice_number);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.pos.invoices.update', $invoice), [
                'discount_amount' => 5,
                'notes' => 'تعديل كاشير',
                'items' => [
                    [
                        'id' => $line->id,
                        'quantity' => 3,
                        'unit_price' => 12,
                        'discount_amount' => 0,
                    ],
                ],
            ])
            ->assertRedirect(route('workspace.pos.invoices.show', $invoice));

        $invoice->refresh();
        $order->refresh();
        $updatedLine = $invoice->items()->firstOrFail();

        $this->assertSame(3, (int) $updatedLine->quantity);
        $this->assertEquals(12.0, (float) $updatedLine->unit_price);
        $this->assertEquals(36.0, (float) $updatedLine->total_amount);
        $this->assertEquals(36.0, (float) $invoice->subtotal);
        $this->assertEquals(5.0, (float) $invoice->discount_amount);
        $this->assertEquals(31.0, (float) $invoice->total_amount);
        $this->assertSame('تعديل كاشير', data_get($invoice->metadata, 'notes'));

        $this->assertSame(3, (int) $order->items()->sum('quantity'));
        $this->assertEquals(36.0, (float) $order->subtotal);
        $this->assertEquals(5.0, (float) $order->discount_amount);
        $this->assertEquals(31.0, (float) $order->total_amount);
        $this->assertSame('closed', $invoice->status);
    }

    public function test_paid_invoice_cannot_be_edited(): void
    {
        $this->seed(FoundationSeeder::class);
        [$owner, $workspace] = $this->createWorkspaceOwner('store');
        $item = $this->makeItem($workspace, 8);

        $order = $this->placePosOrder($workspace, $owner, [
            'order_type' => 'takeaway',
            'items' => [['pos_menu_item_id' => $item->id, 'quantity' => 1]],
        ]);

        $order->update(['payment_status' => 'paid']);
        $invoice = PosCashierInvoice::query()->latest('id')->firstOrFail();
        $line = $invoice->items()->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.pos.invoices.edit', $invoice))
            ->assertRedirect(route('workspace.pos.invoices.show', $invoice));

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->from(route('workspace.pos.invoices.edit', $invoice))
            ->put(route('workspace.pos.invoices.update', $invoice), [
                'discount_amount' => 0,
                'items' => [
                    [
                        'id' => $line->id,
                        'quantity' => 5,
                        'unit_price' => 8,
                        'discount_amount' => 0,
                    ],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertEquals(1, (int) $invoice->fresh()->items()->first()->quantity);
    }

    private function makeItem(Workspace $workspace, float $price = 10): PosMenuItem
    {
        return PosMenuItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'منتج',
            'price' => $price,
            'currency' => 'SAR',
            'is_active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $workspaceType): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => $workspaceType,
        ]);

        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['pos', 'qr_menu', 'products', 'orders'] as $feature) {
            \App\Models\WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = \App\Models\Plan::query()
            ->where('workspace_type', $workspaceType)
            ->where('is_active', true)
            ->orderByDesc('price')
            ->first();

        if ($plan) {
            \App\Models\Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        return [$user, $workspace];
    }
}
