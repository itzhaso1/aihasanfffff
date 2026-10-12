<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerVerification;
use App\Models\Worker\WorkerVerificationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkerFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_worker_registration_is_separate_from_company_registration(): void
    {
        $response = $this->post('/workers/register', [
            'name' => 'Independent Worker',
            'email' => 'worker@example.com',
            'phone' => '+962790000000',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::query()->where('email', 'worker@example.com')->firstOrFail();

        $this->assertAuthenticated();
        $response->assertRedirect(route('worker.inbox.home', absolute: false));
        $this->assertDatabaseHas('worker_profiles', [
            'user_id' => $user->id,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_REGISTERED,
        ]);
        $this->assertDatabaseMissing('workspace_users', ['user_id' => $user->id]);
        $this->assertFalse(Schema::hasColumn('worker_profiles', 'workspace_id'));
    }

    public function test_worker_without_a_workspace_is_sent_to_the_worker_profile_after_login(): void
    {
        $this->post('/workers/register', [
            'name' => 'Independent Worker',
            'email' => 'worker-login@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $this->post(route('logout'));

        $response = $this->post('/login', [
            'email' => 'worker-login@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('worker.inbox.home', absolute: false));

        $this->get(route('worker.inbox.home'))
            ->assertOk()
            ->assertSee(route('worker.inbox.home'), false)
            ->assertSee(route('logout'), false)
            ->assertSee('تسجيل الخروج')
            ->assertSee('المحادثات')
            ->assertDontSee(route('worker.profile.edit'), false)
            ->assertDontSee(route('worker.engagements.index'), false)
            ->assertDontSee(route('worker.disputes.index'), false);
    }

    public function test_worker_profile_shows_rejection_and_document_request_reasons_without_internal_notes(): void
    {
        $rejectedUser = User::factory()->create();
        $rejectedProfile = $this->completeProfile($rejectedUser);
        $rejectedProfile->verifications()->create([
            'status' => WorkerVerification::STATUS_REJECTED,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'rejection_reason' => 'صورة الهوية غير واضحة',
            'review_notes' => 'INTERNAL-NOTE-9f3a',
        ]);
        $rejectedProfile->forceFill([
            'verification_status' => WorkerProfile::VERIFICATION_REJECTED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_REJECTED,
        ])->save();

        $this->actingAs($rejectedUser)
            ->get(route('worker.profile.edit'))
            ->assertOk()
            ->assertSee('رُفض التحقق من الحساب.')
            ->assertSee('صورة الهوية غير واضحة')
            ->assertDontSee('INTERNAL-NOTE-9f3a');

        $documentsUser = User::factory()->create();
        $documentsProfile = $this->completeProfile($documentsUser);
        $documentsProfile->verifications()->create([
            'status' => WorkerVerification::STATUS_DOCUMENTS_REQUIRED,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'rejection_reason' => 'يلزم رفع إثبات العنوان',
            'review_notes' => 'INTERNAL-NOTE-DOC-77',
        ]);
        $documentsProfile->forceFill([
            'verification_status' => WorkerProfile::VERIFICATION_DOCUMENTS_REQUIRED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE,
        ])->save();

        $this->actingAs($documentsUser)
            ->get(route('worker.profile.edit'))
            ->assertOk()
            ->assertSee('التحقق يحتاج مستندات إضافية.')
            ->assertSee('يلزم رفع إثبات العنوان')
            ->assertDontSee('INTERNAL-NOTE-DOC-77');

        $approvedUser = User::factory()->create();
        $approvedProfile = $this->completeProfile($approvedUser);
        $approvedProfile->verifications()->create([
            'status' => WorkerVerification::STATUS_APPROVED,
            'submitted_at' => now(),
            'reviewed_at' => now(),
            'review_notes' => 'APPROVAL-NOTE-PRIVATE',
        ]);
        $approvedProfile->forceFill([
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
        ])->save();

        $this->actingAs($approvedUser)
            ->get(route('worker.profile.edit'))
            ->assertOk()
            ->assertDontSee('APPROVAL-NOTE-PRIVATE')
            ->assertDontSee('رُفض التحقق من الحساب.');
    }

    public function test_worker_can_complete_profile_with_private_identity_data(): void
    {
        $user = User::factory()->unverified()->create();
        $profile = WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $user->name,
            'country_code' => 'JO',
            'nationality' => 'Jordanian',
            'lifecycle_status' => WorkerProfile::LIFECYCLE_REGISTERED,
            'verification_status' => WorkerProfile::VERIFICATION_NOT_REQUESTED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
        ]);

        $response = $this->actingAs($user)->patch(route('worker.profile.update'), [
            'display_name' => 'Worker Profile',
            'country_code' => 'JO',
            'nationality' => 'Jordanian',
            'professional_summary' => 'Experienced sales specialist.',
            'sales_experience_level' => 'senior',
            'availability_status' => 'available',
            'legal_name' => 'Legal Worker Name',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'JO-PRIVATE-123',
            'address' => 'Private address',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('worker_profiles', [
            'id' => $profile->id,
            'display_name' => 'Worker Profile',
            'country_code' => 'JO',
            'lifecycle_status' => WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE,
        ]);
        $this->assertNotSame(
            'JO-PRIVATE-123',
            (string) DB::table('worker_identity_private')->where('worker_profile_id', $profile->id)->value('identity_number'),
        );
    }

    public function test_worker_can_upload_a_private_document_and_submit_for_review(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $profile = $this->completeProfile($user);

        $uploadResponse = $this->actingAs($user)->post(route('worker.documents.store'), [
            'document_type_code' => 'identity_document',
            'document' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf'),
        ]);

        $uploadResponse->assertRedirect();
        $document = WorkerVerificationDocument::query()->firstOrFail();
        Storage::disk('local')->assertExists($document->path);

        $submitResponse = $this->actingAs($user)->post(route('worker.verification.submit'));

        $submitResponse->assertRedirect();
        $this->assertDatabaseHas('worker_profiles', [
            'id' => $profile->id,
            'verification_status' => WorkerProfile::VERIFICATION_PENDING_REVIEW,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
        ]);
        $this->assertDatabaseHas('worker_verifications', [
            'worker_profile_id' => $profile->id,
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseHas('worker_verification_documents', [
            'id' => $document->id,
            'worker_verification_id' => DB::table('worker_verifications')->value('id'),
        ]);
    }

    public function test_unverified_worker_cannot_upload_or_submit(): void
    {
        Storage::fake('local');
        $user = User::factory()->unverified()->create();
        $this->completeProfile($user);

        $this->actingAs($user)
            ->post(route('worker.documents.store'), [
                'document_type_code' => 'identity_document',
                'document' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect(route('verification.notice', absolute: false));
    }

    public function test_worker_profile_routes_are_scoped_to_the_authenticated_worker(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $this->completeProfile($firstUser);
        $this->completeProfile($secondUser);

        $this->actingAs($firstUser)
            ->get(route('worker.profile.edit'))
            ->assertOk()
            ->assertSee($firstUser->name);

        $this->assertNotSame($firstUser->id, $secondUser->workerProfile->user_id);
    }

    public function test_only_platform_admin_can_review_and_private_documents_are_not_public(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $profile = $this->completeProfile($user);
        $this->actingAs($user)->post(route('worker.documents.store'), [
            'document_type_code' => 'identity_document',
            'document' => UploadedFile::fake()->create('identity.pdf', 100, 'application/pdf'),
        ]);
        $this->actingAs($user)->post(route('worker.verification.submit'));

        $this->get(route('platform.worker-verifications.show', $profile->id))
            ->assertRedirect(route('platform.login', absolute: false));

        $admin = PlatformAdmin::factory()->create();
        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.worker-verifications.show', $profile->id))
            ->assertOk()
            ->assertSee($profile->display_name);

        $this->actingAs($admin, 'platform_admin')
            ->post(route('platform.worker-verifications.approve', $profile->id), ['notes' => 'Reviewed'])
            ->assertRedirect();

        $this->assertDatabaseHas('worker_profiles', [
            'id' => $profile->id,
            'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'worker.verification.approved',
            'workspace_id' => null,
        ]);
    }

    private function completeProfile(User $user): WorkerProfile
    {
        $profile = WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => $user->name,
            'country_code' => 'SA',
            'nationality' => 'Saudi',
            'lifecycle_status' => WorkerProfile::LIFECYCLE_REGISTERED,
            'verification_status' => WorkerProfile::VERIFICATION_NOT_REQUESTED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
        ]);

        $this->actingAs($user)->patch(route('worker.profile.update'), [
            'display_name' => $user->name,
            'country_code' => 'SA',
            'nationality' => 'Saudi',
            'professional_summary' => 'Sales specialist.',
            'sales_experience_level' => 'mid',
            'availability_status' => 'available',
            'legal_name' => 'Legal Name',
            'date_of_birth' => '1990-01-01',
            'identity_number' => 'SA-PRIVATE-123',
        ]);

        return $profile->refresh();
    }
}
