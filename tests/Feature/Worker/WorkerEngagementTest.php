<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Enums\WorkerEngagementStatus;
use App\Models\ChatCatalogItem;
use App\Models\User;
use App\Models\Worker\WorkerContractRate;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementGuide;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Worker\WorkerEngagementException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkerEngagementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_worker_engagement_status_is_a_backed_string_enum(): void
    {
        $this->assertInstanceOf(\BackedEnum::class, WorkerEngagementStatus::Draft);
        $this->assertSame('draft', WorkerEngagementStatus::Draft->value);
        $this->assertSame('terminated', WorkerEngagementStatus::Terminated->value);
    }

    public function test_verified_worker_receives_an_offered_engagement_without_workspace_membership(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();

        $response = $this->companyRequest($owner, $workspace)->post(route('workspace.worker-engagements.store'), [
            'worker_profile_id' => $profile->id,
            'job_title' => 'Independent Sales Specialist',
            'starts_at' => '2026-10-01',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('worker_engagements', [
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Offered->value,
            'job_title' => 'Independent Sales Specialist',
        ]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $profile->user_id]);
        $this->assertFalse(Schema::hasColumn('worker_profiles', 'workspace_id'));

        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->firstOrFail();

        $this->assertInstanceOf(WorkerEngagementStatus::class, $engagement->status);
        $this->assertSame(WorkerEngagementStatus::Offered, $engagement->status);
    }

    #[DataProvider('supportedDurationCases')]
    public function test_company_offer_calculates_and_persists_the_server_end_date(
        int $durationValue,
        string $durationUnit,
        string $expectedEndsAt,
    ): void {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        WorkerContractRate::factory()->create([
            'worker_profile_id' => $profile->id,
            'duration_value' => $durationValue,
            'duration_unit' => $durationUnit,
            'amount_minor' => 10000,
            'currency' => 'SAR',
            'effective_from' => now()->subMinute(),
        ]);

        $response = $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => $durationValue,
                'duration_unit' => $durationUnit,
            ]);

        $response->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->firstOrFail();

        $this->assertSame('2026-10-01', $engagement->starts_at->toDateString());
        $this->assertSame($expectedEndsAt, $engagement->ends_at->toDateString());
        $this->assertSame($durationValue, $engagement->duration_value);
        $this->assertSame($durationUnit, $engagement->duration_unit->value);
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function supportedDurationCases(): array
    {
        return [
            'one day' => [1, 'day', '2026-10-02'],
            'one week' => [1, 'week', '2026-10-08'],
            'one month' => [1, 'month', '2026-11-01'],
            'three months' => [3, 'month', '2027-01-01'],
            'forty five days' => [45, 'day', '2026-11-15'],
        ];
    }

    #[DataProvider('invalidDurationCases')]
    public function test_company_offer_rejects_invalid_duration_values(
        mixed $durationValue,
        string $durationUnit,
        string $errorKey,
    ): void {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();

        $response = $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => $durationValue,
                'duration_unit' => $durationUnit,
            ]);

        $response->assertSessionHasErrors($errorKey);
        $this->assertDatabaseCount('worker_engagements', 0);
    }

    /**
     * @return array<string, array{mixed, string, string}>
     */
    public static function invalidDurationCases(): array
    {
        return [
            'zero' => [0, 'day', 'duration_value'],
            'negative' => [-1, 'day', 'duration_value'],
            'fractional' => ['1.5', 'day', 'duration_value'],
            'unsupported unit' => [1, 'year', 'duration_unit'],
        ];
    }

    public function test_company_offer_rejects_a_manual_end_date_when_duration_is_supplied(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();

        $response = $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'duration_value' => 1,
                'duration_unit' => 'day',
                'ends_at' => '2026-12-31',
            ]);

        $response->assertSessionHasErrors('ends_at');
        $this->assertDatabaseCount('worker_engagements', 0);
    }

    public function test_legacy_offer_dates_remain_unchanged_without_a_duration(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'starts_at' => '2026-10-01',
                'ends_at' => '2026-12-31',
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->firstOrFail();

        $this->assertSame('2026-10-01', $engagement->starts_at->toDateString());
        $this->assertSame('2026-12-31', $engagement->ends_at->toDateString());
        $this->assertNull($engagement->duration_value);
        $this->assertNull($engagement->duration_unit);
    }

    public function test_company_offer_persists_and_updates_an_engagement_scoped_work_guide(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'job_title' => 'مندوب مبيعات',
                'job_description' => 'شرح المنتجات ومساعدة العملاء.',
                'guide' => [
                    'company_overview' => 'شركة متخصصة في بيع الإلكترونيات.',
                    'products_context' => 'هواتف وإكسسوارات.',
                    'responsibilities' => "الرد على العملاء\nشرح المنتجات",
                    'workflow_steps' => "استقبال العميل\nترشيح المنتج",
                    'important_rules' => 'لا يتم البيع تحت الحد الأدنى.',
                    'customer_guidance' => 'حافظ على أسلوب مهني.',
                    'escalation_rules' => 'أرسل الشكوى للمسؤول.',
                    'work_schedule_notes' => 'من التاسعة صباحًا حتى الخامسة مساءً.',
                    'important_notes' => 'تحقق من توفر المنتج قبل الوعد.',
                    'faq_items' => [[
                        'question' => 'متى أطلب موافقة؟',
                        'answer' => 'عند طلب خصم استثنائي.',
                    ]],
                ],
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->firstOrFail();

        $this->assertDatabaseHas('worker_engagement_guides', [
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $engagement->id,
            'company_overview' => 'شركة متخصصة في بيع الإلكترونيات.',
        ]);
        $this->assertSame(
            ['الرد على العملاء', 'شرح المنتجات'],
            $engagement->guide()->firstOrFail()->responsibilities,
        );

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.show', $engagement))
            ->assertOk()
            ->assertSee('دليل العمل')
            ->assertSee('شركة متخصصة في بيع الإلكترونيات.');

        $this->companyRequest($owner, $workspace)
            ->put(route('workspace.worker-engagements.guide.update', $engagement), [
                'guide' => [
                    'company_overview' => 'نبذة محدثة.',
                    'responsibilities' => ['متابعة العميل'],
                ],
            ])
            ->assertRedirect();

        $guide = WorkerEngagementGuide::withoutGlobalScopes()
            ->where('worker_engagement_id', $engagement->id)
            ->firstOrFail();
        $this->assertSame('نبذة محدثة.', $guide->company_overview);
        $this->assertSame(['متابعة العميل'], $guide->responsibilities);
        $this->assertSame([], $guide->workflow_steps);
        $this->assertNull($guide->products_context);
    }

    public function test_work_guide_validation_rejects_more_than_twelve_list_items(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'guide' => [
                    'responsibilities' => array_fill(0, 13, 'مهمة'),
                ],
            ])
            ->assertSessionHasErrors('guide.responsibilities');

        $this->assertDatabaseCount('worker_engagements', 0);
        $this->assertDatabaseCount('worker_engagement_guides', 0);
    }

    public function test_worker_cannot_update_a_work_guide_and_another_company_cannot_manage_it(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$otherOwner, $otherWorkspace] = $this->createCompany('Other Workspace');
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->actingAs($worker)
            ->put(route('workspace.worker-engagements.guide.update', $engagement), [
                'guide' => ['company_overview' => 'محاولة غير مصرح بها'],
            ])
            ->assertNotFound();

        $this->companyRequest($otherOwner, $otherWorkspace)
            ->put(route('workspace.worker-engagements.guide.update', $engagement), [
                'guide' => ['company_overview' => 'محاولة شركة أخرى'],
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('worker_engagement_guides', [
            'worker_engagement_id' => $engagement->id,
        ]);
    }

    public function test_company_offer_persists_selected_products_and_engagement_price_overrides(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $first = $this->createProduct($workspace, 'Assigned Product', '25.00');
        $second = $this->createProduct($workspace, 'Second Assigned Product', '40.00');

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'job_title' => 'Product Specialist',
                'products' => [
                    [
                        'id' => $first->id,
                        'minimum_selling_price' => '22.00',
                        'maximum_selling_price' => '30.00',
                    ],
                    [
                        'id' => $second->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $engagement = WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->firstOrFail();

        $this->assertDatabaseHas('worker_engagement_products', [
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $engagement->id,
            'chat_catalog_item_id' => $first->id,
            'minimum_selling_price' => '22.00',
            'maximum_selling_price' => '30.00',
        ]);
        $this->assertDatabaseHas('worker_engagement_products', [
            'workspace_id' => $workspace->id,
            'worker_engagement_id' => $engagement->id,
            'chat_catalog_item_id' => $second->id,
            'minimum_selling_price' => null,
            'maximum_selling_price' => null,
        ]);
        $this->assertDatabaseCount('worker_engagement_products', 2);
    }

    public function test_company_cannot_assign_a_product_from_another_workspace(): void
    {
        [$owner, $workspace] = $this->createCompany('Assignment Workspace');
        [, $foreignWorkspace] = $this->createCompany('Foreign Workspace');
        [, $profile] = $this->createVerifiedWorker();
        $foreignProduct = $this->createProduct($foreignWorkspace, 'Foreign Product', '50.00');

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'products' => [
                    ['id' => $foreignProduct->id],
                ],
            ])
            ->assertSessionHasErrors('products.0.id');

        $this->assertDatabaseCount('worker_engagements', 0);
        $this->assertDatabaseCount('worker_engagement_products', 0);
    }

    public function test_company_offer_rejects_an_invalid_engagement_product_price_range(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $product = $this->createProduct($workspace, 'Invalid Range Product', '25.00');

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'products' => [
                    [
                        'id' => $product->id,
                        'minimum_selling_price' => '31.00',
                        'maximum_selling_price' => '30.00',
                    ],
                ],
            ])
            ->assertSessionHasErrors([
                'products.0.maximum_selling_price' => 'يجب ألا يكون الحد الأدنى أكبر من الحد الأعلى.',
            ]);

        $this->assertDatabaseCount('worker_engagements', 0);
        $this->assertDatabaseCount('worker_engagement_products', 0);
    }

    public function test_unverified_worker_cannot_receive_an_engagement_offer(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createWorker([
            'verification_status' => WorkerProfile::VERIFICATION_PENDING_REVIEW,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
        ]);

        $response = $this->companyRequest($owner, $workspace)->post(route('workspace.worker-engagements.store'), [
            'worker_profile_id' => $profile->id,
        ]);

        $response->assertSessionHasErrors('engagement');
        $this->assertDatabaseCount('worker_engagements', 0);
    }

    public function test_nonexistent_worker_is_rejected_by_offer_validation(): void
    {
        [$owner, $workspace] = $this->createCompany();

        $response = $this->companyRequest($owner, $workspace)->post(route('workspace.worker-engagements.store'), [
            'worker_profile_id' => 999999,
        ]);

        $response->assertSessionHasErrors('worker_profile_id');
        $this->assertDatabaseCount('worker_engagements', 0);
    }

    public function test_only_a_workspace_manager_can_create_and_manage_engagements(): void
    {
        [$owner, $workspace] = $this->createCompany();
        $member = User::factory()->create();
        $workspace->users()->attach($member->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        [, $profile] = $this->createVerifiedWorker();

        $this->companyRequest($member, $workspace)
            ->get(route('workspace.worker-engagements.create'))
            ->assertForbidden();

        $this->assertDatabaseMissing('worker_engagements', [
            'workspace_id' => $workspace->id,
            'worker_profile_id' => $profile->id,
        ]);
    }

    public function test_company_offer_form_lists_workspace_products_for_assignment(): void
    {
        [$owner, $workspace] = $this->createCompany();
        $product = $this->createProduct($workspace, 'Offer Form Product', '35.00');

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.create'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('السعر الأساسي')
            ->assertSee('الحد الأدنى لهذا العمل')
            ->assertSee('الحد الأعلى لهذا العمل')
            ->assertSee('مدة التعاقد')
            ->assertSee('مدة مخصصة')
            ->assertDontSee('تاريخ النهاية الاختياري');
    }

    public function test_worker_can_accept_and_reject_only_their_own_offers(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        [, $otherProfile] = $this->createVerifiedWorker();
        $accepted = $this->createOffer($owner, $workspace, $profile);
        $rejected = $this->createOffer($owner, $workspace, $profile);

        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $accepted->id))
            ->assertRedirect(route('worker.engagements.show', $accepted->id, absolute: false));

        $this->actingAs($worker)
            ->post(route('worker.engagements.reject', $rejected->id))
            ->assertRedirect(route('worker.engagements.show', $rejected->id, absolute: false));

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $accepted->id,
            'status' => WorkerEngagementStatus::Accepted->value,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $rejected->id,
            'status' => WorkerEngagementStatus::Rejected->value,
        ]);
        $this->assertDatabaseHas('worker_engagement_acceptances', [
            'worker_engagement_id' => $accepted->id,
            'party' => 'worker',
            'decision' => WorkerEngagementStatus::Accepted->value,
        ]);
        $this->assertDatabaseHas('worker_engagement_acceptances', [
            'worker_engagement_id' => $rejected->id,
            'party' => 'worker',
            'decision' => WorkerEngagementStatus::Rejected->value,
            'accepted_at' => null,
        ]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $worker->id]);

        $otherWorker = User::query()->findOrFail($otherProfile->user_id);
        $this->actingAs($otherWorker)
            ->post(route('worker.engagements.accept', $accepted->id))
            ->assertNotFound();

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $accepted->id,
            'status' => WorkerEngagementStatus::Accepted->value,
        ]);
    }

    public function test_accepted_engagement_requires_explicit_company_activation(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.activate', $engagement))
            ->assertRedirect();

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Offered->value,
        ]);

        $this->actingAs($worker)
            ->post(route('worker.engagements.accept', $engagement->id))
            ->assertRedirect();

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.activate', $engagement))
            ->assertRedirect();

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Active->value,
        ]);
        $this->assertNotNull(
            WorkerEngagement::withoutGlobalScopes()->findOrFail($engagement->id)->active_at,
        );
        $this->assertDatabaseHas('audit_logs', [
            'workspace_id' => $workspace->id,
            'entity_type' => WorkerEngagement::class,
            'entity_id' => $engagement->id,
            'action' => 'worker.engagement.transitioned',
        ]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $worker->id]);
    }

    public function test_valid_active_lifecycle_transitions_are_persisted(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->actingAs($worker)->post(route('worker.engagements.accept', $engagement->id));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.activate', $engagement));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.pause', $engagement));
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Paused->value,
        ]);

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.resume', $engagement));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.complete', $engagement));

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Completed->value,
        ]);
    }

    public function test_paused_engagement_can_be_terminated_with_an_audited_reason(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->actingAs($worker)->post(route('worker.engagements.accept', $engagement->id));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.activate', $engagement));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.pause', $engagement));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.terminate', $engagement), [
                'reason' => 'The engagement ended by company decision.',
            ]);

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagement->id,
            'status' => WorkerEngagementStatus::Terminated->value,
            'termination_reason' => 'The engagement ended by company decision.',
        ]);
    }

    public function test_invalid_status_transition_and_arbitrary_status_are_rejected(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->expectException(WorkerEngagementException::class);
        $engagement->transitionTo(WorkerEngagementStatus::Terminated);
    }

    public function test_invalid_backed_enum_value_is_rejected_by_the_model_cast(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();

        $this->expectException(\ValueError::class);
        WorkerEngagement::withoutGlobalScopes()->create([
            'worker_profile_id' => $profile->id,
            'workspace_id' => $workspace->id,
            'status' => 'not-a-real-engagement-status',
        ]);
    }

    public function test_workspace_isolation_returns_not_found_for_another_company(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [, $profile] = $this->createVerifiedWorker();
        $engagement = $this->createOffer($ownerA, $workspaceA, $profile);

        $this->companyRequest($ownerB, $workspaceB)
            ->get(route('workspace.worker-engagements.show', $engagement->id))
            ->assertNotFound();

        $this->companyRequest($ownerB, $workspaceB)
            ->post(route('workspace.worker-engagements.activate', $engagement->id))
            ->assertNotFound();
    }

    public function test_worker_engagement_pages_do_not_expose_private_identity_data(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [, $profile] = $this->createVerifiedWorker();
        $profile->privateIdentity()->create([
            'legal_name' => 'Private Legal Name',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'PRIVATE-IDENTITY-123',
        ]);
        $engagement = $this->createOffer($owner, $workspace, $profile);

        $this->companyRequest($owner, $workspace)
            ->get(route('workspace.worker-engagements.show', $engagement))
            ->assertOk()
            ->assertDontSee('Private Legal Name')
            ->assertDontSee('PRIVATE-IDENTITY-123');
    }

    public function test_one_worker_can_have_independent_engagements_with_multiple_workspaces(): void
    {
        [$ownerA, $workspaceA] = $this->createCompany('Workspace A');
        [$ownerB, $workspaceB] = $this->createCompany('Workspace B');
        [$worker, $profile] = $this->createVerifiedWorker();
        $engagementA = $this->createOffer($ownerA, $workspaceA, $profile);
        $engagementB = $this->createOffer($ownerB, $workspaceB, $profile);

        $this->actingAs($worker)->post(route('worker.engagements.accept', $engagementA->id));
        $this->actingAs($worker)->post(route('worker.engagements.accept', $engagementB->id));
        $this->companyRequest($ownerA, $workspaceA)
            ->post(route('workspace.worker-engagements.activate', $engagementA));
        $this->companyRequest($ownerB, $workspaceB)
            ->post(route('workspace.worker-engagements.activate', $engagementB));

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagementA->id,
            'workspace_id' => $workspaceA->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active->value,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $engagementB->id,
            'workspace_id' => $workspaceB->id,
            'worker_profile_id' => $profile->id,
            'status' => WorkerEngagementStatus::Active->value,
        ]);

        $this->actingAs($worker)
            ->get(route('worker.engagements.index'))
            ->assertOk()
            ->assertSee('Workspace A')
            ->assertSee('Workspace B')
            ->assertDontSee(route('worker.engagements.sessions.show', $engagementA->id), false)
            ->assertDontSee(route('worker.engagements.earnings.show', $engagementA->id), false)
            ->assertDontSee(route('worker.engagements.sessions.show', $engagementB->id), false)
            ->assertDontSee(route('worker.engagements.earnings.show', $engagementB->id), false)
            ->assertSee(route('logout'), false);
    }

    public function test_a_workspace_cannot_have_two_active_engagements_for_the_same_worker(): void
    {
        [$owner, $workspace] = $this->createCompany();
        [$worker, $profile] = $this->createVerifiedWorker();
        $first = $this->createOffer($owner, $workspace, $profile);
        $second = $this->createOffer($owner, $workspace, $profile);

        $this->actingAs($worker)->post(route('worker.engagements.accept', $first->id));
        $this->actingAs($worker)->post(route('worker.engagements.accept', $second->id));
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.activate', $first));

        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.activate', $second))
            ->assertSessionHasErrors('engagement');

        $this->assertDatabaseHas('worker_engagements', [
            'id' => $first->id,
            'status' => WorkerEngagementStatus::Active->value,
        ]);
        $this->assertDatabaseHas('worker_engagements', [
            'id' => $second->id,
            'status' => WorkerEngagementStatus::Accepted->value,
        ]);
    }

    public function test_engagement_validation_is_applied_through_a_form_request(): void
    {
        [$owner, $workspace] = $this->createCompany();

        $response = $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), []);

        $response->assertSessionHasErrors([
            'worker_profile_id',
        ]);
        $this->assertDatabaseCount('worker_engagements', 0);
    }

    private function createOffer(User $owner, Workspace $workspace, WorkerProfile $profile): WorkerEngagement
    {
        $this->companyRequest($owner, $workspace)
            ->post(route('workspace.worker-engagements.store'), [
                'worker_profile_id' => $profile->id,
                'job_title' => 'Independent Worker',
            ])
            ->assertRedirect();

        return WorkerEngagement::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('worker_profile_id', $profile->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createCompany(string $name = 'Test Workspace'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return [$owner, $workspace];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(array $overrides = []): array
    {
        $user = User::factory()->create();
        $profile = WorkerProfile::query()->create(array_merge([
            'user_id' => $user->id,
            'display_name' => $user->name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Independent sales specialist.',
            'sales_experience_level' => 'senior',
        ], $overrides));

        return [$user, $profile];
    }

    private function createProduct(Workspace $workspace, string $name, string $price): ChatCatalogItem
    {
        return ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => str($name)->slug().'-'.$workspace->id.'-'.uniqid(),
            'description' => 'Product description.',
            'price' => $price,
            'currency' => 'SAR',
            'is_active' => true,
            'is_visible' => true,
            'publication_status' => 'published',
        ]);
    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createVerifiedWorker(): array
    {
        return $this->createWorker();
    }

    private function companyRequest(User $user, Workspace $workspace): static
    {
        return $this->actingAs($user)
            ->withSession(['current_workspace_id' => $workspace->id]);
    }
}
