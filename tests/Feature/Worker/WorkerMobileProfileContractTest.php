<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Models\Worker\WorkerVerification;
use App\Models\Worker\WorkerVerificationDocument;
use App\Models\Worker\WorkerVerificationIssue;
use App\Models\Worker\WorkerWorkExperience;
use App\Models\Workspace;
use Database\Seeders\WorkerSkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkerMobileProfileContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WorkerSkillSeeder::class);
        Storage::fake('local');
    }

    public function test_worker_updates_only_the_professional_profile(): void
    {
        [$worker, $profile] = $this->createWorker();
        $profile->privateIdentity()->create([
            'legal_name' => 'Kept Legal',
            'date_of_birth' => '1992-03-04',
            'identity_number' => 'KEEP-1',
            'address' => 'Kept Address',
        ]);

        $this->patchJson(route('mobile.v1.worker.profile.update'), $this->professionalPayload())
            ->assertUnauthorized();

        Sanctum::actingAs($worker);

        $this->patchJson(route('mobile.v1.worker.profile.update'), [
            'country_code' => 'XX',
        ])->assertUnprocessable();

        $this->patchJson(route('mobile.v1.worker.profile.update'), [
            ...$this->professionalPayload(),
            'legal_name' => 'Should Not Apply',
        ])->assertOk()
            ->assertJsonPath('data.display_name', 'Updated Worker')
            ->assertJsonPath('data.verification_status_label', 'معتمد')
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.identity_number');

        $identity = $profile->privateIdentity()->first();
        $this->assertSame('Kept Legal', $identity?->legal_name);
        $this->assertSame('Updated Worker', $profile->fresh()->display_name);
    }

    public function test_worker_validation_keeps_field_paths_and_returns_specific_errors(): void
    {
        [$worker] = $this->createWorker('Validation Worker', WorkerProfile::VERIFICATION_NOT_REQUESTED, WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE);
        Sanctum::actingAs($worker);

        $this->patchJson(route('mobile.v1.worker.profile.update'), [
            'display_name' => '',
            'country_code' => 'XX',
            'nationality' => '',
            'professional_summary' => '',
        ])->assertUnprocessable()
            ->assertJsonStructure([
                'message',
                'errors' => [
                    'display_name',
                    'country_code',
                    'nationality',
                    'professional_summary',
                ],
            ]);
    }

    public function test_worker_profile_photo_uses_post_multipart_and_is_stored(): void
    {
        [$worker, $profile] = $this->createWorker(
            'Photo Worker',
            WorkerProfile::VERIFICATION_NOT_REQUESTED,
            WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE,
        );
        Sanctum::actingAs($worker);

        $this->post(route('mobile.v1.worker.profile.upload'), [
            ...$this->professionalPayload(),
            'profile_photo' => UploadedFile::fake()->image('worker.png'),
        ])->assertOk()
            ->assertJsonPath('data.display_name', 'Updated Worker');

        $path = $profile->fresh()->profile_photo;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_worker_sees_item_review_reasons_and_resolves_only_corrected_items(): void
    {
        [$worker, $profile] = $this->createWorker(
            'Review Worker',
            WorkerProfile::VERIFICATION_PENDING_REVIEW,
            WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
        );
        $verification = $profile->verifications()->create([
            'status' => WorkerVerification::STATUS_PENDING_REVIEW,
            'submitted_at' => now(),
        ]);
        $document = WorkerVerificationDocument::query()->create([
            'worker_profile_id' => $profile->id,
            'worker_verification_id' => $verification->id,
            'document_type_code' => 'identity',
            'disk' => 'local',
            'path' => 'worker-documents/identity.pdf',
            'original_name' => 'identity.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'status' => WorkerVerificationDocument::STATUS_SUBMITTED,
            'uploaded_by' => $worker->id,
        ]);
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-verifications.reject', $profile->id), [
                'reason' => 'يرجى تعديل العناصر المحددة.',
                'issues' => [
                    [
                        'target_type' => WorkerVerificationIssue::TARGET_PROFILE,
                        'target_id' => $profile->id,
                        'field_key' => 'professional_summary',
                        'reason' => 'أضف خبرة أوضح في خدمة العملاء.',
                    ],
                    [
                        'target_type' => WorkerVerificationIssue::TARGET_DOCUMENT,
                        'target_id' => $document->id,
                        'field_key' => 'identity',
                        'reason' => 'الصورة غير واضحة، ارفع صورة كاملة.',
                    ],
                ],
            ])->assertRedirect();

        Sanctum::actingAs($worker);
        $this->getJson(route('mobile.v1.worker.profile'))
            ->assertOk()
            ->assertJsonFragment([
                'target_type' => WorkerVerificationIssue::TARGET_PROFILE,
                'field_key' => 'professional_summary',
                'reason' => 'أضف خبرة أوضح في خدمة العملاء.',
                'status' => WorkerVerificationIssue::STATUS_OPEN,
            ])
            ->assertJsonFragment([
                'target_type' => WorkerVerificationIssue::TARGET_DOCUMENT,
                'field_key' => 'identity',
                'reason' => 'الصورة غير واضحة، ارفع صورة كاملة.',
                'status' => WorkerVerificationIssue::STATUS_OPEN,
            ])
            ->assertJsonMissingPath('data.verification_issues.0.review_notes');

        $this->patchJson(route('mobile.v1.worker.profile.update'), [
            ...$this->professionalPayload(),
            'professional_summary' => 'خبرة واضحة في خدمة العملاء.',
        ])->assertOk();

        $this->getJson(route('mobile.v1.worker.profile'))
            ->assertOk()
            ->assertJsonCount(1, 'data.verification_issues')
            ->assertJsonPath(
                'data.verification_issues.0.target_type',
                WorkerVerificationIssue::TARGET_DOCUMENT,
            )
            ->assertJsonPath('data.verification_issues.0.field_key', 'identity')
            ->assertJsonPath(
                'data.verification_issues.0.status',
                WorkerVerificationIssue::STATUS_OPEN,
            );

        $this->withHeader('Accept', 'application/json')->post(route('mobile.v1.worker.documents.store'), [
            'document_type_code' => 'identity',
            'document' => UploadedFile::fake()->create('identity-replacement.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $this->getJson(route('mobile.v1.worker.profile'))
            ->assertOk()
            ->assertJsonPath('data.verification_issues', []);
        $this->assertDatabaseHas('worker_verification_documents', [
            'id' => $document->id,
            'status' => WorkerVerificationDocument::STATUS_REPLACED,
        ]);
    }

    public function test_company_staff_without_a_worker_profile_cannot_update_a_worker_profile(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($owner);

        $this->patchJson(route('mobile.v1.worker.profile.update'), $this->professionalPayload())
            ->assertForbidden();
    }

    public function test_skill_and_language_catalogs_drive_the_existing_sync_endpoints(): void
    {
        [$worker] = $this->createWorker();
        Sanctum::actingAs($worker);

        $catalog = $this->getJson(route('mobile.v1.worker.skills.catalog'))
            ->assertOk()
            ->assertJsonPath('data.0.skill_key', 'customer_support')
            ->assertJsonPath('data.0.display_name', 'دعم العملاء');

        $skillId = $catalog->json('data.0.id');
        $this->assertNotNull(WorkerSkill::query()->find($skillId));

        $this->putJson(route('mobile.v1.worker.skills.sync'), [
            'skill_ids' => [$skillId],
        ])->assertOk()
            ->assertJsonPath('data.skills.0.id', $skillId);

        $this->putJson(route('mobile.v1.worker.skills.sync'), [
            'skill_ids' => [999999],
        ])->assertUnprocessable();

        $this->getJson(route('mobile.v1.worker.languages.catalog'))
            ->assertOk()
            ->assertJsonFragment(['code' => 'ar', 'display_name' => 'العربية'])
            ->assertJsonFragment(['code' => 'en', 'display_name' => 'English']);

        $this->putJson(route('mobile.v1.worker.languages.sync'), [
            'language_codes' => ['ar'],
        ])->assertOk()
            ->assertJsonPath('data.languages.0.language_code', 'ar');

        $this->putJson(route('mobile.v1.worker.languages.sync'), [
            'language_codes' => ['zz'],
        ])->assertUnprocessable();
    }

    public function test_worker_creates_updates_and_deletes_only_owned_experiences(): void
    {
        [$worker] = $this->createWorker();
        [$other] = $this->createWorker('Other Worker');
        $foreign = WorkerWorkExperience::query()->create([
            'worker_profile_id' => $other->workerProfile->id,
            'title' => 'Foreign Role',
            'organization_name' => 'Other Co',
            'started_on' => '2020-01-01',
            'ended_on' => null,
            'is_current' => true,
            'description' => null,
        ]);

        Sanctum::actingAs($worker);

        $created = $this->postJson(route('mobile.v1.worker.experiences.store'), [
            'title' => 'Support Agent',
            'organization_name' => 'Hasim',
            'started_on' => '2022-01-01',
            'ended_on' => null,
            'is_current' => true,
            'description' => 'Inbox work',
        ])->assertCreated()
            ->assertJsonPath('data.experiences.0.title', 'Support Agent');

        $experienceId = $created->json('data.experiences.0.id');

        $this->patchJson(route('mobile.v1.worker.experiences.update', $experienceId), [
            'title' => 'Senior Support',
            'organization_name' => 'Hasim',
            'started_on' => '2022-01-01',
            'ended_on' => '2023-01-01',
            'is_current' => false,
            'description' => null,
        ])->assertOk()
            ->assertJsonPath('data.experiences.0.title', 'Senior Support');

        $this->deleteJson(route('mobile.v1.worker.experiences.destroy', $foreign))
            ->assertNotFound();
        $this->assertNotNull($foreign->fresh());

        $this->deleteJson(route('mobile.v1.worker.experiences.destroy', $experienceId))
            ->assertOk()
            ->assertJsonMissingPath('data.experiences.0');
        $this->assertNull(WorkerWorkExperience::query()->find($experienceId));
    }

    public function test_private_identity_is_separate_from_the_professional_profile(): void
    {
        [$worker, $profile] = $this->createWorker();
        Sanctum::actingAs($worker);

        $this->getJson(route('mobile.v1.worker.identity.show'))
            ->assertOk()
            ->assertJsonPath('data.legal_name', null);

        $this->putJson(route('mobile.v1.worker.identity.update'), [
            'legal_name' => 'Legal Name',
            'date_of_birth' => '1991-05-06',
            'identity_number' => 'ID-55',
            'address' => 'Private Street',
        ])->assertOk()
            ->assertJsonPath('data.legal_name', 'Legal Name')
            ->assertJsonPath('data.identity_number', 'ID-55')
            ->assertJsonMissingPath('data.review_notes');

        $this->getJson(route('mobile.v1.worker.profile'))
            ->assertOk()
            ->assertJsonMissingPath('data.legal_name')
            ->assertJsonMissingPath('data.identity_number');

        $this->assertSame('Profile Worker', $profile->fresh()->display_name);
    }

    public function test_unverified_worker_is_told_to_verify_email_before_documents_or_submission(): void
    {
        $worker = User::factory()->unverified()->create();
        WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => 'Unverified Worker',
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => WorkerProfile::VERIFICATION_NOT_REQUESTED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'senior',
        ]);
        Sanctum::actingAs($worker);

        $message = 'يجب تأكيد البريد الإلكتروني قبل رفع مستندات التحقق أو إرسال الملف للمراجعة.';

        $this->postJson(route('mobile.v1.worker.documents.store'), [
            'document_type_code' => 'identity',
            'document' => UploadedFile::fake()->create('identity.pdf', 20, 'application/pdf'),
        ])->assertForbidden()
            ->assertJsonPath('message', $message);

        $this->postJson(route('mobile.v1.worker.verification.submit'))
            ->assertForbidden()
            ->assertJsonPath('message', $message);

        $this->assertDatabaseCount('worker_verification_documents', 0);
    }

    public function test_worker_lists_and_uploads_only_owned_verification_documents(): void
    {
        [$worker, $profile] = $this->createWorker(
            'Document Worker',
            WorkerProfile::VERIFICATION_DOCUMENTS_REQUIRED,
            WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE,
        );
        [$other, $otherProfile] = $this->createWorker('Document Other');
        $foreign = WorkerVerificationDocument::query()->create([
            'worker_profile_id' => $otherProfile->id,
            'document_type_code' => 'foreign',
            'disk' => 'local',
            'path' => 'secret/foreign.pdf',
            'original_name' => 'foreign.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'status' => WorkerVerificationDocument::STATUS_SUBMITTED,
            'uploaded_by' => $other->id,
            'rejection_reason' => null,
        ]);

        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.documents.store'), [])
            ->assertUnprocessable();

        $this->withHeader('Accept', 'application/json')->post(route('mobile.v1.worker.documents.store'), [
            'document_type_code' => 'identity',
            'document' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertUnprocessable();

        $this->withHeader('Accept', 'application/json')->post(route('mobile.v1.worker.documents.store'), [
            'document_type_code' => 'identity',
            'document' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('data.document_type_code', 'identity')
            ->assertJsonPath('data.status', WorkerVerificationDocument::STATUS_SUBMITTED)
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.review_notes');

        $this->withHeader('Accept', 'application/json')->post(route('mobile.v1.worker.documents.store'), [
            'document_type_code' => 'identity',
            'document' => UploadedFile::fake()->create('identity-2.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $this->getJson(route('mobile.v1.worker.documents.index'))
            ->assertOk()
            ->assertJsonMissing(['id' => $foreign->id])
            ->assertJsonMissingPath('data.0.path');

        $this->assertSame(
            WorkerVerificationDocument::STATUS_REPLACED,
            WorkerVerificationDocument::query()
                ->where('worker_profile_id', $profile->id)
                ->where('original_name', 'identity.pdf')
                ->value('status'),
        );
    }

    public function test_verification_submission_and_worker_visible_reason_stay_on_the_authenticated_worker(): void
    {
        [$worker, $profile] = $this->createWorker('Submit Worker', WorkerProfile::VERIFICATION_NOT_REQUESTED, WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE);
        [$other, $otherProfile] = $this->createWorker('Other Submit');
        $profile->privateIdentity()->create([
            'legal_name' => 'Submit Legal',
            'date_of_birth' => '1990-01-02',
            'identity_number' => 'ID-SUBMIT',
            'address' => null,
        ]);

        Sanctum::actingAs($worker);

        $this->postJson(route('mobile.v1.worker.verification.submit'), [
            'worker_profile_id' => $otherProfile->id,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'يجب رفع مستند واحد على الأقل قبل الإرسال للمراجعة.');
        $this->assertSame(WorkerProfile::VERIFICATION_APPROVED, $otherProfile->fresh()->verification_status);

        $this->withHeader('Accept', 'application/json')->post(route('mobile.v1.worker.documents.store'), [
            'document_type_code' => 'identity',
            'document' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf'),
        ])->assertCreated();

        $this->postJson(route('mobile.v1.worker.verification.submit'))
            ->assertOk()
            ->assertJsonPath('data.verification_status', WorkerProfile::VERIFICATION_PENDING_REVIEW)
            ->assertJsonPath('data.verification_notice', null)
            ->assertJsonMissingPath('data.review_notes');

        $profile->forceFill([
            'verification_status' => WorkerProfile::VERIFICATION_REJECTED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_REJECTED,
        ])->save();
        WorkerVerification::query()->where('worker_profile_id', $profile->id)->update([
            'status' => WorkerVerification::STATUS_REJECTED,
            'rejection_reason' => 'الصورة غير واضحة',
            'review_notes' => 'Internal reviewer note',
        ]);

        $this->getJson(route('mobile.v1.worker.profile'))
            ->assertOk()
            ->assertJsonPath('data.verification_notice.reason', 'الصورة غير واضحة')
            ->assertJsonPath('data.verification_notice.title', 'رُفض التحقق من الحساب.')
            ->assertJsonMissingPath('data.review_notes')
            ->assertJsonMissingPath('data.verification_notice.review_notes');

        $approved = $this->createWorker('Approved Worker');
        Sanctum::actingAs($approved[0]);
        $approved[1]->privateIdentity()->create([
            'legal_name' => 'Approved Legal',
            'date_of_birth' => '1988-02-02',
            'identity_number' => 'ID-OK',
            'address' => null,
        ]);
        WorkerVerificationDocument::query()->create([
            'worker_profile_id' => $approved[1]->id,
            'document_type_code' => 'identity',
            'disk' => 'local',
            'path' => 'secret/ok.pdf',
            'original_name' => 'ok.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'status' => WorkerVerificationDocument::STATUS_SUBMITTED,
            'uploaded_by' => $approved[0]->id,
        ]);

        $this->postJson(route('mobile.v1.worker.verification.submit'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'الحساب موثّق بالفعل.');
    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function createWorker(
        string $name = 'Profile Worker',
        string $verification = WorkerProfile::VERIFICATION_APPROVED,
        string $lifecycle = WorkerProfile::LIFECYCLE_VERIFIED,
    ): array {
        $worker = User::factory()->create(['name' => $name]);
        $profile = WorkerProfile::query()->create([
            'user_id' => $worker->id,
            'display_name' => $name,
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'verification_status' => $verification,
            'lifecycle_status' => $lifecycle,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'senior',
        ]);
        $worker->setRelation('workerProfile', $profile);

        return [$worker, $profile];
    }

    /**
     * @return array<string, string>
     */
    private function professionalPayload(): array
    {
        return [
            'display_name' => 'Updated Worker',
            'country_code' => WorkerProfile::COUNTRY_JORDAN,
            'nationality' => 'Jordanian',
            'professional_summary' => 'Updated summary',
            'sales_experience_level' => 'senior',
            'availability_status' => WorkerProfile::AVAILABILITY_LIMITED,
        ];
    }
}
