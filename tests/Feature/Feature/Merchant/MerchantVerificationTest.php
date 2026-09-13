<?php

namespace Tests\Feature\Feature\Merchant;

use App\Models\MerchantDocument;
use App\Models\MerchantDocumentType;
use App\Models\MerchantProfile;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Merchant\MerchantPaymentEligibilityService;
use App\Services\Merchant\MerchantVerificationService;
use App\Services\Payment\PaymentService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MerchantVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        Storage::fake('local');
        Config::set('services.hyperpay.merchant_sandbox_auto_approve', false);
        Config::set('services.hyperpay.merchant_onboarding_enabled', false);
    }

    public function test_starter_or_pro_user_can_open_merchant_settings(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithPlan('starter');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.payments.merchant.show'))
            ->assertOk()
            ->assertSee('مدفوعات التاجر');
    }

    public function test_upload_and_submit_moves_to_pending_review(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithPlan('pro');
        $this->ensureDocumentTypes();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.payments.merchant.request'))
            ->assertRedirect();

        $file = UploadedFile::fake()->create('cr.pdf', 100, 'application/pdf');

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.payments.merchant.upload'), [
                'document_type_code' => 'commercial_registration',
                'document' => $file,
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.payments.merchant.submit'))
            ->assertRedirect();

        $profile = MerchantProfile::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();

        $this->assertNotNull($profile);
        $this->assertSame(MerchantProfile::VERIFICATION_PENDING_REVIEW, $profile->verification_status);
    }

    public function test_cannot_accept_payments_while_pending_review(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithPlan('pro');
        $profile = app(MerchantVerificationService::class)->profile($workspace);
        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_PENDING_REVIEW,
            'provider_onboarding_status' => MerchantProfile::PROVIDER_NOT_STARTED,
        ])->save();

        $eligibility = app(MerchantPaymentEligibilityService::class);
        $this->assertFalse($eligibility->canAcceptCustomerPayments($workspace));

        $order = Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-TEST-1',
            'status' => 'confirmed',
            'payment_status' => 'pending',
            'currency' => 'SAR',
            'subtotal' => 100,
            'discount_amount' => 0,
            'shipping_amount' => 0,
            'total_amount' => 100,
        ]);

        $this->expectException(\RuntimeException::class);
        app(PaymentService::class)->createPaymentLink($order, null, 'merchant_order');
    }

    public function test_platform_admin_approve_still_blocked_until_provider_active(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithPlan('pro');
        $service = app(MerchantVerificationService::class);
        $profile = $service->profile($workspace);
        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_PENDING_REVIEW,
            'submitted_at' => now(),
        ])->save();

        $admin = PlatformAdmin::query()->firstOrFail();
        $service->approve($profile, $admin, 'ok');

        $profile->refresh();
        $this->assertSame(MerchantProfile::VERIFICATION_APPROVED, $profile->verification_status);
        $this->assertSame(MerchantProfile::PROVIDER_PENDING, $profile->provider_onboarding_status);

        $this->assertFalse(
            app(MerchantPaymentEligibilityService::class)->canAcceptCustomerPayments($workspace)
        );
    }

    public function test_sandbox_auto_approve_makes_eligible_when_plan_has_payments(): void
    {
        Config::set('services.hyperpay.merchant_sandbox_auto_approve', true);

        [$user, $workspace] = $this->createWorkspaceWithPlan('pro');
        $service = app(MerchantVerificationService::class);
        $profile = $service->profile($workspace);
        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_PENDING_REVIEW,
            'submitted_at' => now(),
        ])->save();

        $admin = PlatformAdmin::query()->firstOrFail();
        $service->approve($profile, $admin);

        $profile->refresh();
        $this->assertSame(MerchantProfile::PROVIDER_ACTIVE, $profile->provider_onboarding_status);
        $this->assertTrue(
            app(MerchantPaymentEligibilityService::class)->canAcceptCustomerPayments($workspace)
        );
    }

    public function test_user_cannot_mass_assign_verification_status(): void
    {
        [$user, $workspace] = $this->createWorkspaceWithPlan('starter');
        $profile = app(MerchantVerificationService::class)->profile($workspace);

        $profile->update([
            'verification_status' => MerchantProfile::VERIFICATION_APPROVED,
            'provider_onboarding_status' => MerchantProfile::PROVIDER_ACTIVE,
        ]);

        $profile->refresh();
        $this->assertNotSame(MerchantProfile::VERIFICATION_APPROVED, $profile->verification_status);
        $this->assertNotSame(MerchantProfile::PROVIDER_ACTIVE, $profile->provider_onboarding_status);
    }

    public function test_workspace_a_cannot_download_workspace_b_document(): void
    {
        [$userA, $workspaceA] = $this->createWorkspaceWithPlan('pro');
        [$userB, $workspaceB] = $this->createWorkspaceWithPlan('pro');
        $this->ensureDocumentTypes();

        $service = app(MerchantVerificationService::class);
        $file = UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf');
        $document = $service->uploadDocument(
            $workspaceB,
            $userB,
            $file,
            'commercial_registration'
        );

        $this->actingAs($userA)
            ->withSession(['current_workspace_id' => $workspaceA->id])
            ->get(route('workspace.payments.merchant.documents.download', $document->id))
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceWithPlan(string $planCode): array
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

        $plan = Plan::query()->where('code', $planCode)->firstOrFail();
        Subscription::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        return [$user, $workspace];
    }

    private function ensureDocumentTypes(): void
    {
        $this->assertTrue(
            MerchantDocumentType::query()->where('code', 'commercial_registration')->exists()
        );
    }
}
