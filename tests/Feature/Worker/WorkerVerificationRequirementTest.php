<?php

declare(strict_types=1);

namespace Tests\Feature\Worker;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerVerificationDocument;
use App\Services\Worker\WorkerVerificationRequirementCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WorkerVerificationRequirementTest extends TestCase
{
    use RefreshDatabase;

    protected bool $bypassIndependentWorkerWebBoundary = true;

    public function test_supported_countries_resolve_without_invented_requirements(): void
    {
        $catalog = app(WorkerVerificationRequirementCatalog::class);

        $this->assertSame([], $catalog->requiredDocumentTypes('JO'));
        $this->assertSame([], $catalog->requiredDocumentTypes('SA'));

        [$jordan] = $this->worker('JO');
        [$saudi] = $this->worker('SA');

        $this->actingAs($jordan)
            ->get(route('worker.profile.edit'))
            ->assertOk()
            ->assertSee('لا توجد أنواع مستندات إلزامية');
        $this->actingAs($saudi)
            ->get(route('worker.profile.edit'))
            ->assertOk()
            ->assertSee('لا توجد أنواع مستندات إلزامية');
    }

    public function test_unsupported_country_fails_safely(): void
    {
        $this->expectException(RuntimeException::class);

        app(WorkerVerificationRequirementCatalog::class)->requiredDocumentTypes('XX');
    }

    public function test_documents_stay_private_while_platform_admin_can_review(): void
    {
        [$worker, $profile] = $this->worker('JO');
        $document = WorkerVerificationDocument::query()->create([
            'worker_profile_id' => $profile->id,
            'document_type_code' => 'unspecified',
            'disk' => 'local',
            'path' => 'worker-documents/private.pdf',
            'original_name' => 'private.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'status' => 'submitted',
        ]);

        $this->get(route('platform.worker-verifications.documents.download', $document))
            ->assertRedirect(route('platform.login'));

        $admin = PlatformAdmin::factory()->create(['name' => 'Reviewer']);

        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.worker-verifications.show', $profile))
            ->assertOk();

        $this->actingAs($worker)
            ->get(route('worker.profile.edit'))
            ->assertOk()
            ->assertDontSee('worker-documents/private.pdf');
    }

    /**
     * @return array{0: User, 1: WorkerProfile}
     */
    private function worker(string $country): array
    {
        $user = User::factory()->create();
        $profile = WorkerProfile::query()->create([
            'user_id' => $user->id,
            'display_name' => 'Worker '.$country,
            'country_code' => $country,
            'nationality' => 'National',
            'verification_status' => WorkerProfile::VERIFICATION_NOT_REQUESTED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_REGISTERED,
            'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            'professional_summary' => 'Summary',
            'sales_experience_level' => 'junior',
        ]);

        return [$user, $profile];
    }
}
