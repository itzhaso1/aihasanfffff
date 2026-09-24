<?php

namespace Tests\Feature\Feature\Finance;

use App\EInvoicing\Security\Exceptions\PrivateKeyStoreException;
use App\EInvoicing\Security\PrivateKeyStore;
use App\Enums\EInvoicing\EInvoicingEnvironment;
use App\Enums\EInvoicing\PrivateKeyReferenceStatus;
use App\Models\EInvoicing\EgsUnit;
use App\Models\EInvoicing\PrivateKeyReference;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Support\Tenancy\WorkspaceContext;
use App\Services\EInvoicing\Security\DevMemoryPrivateKeyStore;
use App\Services\EInvoicing\Security\PrivateKeyReferenceResolver;
use App\Services\EInvoicing\Security\PrivateKeyReferenceService;
use App\Services\EInvoicing\Security\PrivateKeyStoreEnvironmentGuard;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Phase3CPrivateKeyArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        app(DevMemoryPrivateKeyStore::class)->flushMemory();
    }

    #[Test]
    public function migration_has_no_private_key_columns(): void
    {
        $this->assertTrue(Schema::hasTable('egs_private_key_references'));
        foreach (['private_key_pem', 'private_key', 'secret', 'passphrase'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('egs_private_key_references', $column),
                'Forbidden column must not exist: '.$column,
            );
        }
    }

    #[Test]
    public function create_reference_is_workspace_and_egs_scoped(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $pem = $this->fixturePem('test-only-egs-a.key.pem');

        $reference = $this->referenceService()->createActiveDevReference(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
            'fp-test-a-'.bin2hex(random_bytes(4)),
            $pem,
        );

        $this->assertSame((int) $workspace->id, (int) $reference->workspace_id);
        $this->assertSame((int) $egs->id, (int) $reference->egs_unit_id);
        $this->assertSame(EInvoicingEnvironment::Sandbox, $reference->environment);
        $this->assertSame(PrivateKeyReferenceStatus::Active, $reference->status);
        $this->assertTrue(app(PrivateKeyStore::class)->health($reference));
    }

    #[Test]
    public function missing_reference_fails_closed(): void
    {
        [$workspace] = $this->workspaceWithEgs();
        $this->expectException(PrivateKeyStoreException::class);
        app(PrivateKeyReferenceResolver::class)->resolve(
            (int) $workspace->id,
            999_999,
            EInvoicingEnvironment::Sandbox,
        );
    }

    #[Test]
    public function cross_workspace_resolution_is_rejected(): void
    {
        [$workspaceA, $egsA] = $this->workspaceWithEgs('A');
        [$workspaceB] = $this->workspaceWithEgs('B');
        app(WorkspaceContext::class)->set($workspaceA);
        $reference = $this->referenceService()->createActiveDevReference(
            (int) $workspaceA->id,
            (int) $egsA->id,
            EInvoicingEnvironment::Sandbox,
            'fp-cross-ws',
            $this->fixturePem('test-only-egs-a.key.pem'),
        );

        $this->expectException(PrivateKeyStoreException::class);
        $this->expectExceptionMessage('workspace');

        app(PrivateKeyReferenceResolver::class)->resolve(
            (int) $workspaceB->id,
            (int) $reference->id,
            EInvoicingEnvironment::Sandbox,
        );
    }

    #[Test]
    public function cross_egs_resolution_is_rejected(): void
    {
        [$workspace, $egsA] = $this->workspaceWithEgs();
        $egsB = EgsUnit::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Second EGS',
            'next_icv' => 1,
            'status' => EgsUnit::STATUS_ACTIVE,
        ]);

        $reference = $this->referenceService()->createActiveDevReference(
            (int) $workspace->id,
            (int) $egsA->id,
            EInvoicingEnvironment::Sandbox,
            'fp-cross-egs',
            $this->fixturePem('test-only-egs-a.key.pem'),
        );

        $this->expectException(PrivateKeyStoreException::class);
        $this->expectExceptionMessage('EGS');

        app(PrivateKeyReferenceResolver::class)->resolve(
            (int) $workspace->id,
            (int) $reference->id,
            EInvoicingEnvironment::Sandbox,
            egsUnitId: (int) $egsB->id,
        );
    }

    #[Test]
    public function cross_environment_resolution_is_rejected(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $reference = $this->referenceService()->createActiveDevReference(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
            'fp-env',
            $this->fixturePem('test-only-egs-a.key.pem'),
        );

        $this->expectException(PrivateKeyStoreException::class);
        $this->expectExceptionMessage('environment');

        app(PrivateKeyReferenceResolver::class)->resolve(
            (int) $workspace->id,
            (int) $reference->id,
            EInvoicingEnvironment::Production,
        );
    }

    #[Test]
    public function revoked_and_destroyed_references_fail_closed(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $service = $this->referenceService();
        $reference = $service->createActiveDevReference(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
            'fp-life',
            $this->fixturePem('test-only-egs-a.key.pem'),
        );

        $service->revoke($reference);
        $this->expectException(PrivateKeyStoreException::class);
        app(PrivateKeyReferenceResolver::class)->resolve(
            (int) $workspace->id,
            (int) $reference->id,
            EInvoicingEnvironment::Sandbox,
        );
    }

    #[Test]
    public function destroyed_reference_is_terminal(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $service = $this->referenceService();
        $reference = $service->createActiveDevReference(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
            'fp-destroy',
            $this->fixturePem('test-only-egs-a.key.pem'),
        );

        $destroyed = $service->destroy($reference);
        $this->assertSame(PrivateKeyReferenceStatus::Destroyed, $destroyed->status);
        $this->assertFalse(app(PrivateKeyStore::class)->health($destroyed));

        $this->expectException(PrivateKeyStoreException::class);
        app(PrivateKeyReferenceResolver::class)->resolve(
            (int) $workspace->id,
            (int) $destroyed->id,
            EInvoicingEnvironment::Sandbox,
        );
    }

    #[Test]
    public function revoked_reference_cannot_be_reactivated_via_service(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $service = $this->referenceService();
        $reference = $service->createActiveDevReference(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
            'fp-revoke',
            $this->fixturePem('test-only-egs-a.key.pem'),
        );

        $revoked = $service->revoke($reference);
        $this->assertSame(PrivateKeyReferenceStatus::Revoked, $revoked->status);
        $this->assertSame(PrivateKeyReferenceStatus::Revoked, $service->revoke($revoked)->status);
    }

    #[Test]
    public function dev_store_rejected_when_treated_as_production(): void
    {
        $guard = new PrivateKeyStoreEnvironmentGuard(treatAsProduction: true);
        $store = new DevMemoryPrivateKeyStore($guard);

        [$workspace, $egs] = $this->workspaceWithEgs();
        $reference = PrivateKeyReference::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'egs_unit_id' => $egs->id,
            'environment' => EInvoicingEnvironment::Sandbox,
            'store_driver' => \App\Enums\EInvoicing\PrivateKeyStoreDriver::DevMemory,
            'store_key' => (string) \Illuminate\Support\Str::uuid(),
            'public_key_fingerprint' => 'fp-prod-guard',
            'status' => PrivateKeyReferenceStatus::Active,
        ]);

        $this->expectException(PrivateKeyStoreException::class);
        $store->store($reference, $this->fixturePem('test-only-egs-a.key.pem'), 'fp-prod-guard');
    }

    #[Test]
    public function model_json_does_not_expose_pem_or_store_key(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $reference = $this->referenceService()->createActiveDevReference(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
            'fp-serial',
            $this->fixturePem('test-only-egs-a.key.pem'),
        );

        $json = json_encode($reference->toArray());
        $this->assertIsString($json);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $json);
        $this->assertStringNotContainsString('BEGIN EC PRIVATE KEY', $json);
        $this->assertStringNotContainsString((string) $reference->store_key, $json);
    }

    #[Test]
    public function exceptions_do_not_contain_pem(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $pem = $this->fixturePem('test-only-egs-a.key.pem');

        try {
            $this->referenceService()->createActiveDevReference(
                (int) $workspace->id,
                (int) $egs->id,
                EInvoicingEnvironment::Sandbox,
                $pem,
                $pem,
            );
            $this->fail('Expected fingerprint validation to fail.');
        } catch (PrivateKeyStoreException $exception) {
            $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $exception->getMessage());
        }
    }

    #[Test]
    public function dev_store_sign_works_in_test_environment(): void
    {
        [$workspace, $egs] = $this->workspaceWithEgs();
        $reference = $this->referenceService()->createActiveDevReference(
            (int) $workspace->id,
            (int) $egs->id,
            EInvoicingEnvironment::Sandbox,
            'fp-sign',
            $this->fixturePem('test-only-egs-a.key.pem'),
        );

        $signature = app(PrivateKeyStore::class)->sign($reference, 'digest-bytes');
        $this->assertNotSame('', $signature);
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
            'name' => 'Phase3C Workspace '.$suffix,
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

    private function referenceService(): PrivateKeyReferenceService
    {
        return app(PrivateKeyReferenceService::class);
    }

    private function fixturePem(string $file): string
    {
        $path = base_path('tests/Fixtures/EInvoicing/'.$file);
        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        return $contents;
    }
}
