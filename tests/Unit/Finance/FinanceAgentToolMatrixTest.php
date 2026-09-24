<?php

namespace Tests\Unit\Finance;

use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Agent\FinanceAgentResultMinimizer;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolExecutor;
use App\Services\Finance\Assistant\FinanceAssistantToolRegistry;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\Assistant\Security\FinanceAgentRunState;
use App\Services\Finance\Assistant\Security\FinanceAgentSecurityContext;
use App\Services\Finance\Assistant\Security\FinanceAgentToolSecurityCatalog;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class FinanceAgentToolMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_registered_tool_matches_security_catalog_and_stays_read_only(): void
    {
        $registry = app(FinanceAssistantToolRegistry::class);
        $catalog = app(FinanceAgentToolSecurityCatalog::class);
        $names = array_map(fn (FinanceAssistantTool $tool): string => $tool->name(), $registry->all());

        $catalog->assertRegistryCovered($names);

        foreach ($registry->all() as $tool) {
            $profile = $catalog->profileFor($tool);
            $this->assertSame('read', $profile->riskLevel->value, $tool->name());
            $this->assertSame('read', $profile->accessMode->value, $tool->name());
            $this->assertSame($tool->permission(), $profile->permission, $tool->name());
            $this->assertNotSame('', $profile->auditCategory, $tool->name());
            $this->assertNotSame('', $profile->resultProfile, $tool->name());
        }
    }

    public function test_catalog_rejects_drift(): void
    {
        $catalog = app(FinanceAgentToolSecurityCatalog::class);

        $this->expectException(InvalidArgumentException::class);
        $catalog->assertRegistryCovered(['missing.tool']);
    }

    public function test_catalog_rejects_permission_mismatch(): void
    {
        $tool = new class implements FinanceAssistantTool
        {
            public function name(): string
            {
                return 'sales.today';
            }

            public function description(): string
            {
                return 'drift';
            }

            public function permission(): string
            {
                return 'not.the.real.permission';
            }

            public function parameterSchema(): array
            {
                return [];
            }

            public function validateArguments(array $arguments): array
            {
                return $arguments;
            }

            public function execute($workspace, $user, array $arguments): FinanceAssistantToolResult
            {
                return FinanceAssistantToolResult::unavailable('sales.today', 'unused');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        app(FinanceAgentToolSecurityCatalog::class)->profileFor($tool);
    }

    public function test_each_tool_allows_owner_denies_unprivileged_member_and_strips_workspace_attack(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();
        $owner = User::factory()->create();
        $limited = User::factory()->create();
        $workspaceA->users()->attach($owner->id, ['membership_role' => 'owner', 'status' => 'active', 'joined_at' => now()]);
        $workspaceA->users()->attach($limited->id, ['membership_role' => 'agent', 'status' => 'active', 'joined_at' => now()]);

        Customer::factory()->create([
            'workspace_id' => $workspaceB->id,
            'name' => 'BRAVO-ONLY-MARKER',
        ]);

        $registry = app(FinanceAssistantToolRegistry::class);
        $executor = app(FinanceAssistantToolExecutor::class);
        $minimizer = app(FinanceAgentResultMinimizer::class);
        app(FinanceAgentRunState::class)->begin(FinanceAgentSecurityContext::fromActors($workspaceA, $owner, null, 'fai_matrix'));

        app(WorkspaceContext::class)->runFor($workspaceA, function () use ($registry, $executor, $minimizer, $workspaceA, $workspaceB, $owner, $limited): void {
            foreach ($registry->all() as $tool) {
                $allowed = $executor->execute($workspaceA, $owner, [
                    'name' => $tool->name(),
                    'arguments' => $this->safeArguments($tool->name(), $workspaceB->id),
                ]);
                $this->assertInstanceOf(FinanceAssistantToolResult::class, $allowed, $tool->name());

                $encoded = json_encode($minimizer->minimize($allowed), JSON_UNESCAPED_UNICODE);
                $this->assertStringNotContainsString('BRAVO-ONLY-MARKER', (string) $encoded, $tool->name());
                $this->assertStringNotContainsString('workspace_id', (string) $encoded, $tool->name());
                $this->assertStringNotContainsString('"customer_id"', (string) $encoded, $tool->name());

                $denied = $executor->execute($workspaceA, $limited, [
                    'name' => $tool->name(),
                    'arguments' => $this->safeArguments($tool->name(), $workspaceB->id),
                ]);
                $this->assertFalse($denied->available, $tool->name());
                $this->assertSame('permission_denied', $denied->reason, $tool->name());

                $invalid = $executor->execute($workspaceA, $owner, [
                    'name' => $tool->name(),
                    'arguments' => ['from' => 'not-a-date', 'invoice_number' => str_repeat('X', 80)],
                ]);
                $this->assertInstanceOf(FinanceAssistantToolResult::class, $invalid, $tool->name());
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function safeArguments(string $tool, int $foreignWorkspaceId): array
    {
        $attack = [
            'workspace_id' => $foreignWorkspaceId,
            'tenant_id' => $foreignWorkspaceId,
            'user_id' => 1,
            'customer_id' => 1,
            'invoice_id' => 1,
            'permission' => 'finance.view',
        ];

        return match ($tool) {
            'invoices.lookup', 'invoices.details' => $attack + ['invoice_number' => 'INV-DOES-NOT-EXIST'],
            'invoices.by_customer', 'customers.invoices', 'customers.balance', 'customers.overdue', 'customers.statement' => $attack + ['query' => 'BRAVO-ONLY-MARKER', 'customer_name' => 'BRAVO-ONLY-MARKER'],
            'customers.lookup' => $attack + ['query' => 'BRAVO-ONLY-MARKER'],
            'contracts.lookup', 'contracts.details', 'contracts.balance', 'contracts.invoices', 'contracts.unbilled', 'contracts.schedules' => $attack + ['contract_number' => 'CTR-MISSING', 'query' => 'BRAVO-ONLY-MARKER'],
            'payroll.employees_count' => $attack + ['status' => 'active'],
            default => $attack + ['preset' => 'month'],
        };
    }
}
