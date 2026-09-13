<?php

namespace Tests\Feature\Feature\Finance;

use App\Models\Contract\Contract;
use App\Models\Customer;
use App\Models\Finance\FinanceInvoice;
use App\Models\Projects\FinanceProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\FinanceAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceFrontendExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_decision_dashboard_answers_the_owner_questions_from_workspace_data(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        $other = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Local Giant');
        $this->customer($other[1], 'Foreign Giant');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 15,
                'items_json' => json_encode([[
                    'product_name' => 'Retainer',
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount' => 0,
                    'tax_rate' => 15,
                    'tax_type' => 'standard',
                ]]),
            ])->assertRedirect();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard'))
            ->assertOk()
            ->assertSee('كم بعنا؟')
            ->assertSee('كم ربحنا؟')
            ->assertSee('كم لنا عند العملاء؟')
            ->assertSee('كم علينا؟')
            ->assertSee('مقارنة الفترات')
            ->assertSee('صافي التدفق النقدي')
            ->assertSee('1,150.00')
            ->assertDontSee('Foreign Giant');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.reports.index'))
            ->assertOk()
            ->assertSee('صافي الربح الدفتري')
            ->assertSee('صافي التدفق')
            ->assertSee('مقارنة الفترات');

        $analytics = app(FinanceAnalyticsService::class)->dashboard((int) $workspace->id, [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->endOfMonth()->toDateString(),
        ]);
        $this->assertSame('1000.00', $analytics['hero'][0]['value']);
        $this->assertSame($analytics['ledger_profit']['revenue'], $analytics['hero'][0]['value']);
        $this->assertSame($analytics['ledger_profit']['net_profit'], $analytics['hero'][1]['value']);
    }

    public function test_invoice_inbox_shows_sent_lifecycle_and_hides_other_workspace_invoices(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Inbox Customer');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.invoices.store'), [
                'type' => 'sales',
                'customer_id' => $customer->id,
                'issue_date' => now()->toDateString(),
                'currency' => 'SAR',
                'invoice_status' => 'issued',
                'tax_profile_type' => 'standard',
                'tax_rate' => 0,
                'items_json' => json_encode([[
                    'product_name' => 'Service',
                    'quantity' => 1,
                    'unit_price' => 250,
                    'discount' => 0,
                    'tax_rate' => 0,
                    'tax_type' => 'zero_rated',
                ]]),
            ])->assertRedirect();

        $invoice = FinanceInvoice::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.index', ['lifecycle' => 'sent']))
            ->assertOk()
            ->assertSee('مرسلة')
            ->assertSee($invoice->invoice_number)
            ->assertSee('250.00');

        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('workspace.finance.invoices.index'))
            ->assertOk()
            ->assertDontSee($invoice->invoice_number);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('تقدم التحصيل')
            ->assertSee('الفوترة الإلكترونية')
            ->assertSee('غير مهيأة');
    }

    public function test_contract_index_flags_expiring_contracts_inside_workspace_only(): void
    {
        [$user, $workspace] = $this->createWorkspaceOwner();
        [$otherUser, $otherWorkspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Contract Client');
        $project = FinanceProject::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Portal Revamp',
            'status' => 'active',
        ]);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.store'), [
                'title' => 'Annual Retainer',
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'currency' => 'SAR',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addDays(10)->toDateString(),
                'items' => [['title' => 'Retainer', 'quantity' => 1, 'unit_price' => 5000]],
            ])->assertRedirect();

        $contract = Contract::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame($project->id, (int) $contract->project_id);

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.activate', $contract))
            ->assertRedirect();

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.contracts.index', ['expiring' => 1]))
            ->assertOk()
            ->assertSee('Annual Retainer')
            ->assertSee('Portal Revamp')
            ->assertSee('ينتهي خلال');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.contracts.show', $contract))
            ->assertOk()
            ->assertSee('فاتورة من العقد')
            ->assertSee('Portal Revamp');

        $this->actingAs($user)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.invoices.create', [
                'contract_id' => $contract->id,
                'customer_id' => $customer->id,
                'project_id' => $project->id,
            ]))
            ->assertOk()
            ->assertSee($contract->contract_number);

        $this->actingAs($otherUser)->withSession(['current_workspace_id' => $otherWorkspace->id])
            ->get(route('workspace.finance.contracts.index'))
            ->assertOk()
            ->assertDontSee('Annual Retainer');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        foreach (['finance', 'products', 'orders', 'customers'] as $feature) {
            $this->enableWorkspaceFeature($workspace, $feature);
        }

        return [$user, $workspace];
    }

    private function customer(Workspace $workspace, string $name): Customer
    {
        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '0790000000',
            'email' => strtolower(str_replace(' ', '', $name)).'@example.com',
        ]);
    }
}
