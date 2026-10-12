<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Enums\WorkforceParticipantType;
use App\Models\ChatCatalogItem;
use App\Models\User;
use App\Models\Worker\WorkerDiscoverySettings;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementGuide;
use App\Models\Worker\WorkerEngagementPermission;
use App\Models\Worker\WorkerEngagementProduct;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\WorkerCapabilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerMobileFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerCapabilitySeeder::class);
    }

    public function test_independent_worker_authenticates_without_a_workspace_membership(): void
    {
        [$worker, $profile] = $this->createWorker();
        $profile->privateIdentity()->create([
            'legal_name' => 'Secret Legal 44110',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'ID-SECRET-44110',
            'address' => 'Hidden Address',
        ]);

        $login = $this->postJson('/api/mobile/v1/auth/login', [
            'email_or_phone' => $worker->email,
            'password' => 'password',
            'device_name' => 'Worker Phone',
        ]);

        $login->assertOk()
            ->assertJsonPath('data.participant.type', WorkforceParticipantType::IndependentWorker->value)
            ->assertJsonPath('data.participant.worker_profile_id', $profile->id)
            ->assertJsonPath('data.user.id', $worker->id)
            ->assertJsonPath('data.workspace', null)
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.identity_number')
            ->assertJsonMissingPath('data.user.identity_number');

        $token = $login->json('data.token');
        $this->assertIsString($token);
        $this->assertNotSame('', $token);
        $this->assertNull($worker->tokens()->first()?->workspace_id);

        $this->withToken($token)
            ->getJson('/api/mobile/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.participant.type', WorkforceParticipantType::IndependentWorker->value)
            ->assertJsonPath('data.participant.worker_profile_id', $profile->id)
            ->assertJsonMissingPath('data.identity_number');
    }

    public function test_company_user_authentication_stays_on_the_workspace_session(): void
    {
        [$owner, $workspace] = $this->createCompany();
        WorkerProfile::query()->create([
            'user_id' => $owner->id,
            'display_name' => 'Owner With Profile',
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_NOT_REQUESTED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_REGISTERED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
        ]);

        $this->postJson('/api/mobile/v1/auth/login', [
            'email_or_phone' => $owner->email,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonPath('data.participant.type', WorkforceParticipantType::CompanyStaff->value)
            ->assertJsonPath('data.participant.worker_profile_id', null)
            ->assertJsonPath('data.workspace.id', $workspace->id);

        $stranger = User::factory()->create();

        $this->postJson('/api/mobile/v1/auth/login', [
            'email_or_phone' => $stranger->email,
            'password' => 'password',
        ])->assertNotFound()
            ->assertJsonPath('message', 'لا توجد مساحة عمل مرتبطة بهذا الحساب.');
    }

    public function test_worker_reads_only_owned_engagements_with_granted_capabilities(): void
    {
        [$worker, $profile] = $this->createWorker('Reader');
        [$other, $otherProfile] = $this->createWorker('Other');
        [$owner, $workspace] = $this->createCompany('Company A');
        [, $otherWorkspace] = $this->createCompany('Company B');
        $own = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Active, [
            'inbox.messages.read' => true,
            'inbox.messages.send' => false,
        ], 'Read the company inbox.');
        $foreign = $this->createEngagement($otherWorkspace, $owner, $otherProfile, WorkerEngagementStatus::Offered, [
            'customers.phone.view' => true,
        ]);

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.index'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $own->id)
            ->assertJsonPath('data.0.workspace.name', 'Company A')
            ->assertJsonPath('data.0.capabilities.0.capability_key', 'inbox.messages.read')
            ->assertJsonPath('data.0.capabilities.0.display_name', 'Read inbox messages')
            ->assertJsonMissingPath('data.0.capabilities.1')
            ->assertJsonMissingPath('data.1');

        $this->getJson(route('mobile.v1.worker.engagements.show', $own))
            ->assertOk()
            ->assertJsonPath('data.capabilities.0.display_name', 'Read inbox messages')
            ->assertJsonMissingPath('data.capabilities.0.description')
            ->assertJsonMissingPath('data.identity_number');

        $this->getJson(route('mobile.v1.worker.engagements.show', $foreign))
            ->assertNotFound()
            ->assertJsonMissingPath('data.workspace.name');

        $this->postJson('/api/mobile/v1/worker/engagements/'.$own->id.'/capabilities', [
            'capability_key' => 'inbox.messages.send',
            'allowed' => true,
        ])->assertNotFound();
    }

    public function test_worker_reads_work_details_and_only_authorized_company_products(): void
    {
        [$worker, $profile] = $this->createWorker('Products Worker');
        [$owner, $workspace] = $this->createCompany('Products Company');
        [, $otherWorkspace] = $this->createCompany('Other Products Company');
        $engagement = $this->createEngagement(
            $workspace,
            $owner,
            $profile,
            WorkerEngagementStatus::Active,
            ['catalog.products.view' => true],
            'Sell and explain the company catalog.',
        );

        $visible = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Worker Product',
            'slug' => 'worker-product-'.$workspace->id,
            'description' => 'A safe product description.',
            'price' => '25.00',
            'worker_min_price' => '22.00',
            'worker_max_price' => '30.00',
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);
        $unassigned = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Unassigned Company Product',
            'slug' => 'unassigned-product-'.$workspace->id,
            'price' => '40.00',
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);
        $otherEngagement = $this->createEngagement(
            $workspace,
            $owner,
            $profile,
            WorkerEngagementStatus::Active,
            ['catalog.products.view' => true],
        );
        $otherEngagementProduct = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Other Engagement Product',
            'slug' => 'other-engagement-product-'.$workspace->id,
            'price' => '50.00',
            'worker_min_price' => '45.00',
            'worker_max_price' => '60.00',
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);
        WorkerEngagementProduct::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $engagement->id,
            'chat_catalog_item_id' => $visible->id,
            'minimum_selling_price' => '20.00',
            'maximum_selling_price' => '27.50',
        ]);
        WorkerEngagementProduct::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $otherEngagement->id,
            'chat_catalog_item_id' => $otherEngagementProduct->id,
        ]);
        ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other Company Product',
            'slug' => 'other-product-'.$otherWorkspace->id,
            'price' => '99.00',
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.show', $engagement))
            ->assertOk()
            ->assertJsonPath('data.job_description', 'Sell and explain the company catalog.');

        $this->getJson(route('mobile.v1.worker.engagements.products', $engagement))
            ->assertOk()
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('data.0.name', 'Worker Product')
            ->assertJsonPath('data.0.minimum_selling_price', '20.00')
            ->assertJsonPath('data.0.maximum_selling_price', '27.50')
            ->assertJsonMissing(['name' => $unassigned->name])
            ->assertJsonMissing(['name' => $otherEngagementProduct->name])
            ->assertJsonMissing(['name' => 'Other Company Product']);

        $this->getJson(route('mobile.v1.worker.engagements.products', $otherEngagement))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Other Engagement Product')
            ->assertJsonPath('data.0.minimum_selling_price', '45.00')
            ->assertJsonPath('data.0.maximum_selling_price', '60.00');

        $this->putJson(route('mobile.v1.worker.engagements.products', $engagement), [
            'minimum_selling_price' => '1.00',
            'maximum_selling_price' => '2.00',
        ])->assertMethodNotAllowed();
    }

    public function test_worker_without_product_capability_cannot_read_products(): void
    {
        [$worker, $profile] = $this->createWorker('No Products Worker');
        [$owner, $workspace] = $this->createCompany('No Products Company');
        $engagement = $this->createEngagement(
            $workspace,
            $owner,
            $profile,
            WorkerEngagementStatus::Active,
            [],
        );

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.products', $engagement))
            ->assertForbidden();
    }

    public function test_worker_can_read_their_private_guide_but_not_another_workers_guide(): void
    {
        [$worker, $profile] = $this->createWorker('Guide Worker');
        [, $otherProfile] = $this->createWorker('Other Guide Worker');
        [$owner, $workspace] = $this->createCompany('Guide Company');
        [$otherOwner, $otherWorkspace] = $this->createCompany('Other Guide Company');
        $own = $this->createEngagement(
            $workspace,
            $owner,
            $profile,
            WorkerEngagementStatus::Offered,
            ['catalog.products.view' => true],
        );
        $otherWorkerEngagement = $this->createEngagement(
            $workspace,
            $owner,
            $otherProfile,
            WorkerEngagementStatus::Offered,
            ['catalog.products.view' => true],
        );
        $foreignCompanyEngagement = $this->createEngagement(
            $otherWorkspace,
            $otherOwner,
            $otherProfile,
            WorkerEngagementStatus::Offered,
            ['catalog.products.view' => true],
        );

        WorkerEngagementGuide::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $own->id,
            'company_overview' => 'دليل الشركة الخاص بالعامل.',
            'responsibilities' => ['شرح المنتجات'],
            'workflow_steps' => ['استقبال العميل'],
        ]);
        WorkerEngagementGuide::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $otherWorkerEngagement->id,
            'company_overview' => 'دليل عامل آخر.',
        ]);
        WorkerEngagementGuide::withoutGlobalScopes()->create([
            'workspace_id' => $otherWorkspace->id,
            'worker_engagement_id' => $foreignCompanyEngagement->id,
            'company_overview' => 'دليل شركة أخرى.',
        ]);

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.show', $own))
            ->assertOk()
            ->assertJsonPath('data.guide.company_overview', 'دليل الشركة الخاص بالعامل.')
            ->assertJsonPath('data.guide.responsibilities.0', 'شرح المنتجات')
            ->assertJsonPath('data.guide.workflow_steps.0', 'استقبال العميل');

        $this->getJson(route('mobile.v1.worker.engagements.show', $otherWorkerEngagement))
            ->assertNotFound()
            ->assertJsonMissingPath('data.guide');

        $this->getJson(route('mobile.v1.worker.engagements.show', $foreignCompanyEngagement))
            ->assertNotFound()
            ->assertJsonMissingPath('data.guide');
    }

    public function test_worker_can_read_assigned_products_before_accepting_when_capability_is_granted(): void
    {
        [$worker, $profile] = $this->createWorker('Pre Acceptance Worker');
        [$owner, $workspace] = $this->createCompany('Pre Acceptance Company');
        $engagement = $this->createEngagement(
            $workspace,
            $owner,
            $profile,
            WorkerEngagementStatus::Offered,
            ['catalog.products.view' => true],
        );
        $product = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Offer Product',
            'slug' => 'offer-product-'.$workspace->id,
            'price' => '80.00',
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);
        WorkerEngagementProduct::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $engagement->id,
            'chat_catalog_item_id' => $product->id,
            'minimum_selling_price' => '75.00',
            'maximum_selling_price' => '90.00',
        ]);

        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.engagements.products', $engagement))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Offer Product')
            ->assertJsonPath('data.0.minimum_selling_price', '75.00')
            ->assertJsonPath('data.0.maximum_selling_price', '90.00');
    }

    public function test_worker_cannot_modify_a_guide_and_unauthenticated_users_cannot_read_it(): void
    {
        [$worker, $profile] = $this->createWorker('Read Only Worker');
        [$owner, $workspace] = $this->createCompany('Read Only Company');
        $engagement = $this->createEngagement(
            $workspace,
            $owner,
            $profile,
            WorkerEngagementStatus::Active,
            [],
        );
        WorkerEngagementGuide::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $engagement->id,
            'company_overview' => 'دليل خاص.',
        ]);

        $this->getJson(route('mobile.v1.worker.engagements.show', $engagement))
            ->assertUnauthorized();

        Sanctum::actingAs($worker);
        $this->putJson(route('mobile.v1.worker.engagements.show', $engagement), [
            'guide' => ['company_overview' => 'تعديل غير مسموح'],
        ])->assertMethodNotAllowed();
    }

    public function test_company_mobile_offer_persists_engagement_product_assignments(): void
    {
        [$owner, $workspace] = $this->createCompany('Mobile Offer Company');
        [, $profile] = $this->createWorker('Mobile Offer Worker');
        WorkerDiscoverySettings::query()->create([
            'worker_profile_id' => $profile->id,
            'is_discoverable' => true,
        ]);
        $product = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Mobile Assigned Product',
            'slug' => 'mobile-assigned-product-'.$workspace->id,
            'price' => '80.00',
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);

        Sanctum::actingAs($owner);

        $this->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/engagements', [
                'job_title' => 'Mobile Offer Role',
                'guide' => [
                    'company_overview' => 'دليل العرض من تطبيق الشركة.',
                    'responsibilities' => ['متابعة العملاء'],
                ],
                'products' => [[
                    'id' => $product->id,
                    'minimum_selling_price' => '75.00',
                    'maximum_selling_price' => '90.00',
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.job_title', 'Mobile Offer Role');

        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->firstOrFail();

        $this->assertDatabaseHas('worker_engagement_products', [
            'worker_engagement_id' => $engagement->id,
            'chat_catalog_item_id' => $product->id,
            'minimum_selling_price' => '75.00',
            'maximum_selling_price' => '90.00',
        ]);
        $this->assertDatabaseHas('worker_engagement_guides', [
            'worker_engagement_id' => $engagement->id,
            'workspace_id' => $workspace->id,
            'company_overview' => 'دليل العرض من تطبيق الشركة.',
        ]);
    }

    public function test_independent_worker_cannot_create_a_company_offer_or_product_assignment(): void
    {
        [, $workspace] = $this->createCompany('Protected Offer Company');
        [$worker, $profile] = $this->createWorker('Protected Offer Worker');
        $product = ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Protected Product',
            'slug' => 'protected-product-'.$workspace->id,
            'price' => '80.00',
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);

        $this->assertFalse($worker->workspaces()->whereKey($workspace->id)->exists());
        Sanctum::actingAs($worker);

        $this->withHeaders(['X-Workspace-Id' => (string) $workspace->id])
            ->postJson('/api/mobile/v1/company/worker-discovery/'.$profile->id.'/engagements', [
                'job_title' => 'Unauthorized Offer',
                'products' => [['id' => $product->id]],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('worker_engagements', 0);
        $this->assertDatabaseCount('worker_engagement_products', 0);
    }

    public function test_worker_can_accept_or_reject_only_an_own_offer(): void
    {
        [$worker, $profile] = $this->createWorker('Responder');
        [$other, $otherProfile] = $this->createWorker('Foreign Responder');
        [$owner, $workspace] = $this->createCompany();
        $offer = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Offered, []);
        $rejection = $this->createEngagement($workspace, $owner, $profile, WorkerEngagementStatus::Offered, []);
        $foreign = $this->createEngagement($workspace, $owner, $otherProfile, WorkerEngagementStatus::Offered, []);

        $workspaceContext = app(WorkspaceContext::class);
        $workspaceContext->disableTestFixtureMutations();
        $workspaceContext->clear();
        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.engagements.accept', $foreign))
            ->assertNotFound();
        $this->postJson(route('mobile.v1.worker.engagements.reject', $foreign))
            ->assertNotFound();
        $this->assertSame(WorkerEngagementStatus::Offered, $foreign->fresh()->status);

        $this->postJson(route('mobile.v1.worker.engagements.accept', $offer))
            ->assertOk()
            ->assertJsonPath('data.status', WorkerEngagementStatus::Accepted->value);

        $this->postJson(route('mobile.v1.worker.engagements.accept', $offer))
            ->assertStatus(422)
            ->assertJsonPath('message', 'لا يمكن تنفيذ هذا الإجراء على هذا الارتباط.');
        $this->assertSame(WorkerEngagementStatus::Accepted, $offer->fresh()->status);

        $this->postJson(route('mobile.v1.worker.engagements.reject', $rejection))
            ->assertOk()
            ->assertJsonPath('data.status', WorkerEngagementStatus::Rejected->value);

        $this->assertNull($workspaceContext->workspace());
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $worker->id]);
    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(string $name = 'Foundation Worker'): array
    {
        $worker = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'senior',
        ]);

        return [$worker, $profile];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Foundation Workspace'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'name' => $name,
            'owner_user_id' => $owner->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }

    /**
     * @param  array<string, bool>  $capabilities
     */
    private function createEngagement(
        Workspace $workspace,
        User $owner,
        WorkerProfile $profile,
        WorkerEngagementStatus $status,
        array $capabilities,
        ?string $jobDescription = null,
    ): WorkerEngagement {
        $engagement = WorkerEngagement::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => $status,
            'job_title' => 'Role '.$profile->id,
            'job_description' => $jobDescription,
            'created_by' => $owner->id,
        ]);

        foreach ($capabilities as $capability => $allowed) {
            WorkerEngagementPermission::query()->create([
                'worker_engagement_id' => $engagement->id,
                'capability_key' => $capability,
                'allowed' => $allowed,
            ]);
        }

        return $engagement;
    }
}
