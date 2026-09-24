<?php

namespace Tests\Feature\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCheckoutSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionPlanCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_official_active_plans_appear_and_internal_or_inactive_are_hidden(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();

        $official = $this->makeOfficialPlan('ui-starter', 'Starter Visible', 49);
        $this->makeOfficialPlan('ui-hidden-inactive', 'Hidden Inactive', 10, [
            'is_active' => false,
        ]);
        Plan::query()->create([
            'code' => 'internal-vip-ui',
            'name' => 'Internal Hidden',
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => 1,
            'is_active' => true,
            'is_public' => false,
            'is_official' => false,
            'features' => ['dashboard'],
            'limits' => [],
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.index'))
            ->assertOk()
            ->assertSee('hs-workspace-shell', false)
            ->assertSee('hs-workspace-main', false)
            ->assertSee('hs-plan-grid', false)
            ->assertDontSee('lg:ps-72', false)
            ->assertSee('Starter Visible')
            ->assertSee(number_format((float) $official->price, 2), false)
            ->assertSee('SAR', false)
            ->assertDontSee('Hidden Inactive')
            ->assertDontSee('Internal Hidden')
            ->assertDontSee('name="payment_provider"', false)
            ->assertDontSee('تجريبي محلي');
    }

    public function test_current_plan_and_status_are_displayed_from_subscription(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan('ui-current', 'Current Card Plan', 80);

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_PAST_DUE,
            'starts_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth(),
            'current_period_end' => now()->addDays(2),
            'grace_ends_at' => now()->addDays(2),
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.index'))
            ->assertOk()
            ->assertSee('Current Card Plan')
            ->assertSee(__('متأخر الدفع'))
            ->assertSee(__('باقتك الحالية'));
    }

    public function test_current_plan_cannot_be_purchased_again(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan('ui-same', 'Same Plan', 70);

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), [
                'plan_id' => $plan->id,
                'price' => 1,
                'currency' => 'USD',
                'payment_provider' => 'local',
            ])
            ->assertRedirect(route('workspace.subscriptions.index'));

        $this->assertDatabaseMissing('subscription_checkout_sessions', [
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
        ]);
        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
            'provider' => 'local',
        ]);
    }

    public function test_upgrade_and_downgrade_start_secure_checkout_without_activation(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $low = $this->makeOfficialPlan('ui-low', 'Low Plan', 40);
        $high = $this->makeOfficialPlan('ui-high', 'High Plan', 140);

        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $low->id,
            'status' => Subscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.index'))
            ->assertOk()
            ->assertSee(__('متابعة إلى الدفع'))
            ->assertSee(__('باقتك الحالية'));

        $upgrade = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), [
                'plan_id' => $high->id,
                'price' => 1,
                'currency' => 'USD',
            ]);

        $upgradeSession = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();
        $upgrade->assertRedirect(route('workspace.subscriptions.checkout.show', $upgradeSession));
        $this->assertSame('awaiting_payment', $upgradeSession->checkout_status);
        $this->assertSame((string) $high->price, (string) $upgradeSession->amount);
        $this->assertSame($high->currency, $upgradeSession->currency);

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'plan_id' => $high->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);

        Subscription::withoutGlobalScopes()->where('workspace_id', $workspace->id)->update([
            'plan_id' => $high->id,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.index'))
            ->assertOk()
            ->assertSee(__('متابعة إلى الدفع'));

        $downgrade = $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $low->id]);

        $downSession = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();
        $downgrade->assertRedirect(route('workspace.subscriptions.checkout.show', $downSession));
        $this->assertSame('awaiting_payment', $downSession->checkout_status);
        $this->assertSame($low->id, $downSession->plan_id);
    }

    public function test_unauthorized_member_can_view_plans_but_cannot_start_checkout(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan('ui-member', 'Member Visible Plan', 55);
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.index'))
            ->assertOk()
            ->assertSee('Member Visible Plan')
            ->assertSee(__('لا تملك صلاحية تغيير الباقة'))
            ->assertDontSee('action="'.route('workspace.subscriptions.store').'"', false);

        $this->actingAs($member)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $plan->id])
            ->assertForbidden();
    }

    public function test_checkout_page_stays_awaiting_payment_and_has_no_frontend_confirm(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan('ui-checkout', 'Checkout Plan', 88);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.store'), ['plan_id' => $plan->id]);

        $session = SubscriptionCheckoutSession::withoutGlobalScopes()->latest('id')->firstOrFail();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.checkout.show', $session))
            ->assertOk()
            ->assertSee('Checkout Plan')
            ->assertSee(number_format((float) $plan->price, 2), false)
            ->assertSee('SAR')
            ->assertSee('awaiting_payment')
            ->assertSee(__('العملية بانتظار تحقق الدفع من بوابة الدفع. لا يمكن تفعيل الاشتراك من الواجهة.'))
            ->assertDontSee(__('تم الدفع بالفعل وتفعيل الاشتراك بنجاح.'))
            ->assertDontSee('تأكيد نجاح الدفع')
            ->assertDontSee('name="payment_reference"', false);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.subscriptions.checkout.confirm-payment', $session), [
                'payment_reference' => 'i-paid',
                'payment_status' => 'paid',
            ])
            ->assertRedirect(route('workspace.subscriptions.checkout.show', $session));

        $this->assertDatabaseMissing('subscriptions', [
            'workspace_id' => $workspace->id,
            'status' => Subscription::STATUS_ACTIVE,
        ]);
    }

    public function test_expired_checkout_shows_restart_cta_and_cannot_activate(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $plan = $this->makeOfficialPlan('ui-expired', 'Expired Checkout Plan', 66);
        $session = app(SubscriptionService::class)->createCheckoutSession($workspace, $plan);
        $session->forceFill([
            'expires_at' => now()->subHour(),
            'checkout_status' => 'expired',
        ])->save();

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.checkout.show', $session))
            ->assertOk()
            ->assertSee(__('انتهت صلاحية عملية الدفع. ابدأ عملية جديدة من السيرفر.'))
            ->assertSee(__('بدء عملية دفع جديدة'));

        $this->expectException(\RuntimeException::class);
        app(SubscriptionService::class)->completeCheckoutAndActivate(
            $workspace,
            $session->fresh(),
            'local_billing_provider',
            'pay_expired'
        );
    }

    public function test_comparison_uses_database_features_not_missing_internal_plans(): void
    {
        [$owner, $workspace] = $this->makeWorkspaceOwner();
        $this->makeOfficialPlan('ui-compare', 'Compare Plan', 90, [
            'features' => ['ai', 'finance'],
        ]);

        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.subscriptions.index'))
            ->assertOk()
            ->assertSee('Compare Plan')
            ->assertSee(__('الذكاء الاصطناعي'))
            ->assertDontSee('Internal Hidden');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makeWorkspaceOwner(): array
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

        return [$user, $workspace];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeOfficialPlan(string $code, string $name, float $price, array $overrides = []): Plan
    {
        return Plan::query()->create(array_merge([
            'code' => $code,
            'name' => $name,
            'workspace_type' => 'company',
            'billing_period' => 'monthly',
            'currency' => 'SAR',
            'price' => $price,
            'is_active' => true,
            'is_public' => true,
            'is_official' => true,
            'features' => ['dashboard', 'ai'],
            'permissions' => [],
            'limits' => ['team_members' => 3, 'products' => 50],
        ], $overrides));
    }
}
