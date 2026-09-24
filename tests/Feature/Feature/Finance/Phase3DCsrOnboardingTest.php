<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Security\Csr\CsrGenerator;
use App\EInvoicing\Security\Exceptions\EgsOnboardingException;
use App\EInvoicing\Security\Exceptions\PrivateKeyStoreException;
use App\Enums\EInvoicing\EgsOnboardingStatus;
use App\Enums\EInvoicing\EInvoicingEnvironment;
use App\Models\EInvoicing\EgsOnboarding;
use App\Models\EInvoicing\EgsUnit;
use App\Models\EInvoicing\PrivateKeyReference;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\EInvoicing\Security\DevMemoryPrivateKeyStore;
use App\Services\EInvoicing\Security\EgsCsrGenerationService;
use App\Services\EInvoicing\Security\EgsOnboardingService;
use App\Services\EInvoicing\Security\PrivateKeyReferenceResolver;
use App\Services\EInvoicing\Security\PrivateKeyStoreEnvironmentGuard;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Phase3DCsrOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\FoundationSeeder::class);
        app(DevMemoryPrivateKeyStore::class)->flushMemory();
    }

    #[Test]
    public function migration_has_no_forbidden_private_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('egs_onboarding'));
        foreach (['private_key_pem', 'private_key', 'secret', 'passphrase'] as $column) {
            $this->assertFalse(Schema::hasColumn('egs_onboarding', $column));
        }
    }

    #[Test]
    public function one_onboarding_per_egs_and_environment(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $service = app(EgsOnboardingService::class);
        $a = $service->ensure((int) $workspace->id, (int) $egs->id, EInvoicingEnvironment::Sandbox);
        $b = $service->ensure((int) $workspace->id, (int) $egs->id, EInvoicingEnvironment::Sandbox);
        $this->assertSame($a->id, $b->id);

        $prod = $service->ensure((int) $workspace->id, (int) $egs->id, EInvoicingEnvironment::Production);
        $this->assertNotSame($a->id, $prod->id);
    }

    #[Test]
    public function csr_generation_reaches_csr_generated_with_public_artifact_only_in_db(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        app(WorkspaceContext::class)->set($workspace);

        $onboarding = app(EgsCsrGenerationService::class)->generateForEgs(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
        );

        $this->assertSame(EgsOnboardingStatus::CsrGenerated, $onboarding->status);
        $this->assertNotNull($onboarding->csr_pem);
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', (string) $onboarding->csr_pem);
        $this->assertNotNull($onboarding->private_key_reference_id);

        $row = (array) \Illuminate\Support\Facades\DB::table('egs_onboarding')->where('id', $onboarding->id)->first();
        $encoded = json_encode($row);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $encoded);
        $this->assertStringNotContainsString('BEGIN EC PRIVATE KEY', $encoded);

        $refRow = (array) \Illuminate\Support\Facades\DB::table('egs_private_key_references')
            ->where('id', $onboarding->private_key_reference_id)
            ->first();
        $refEncoded = json_encode($refRow);
        $this->assertIsString($refEncoded);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $refEncoded);
    }

    #[Test]
    public function model_serialization_does_not_include_private_key(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        app(WorkspaceContext::class)->set($workspace);
        $onboarding = app(EgsCsrGenerationService::class)->generateForEgs(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
        );

        $json = json_encode($onboarding->toArray());
        $this->assertIsString($json);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $json);

        $ref = PrivateKeyReference::withoutGlobalScopes()->find($onboarding->private_key_reference_id);
        $this->assertNotNull($ref);
        $refJson = json_encode($ref->toArray());
        $this->assertIsString($refJson);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $refJson);
        $this->assertStringNotContainsString((string) $ref->store_key, $refJson);
    }

    #[Test]
    public function cross_workspace_onboarding_resolution_fails(): void
    {
        [$workspaceA, $egsA] = $this->workspaceWithEgs('A');
        [$workspaceB] = $this->workspaceWithEgs('B');

        $this->expectException(\App\EInvoicing\Security\Exceptions\EgsOnboardingException::class);
        app(EgsOnboardingService::class)->requireForWorkspace(
            (int) $workspaceB->id,
            (int) $egsA->id,
            EInvoicingEnvironment::Sandbox,
        );
    }

    #[Test]
    public function cross_environment_key_resolution_fails(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        app(WorkspaceContext::class)->set($workspace);
        $onboarding = app(EgsCsrGenerationService::class)->generateForEgs(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
        );

        $this->expectException(PrivateKeyStoreException::class);
        app(PrivateKeyReferenceResolver::class)->resolve(
            (int) $workspace->id,
            (int) $onboarding->private_key_reference_id,
            EInvoicingEnvironment::Production,
            (int) $egs->id,
        );
    }

    #[Test]
    public function production_e_invoicing_environment_csr_is_blocked_in_phase_3d(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $this->expectException(EgsOnboardingException::class);
        app(EgsCsrGenerationService::class)->generateForEgs(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Production,
        );
    }

    #[Test]
    public function dev_store_blocked_when_app_is_production(): void
    {
        $guard = new PrivateKeyStoreEnvironmentGuard(treatAsProduction: true);
        $this->expectException(PrivateKeyStoreException::class);
        $guard->assertDevDriverAllowed('dev_memory');
    }

    #[Test]
    public function existing_csr_is_not_silently_overwritten(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        app(WorkspaceContext::class)->set($workspace);
        $service = app(EgsCsrGenerationService::class);
        $service->generateForEgs((int) $workspace->id, (int) $egs->id, EInvoicingEnvironment::Sandbox);

        $this->expectException(EgsOnboardingException::class);
        $service->generateForEgs((int) $workspace->id, (int) $egs->id, EInvoicingEnvironment::Sandbox);
    }

    #[Test]
    public function csr_generation_failure_marks_onboarding_failed(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        app(WorkspaceContext::class)->set($workspace);
        $onboarding = app(EgsOnboardingService::class)->ensure(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
        );

        $this->mock(CsrGenerator::class, function ($mock): void {
            $mock->shouldReceive('generate')->andThrow(new PrivateKeyStoreException(
                'Simulated CSR failure.',
                operation: 'csr_generate',
                reason: 'test_failure',
            ));
        });

        try {
            app(EgsCsrGenerationService::class)->generateForEgs(
                (int) $workspace->id,
                (int) $egs->id,
                EInvoicingEnvironment::Sandbox,
            );
            $this->fail('Expected exception.');
        } catch (PrivateKeyStoreException) {
            // expected
        }

        $fresh = EgsOnboarding::withoutGlobalScopes()->find($onboarding->id);
        $this->assertSame(EgsOnboardingStatus::Failed, $fresh?->status);
        $this->assertSame('test_failure', $fresh?->failure_code);
    }

    /**
     * @return array{0: Workspace, 1: EgsUnit}
     */
    private function workspaceWithEgs(string $suffix = ''): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $owner->id,
            'type' => 'company',
            'name' => 'Phase3D Workspace '.$suffix,
        ]);
        $workspace->users()->attach($owner->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);
        WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'feature_key' => 'finance'],
            ['workspace_id' => $workspace->id, 'feature_key' => 'finance', 'enabled' => true, 'source' => 'manual'],
        );
        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->orderByDesc('price')->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        $egs = EgsUnit::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'uuid' => $suffix === ''
                ? EgsUnit::defaultUuidForWorkspace((int) $workspace->id)
                : (string) \Illuminate\Support\Str::uuid(),
            'name' => 'EGS '.$suffix,
            'next_icv' => 1,
            'status' => EgsUnit::STATUS_ACTIVE,
        ]);

        return [$workspace->fresh(), $egs];
    }
}
