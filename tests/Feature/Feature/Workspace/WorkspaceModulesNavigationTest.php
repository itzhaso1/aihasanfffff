<?php

namespace Tests\Feature\Feature\Workspace;

use App\Models\Contract\Contract as WorkspaceContract;
use App\Models\Customer;
use App\Models\Finance\FinanceEmployee;
use App\Models\Finance\FinanceEmployeePayrollRecord;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Ui\CommunicationNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WorkspaceModulesNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_dashboard_shows_grouped_modules_with_existing_routes(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $response = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.dashboard'));

        $response->assertOk()
            ->assertSee('لوحة تحكم الأعمال الذكية')
            ->assertSee('POS / الإدارة')
            ->assertSee('التواصل')
            ->assertSee('الفوترة والحسابات')
            ->assertSee('الإعدادات')
            ->assertSee('نظرة عامة على أعمالك')
            ->assertSee(route('workspace.conversations.index'), false)
            ->assertSee(route('workspace.customers.index'), false)
            ->assertSee(route('workspace.chat-catalog.index'), false)
            ->assertSee(route('workspace.channels.index'), false)
            ->assertDontSee(route('workspace.products.index'), false)
            ->assertDontSee(route('workspace.categories.index'), false)
            ->assertDontSee(route('workspace.inventory.index'), false)
            ->assertDontSee('مخزون التواصل')
            ->assertSee(route('workspace.finance.dashboard'), false)
            ->assertSee(route('workspace.finance.contracts.index'), false)
            ->assertSee(route('workspace.ai-settings.edit'), false)
            ->assertSee(route('workspace.payments.index'), false)
            ->assertSee(route('workspace.subscriptions.index'), false)
            ->assertSee(route('workspace.employees.index'), false)
            ->assertDontSee('المواعيد')
            ->assertDontSee('الموقع الإلكتروني')
            ->assertDontSee('Products & Inventory')
            ->assertDontSee('Quick Actions')
            ->assertDontSee('مبيعات خلال آخر 7 أيام');

        $this->assertFalse(Route::has('workspace.website.overview'));
        $this->assertFalse(Route::has('public.website.show'));
    }

    public function test_communication_sidebar_keeps_chat_catalog_and_excludes_finance_catalog(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $response = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.conversations.index'));

        $response->assertOk()
            ->assertSee(route('workspace.conversations.index'), false)
            ->assertSee(route('workspace.customers.index'), false)
            ->assertSee(route('workspace.chat-catalog.index'), false)
            ->assertSee(route('workspace.channels.index'), false)
            ->assertSee(route('workspace.emails.index'), false)
            ->assertSee(route('workspace.whatsapp-accounts.index'), false)
            ->assertSee('كتالوج الشات')
            ->assertDontSee('مخزون التواصل')
            ->assertDontSee('التصنيفات');

        $labels = CommunicationNav::data()['secondaryLinks']->pluck('label')->all();
        $routes = CommunicationNav::data()['secondaryLinks']->pluck('route')->all();

        $this->assertSame([
            'المحادثات',
            'العملاء',
            'كتالوج الشات',
            'Channels',
            'البريد الإلكتروني',
            'واتساب',
        ], $labels);
        $this->assertSame('workspace.chat-catalog.index', $routes[2]);
        $this->assertNotContains('workspace.products.index', $routes);
        $this->assertNotContains('workspace.categories.index', $routes);
        $this->assertNotContains('workspace.inventory.index', $routes);
    }

    public function test_finance_sidebar_still_exposes_products_and_inventory(): void
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('company');

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.finance.dashboard'))
            ->assertOk()
            ->assertSee(route('workspace.finance.products.index'), false)
            ->assertSee(route('workspace.finance.inventory.index'), false)
            ->assertSee('المنتجات')
            ->assertSee('المخزون');

        $this->assertTrue(Route::has('workspace.products.index'));
        $this->assertTrue(Route::has('workspace.categories.index'));
        $this->assertTrue(Route::has('workspace.inventory.index'));
    }

    public function test_contracts_module_respects_workspace_customer_isolation(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('store');

        $customerB = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'Foreign Customer',
            'phone' => '0500001111',
        ]);

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.contracts.store'), [
                'title' => 'Isolation Contract',
                'customer_id' => $customerB->id,
                'currency' => 'SAR',
                'value' => 1000,
            ])
            ->assertSessionHasErrors('customer_id');

        $customerA = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspaceA->id,
            'name' => 'Local Customer',
            'phone' => '0500002222',
        ]);

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.contracts.store'), [
                'title' => 'Local Contract',
                'customer_id' => $customerA->id,
                'currency' => 'SAR',
                'items' => [
                    [
                        'title' => 'Website Design',
                        'quantity' => 1,
                        'unit_price' => 1000,
                    ],
                ],
            ])
            ->assertRedirect();

        $contract = WorkspaceContract::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->where('title', 'Local Contract')
            ->firstOrFail();

        $this->actingAs($ownerB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->get(route('workspace.contracts.show', $contract))
            ->assertNotFound();
    }

    public function test_finance_employees_module_uses_independent_finance_employee_records(): void
    {
        [$ownerA, $workspaceA] = $this->createWorkspaceOwner('company');
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('store');

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.employees.store'), [
                'full_name' => 'Finance Employee A',
                'job_title' => 'Payroll Officer',
                'basic_salary' => 5000,
                'status' => 'active',
            ])
            ->assertRedirect();

        $employee = FinanceEmployee::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->where('full_name', 'Finance Employee A')
            ->firstOrFail();

        $this->assertSame('Payroll Officer', $employee->job_title);
        $this->assertSame('5000.00', (string) $employee->basic_salary);

        $this->actingAs($ownerA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->post(route('workspace.finance.employees.payroll-records.store', $employee), [
                'period_start' => '2026-08-01',
                'period_end' => '2026-08-31',
                'basic_salary' => 5000,
                'allowances_total' => 500,
                'deductions_total' => 250,
                'payment_status' => 'pending',
            ])
            ->assertRedirect();

        $record = FinanceEmployeePayrollRecord::withoutGlobalScopes()
            ->where('workspace_id', $workspaceA->id)
            ->where('finance_employee_id', $employee->id)
            ->firstOrFail();

        $this->assertSame('5250.00', (string) $record->net_amount);

        $this->actingAs($ownerB)
            ->withSession(['current_workspace_id' => $workspaceB->id])
            ->get(route('workspace.finance.employees.show', $employee))
            ->assertNotFound();
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

        $this->enableWorkspaceFeature($workspace, 'finance');

        return [$user, $workspace];
    }
}
