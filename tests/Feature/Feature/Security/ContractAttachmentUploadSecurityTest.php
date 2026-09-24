<?php

namespace Tests\Feature\Feature\Security;

use App\Models\Contract\Contract;
use App\Models\Contract\ContractAttachment;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\Contracts\ContractService;
use App\Services\Finance\FinanceBootstrapService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ContractAttachmentUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_web_contract_attachment_upload_rejects_disallowed_file_type(): void
    {
        Storage::fake('local');
        [$owner, $workspace, $contract] = $this->seedDraftContract();
        $before = ContractAttachment::withoutGlobalScopes()->count();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.attachments.store', $contract), [
                'attachments' => [UploadedFile::fake()->create('shell.php', 10, 'application/x-php')],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->assertSame($before, ContractAttachment::withoutGlobalScopes()->count());
        Storage::disk('local')->assertDirectoryEmpty('workspaces/'.$workspace->id.'/contracts/'.$contract->id);
    }

    public function test_web_contract_attachment_upload_accepts_pdf(): void
    {
        Storage::fake('local');
        [$owner, $workspace, $contract] = $this->seedDraftContract();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.finance.contracts.attachments.store', $contract), [
                'attachments' => [UploadedFile::fake()->create('contract.pdf', 20, 'application/pdf')],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $attachment = ContractAttachment::withoutGlobalScopes()->where('contract_id', $contract->id)->first();
        $this->assertNotNull($attachment);
        $this->assertSame('contract.pdf', $attachment->file_name);
        Storage::disk('local')->assertExists($attachment->file_path);
        $this->assertStringNotContainsString('.php', $attachment->file_path);
    }

    public function test_finance_api_contract_attachment_upload_rejects_disallowed_file_type(): void
    {
        Storage::fake('local');
        [$owner, $workspace, $contract] = $this->seedDraftContract();
        Sanctum::actingAs($owner);
        $headers = ['X-Workspace-Id' => (string) $workspace->id];
        $before = ContractAttachment::withoutGlobalScopes()->count();

        $this->withHeaders($headers)
            ->post('/api/finance/v1/contracts/'.$contract->id.'/attachments', [
                'attachments' => [UploadedFile::fake()->create('payload.php', 10, 'application/x-php')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['attachments.0']);

        $this->assertSame($before, ContractAttachment::withoutGlobalScopes()->count());
    }

    public function test_finance_api_contract_attachment_upload_accepts_pdf(): void
    {
        Storage::fake('local');
        [$owner, $workspace, $contract] = $this->seedDraftContract();
        Sanctum::actingAs($owner);
        $headers = ['X-Workspace-Id' => (string) $workspace->id];

        $this->withHeaders($headers)
            ->post('/api/finance/v1/contracts/'.$contract->id.'/attachments', [
                'attachments' => [UploadedFile::fake()->create('signed.pdf', 18, 'application/pdf')],
            ])
            ->assertOk()
            ->assertJsonPath('data.attachments.0.file_name', 'signed.pdf');
    }

    public function test_contract_service_rejects_unsafe_upload_even_if_validation_bypassed(): void
    {
        Storage::fake('local');
        [, $workspace, $contract] = $this->seedDraftContract();

        $this->expectException(RuntimeException::class);

        app(WorkspaceContext::class)->runFor($workspace, function () use ($contract): void {
            app(ContractService::class)->storeAttachments($contract, [
                UploadedFile::fake()->create('shell.php', 10, 'application/x-php'),
            ]);
        });
    }

    public function test_cross_workspace_contract_attachment_upload_is_not_found(): void
    {
        Storage::fake('local');
        [, $workspaceA, $contractA] = $this->seedDraftContract();
        [$ownerB, $workspaceB] = $this->createWorkspaceOwner('Workspace B');
        Sanctum::actingAs($ownerB);

        $this->withHeaders(['X-Workspace-Id' => (string) $workspaceB->id])
            ->post('/api/finance/v1/contracts/'.$contractA->id.'/attachments', [
                'attachments' => [UploadedFile::fake()->create('blocked.pdf', 10, 'application/pdf')],
            ])
            ->assertNotFound();
    }

    /**
     * @return array{0: User, 1: Workspace, 2: Contract}
     */
    private function seedDraftContract(): array
    {
        [$owner, $workspace] = $this->createWorkspaceOwner('Contract Upload Security');
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Contract Customer',
            'phone' => '0501112233',
        ]);

        $contract = $this->inWorkspace($workspace, function () use ($workspace, $customer, $owner): Contract {
            return app(ContractService::class)->create($workspace, [
                'title' => 'Draft Contract',
                'customer_id' => $customer->id,
                'value' => 1000,
                'currency' => 'SAR',
            ], (int) $owner->id);
        });

        return [$owner, $workspace, $contract];
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(string $name): array
    {
        app(WorkspaceContext::class)->clear();
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => $name,
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        foreach (['finance', 'products', 'orders', 'customers'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

        $plan = Plan::query()->where('is_active', true)->first();
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

        $this->inWorkspace($workspace, function () use ($workspace): void {
            app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);
            app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        });

        return [$user, $workspace];
    }
}
