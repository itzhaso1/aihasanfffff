<?php

namespace Tests\Unit\Commerce;

use App\Models\Workspace;
use App\Services\Commerce\ChatCommerceTaxRateResolver;
use App\Services\Commerce\CommerceWorkspaceProfileService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ChatCommerceTaxRateResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_fallback_is_fifteen_when_workspace_has_no_chat_tax_setting(): void
    {
        $workspace = Workspace::factory()->create();

        $profile = app(ChatCommerceTaxRateResolver::class)->defaultProfileFixed((int) $workspace->id);

        $this->assertSame('standard', $profile['type']);
        $this->assertSame('15.00', $profile['rate']);
    }

    public function test_workspace_chat_tax_setting_is_used_without_finance_tables(): void
    {
        $workspace = Workspace::factory()->create();
        app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace): void {
            app(CommerceWorkspaceProfileService::class)->updateDefaultTaxRate($workspace, '8.25');
        });

        $this->assertFalse(
            str_contains(
                file_get_contents(app_path('Services/Commerce/ChatCommerceTaxRateResolver.php')),
                'finance_tax_rates',
            )
        );
        $this->assertFalse(
            str_contains(
                file_get_contents(app_path('Services/Commerce/ChatCommerceTaxRateResolver.php')),
                'finance_settings',
            )
        );

        if (Schema::hasTable('finance_settings')) {
            DB::table('finance_settings')->updateOrInsert(
                ['workspace_id' => $workspace->id],
                ['default_vat_rate' => '99.00'],
            );
        }
        if (Schema::hasTable('finance_tax_rates')) {
            DB::table('finance_tax_rates')->insert([
                'workspace_id' => $workspace->id,
                'name' => 'Ignored Finance Rate',
                'code' => 'IGN',
                'type' => 'standard',
                'rate' => '99.00',
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $profile = app(ChatCommerceTaxRateResolver::class)->defaultProfileFixed((int) $workspace->id);

        $this->assertSame('8.25', $profile['rate']);
    }

    public function test_workspace_a_does_not_read_workspace_b_tax_setting(): void
    {
        $workspaceA = Workspace::factory()->create();
        $workspaceB = Workspace::factory()->create();

        app(WorkspaceContext::class)->runFor($workspaceA, function () use ($workspaceA): void {
            app(CommerceWorkspaceProfileService::class)->updateDefaultTaxRate($workspaceA, '5.00');
        });
        app(WorkspaceContext::class)->runFor($workspaceB, function () use ($workspaceB): void {
            app(CommerceWorkspaceProfileService::class)->updateDefaultTaxRate($workspaceB, '10.00');
        });

        $this->assertSame('5.00', app(ChatCommerceTaxRateResolver::class)->defaultProfileFixed((int) $workspaceA->id)['rate']);
        $this->assertSame('10.00', app(ChatCommerceTaxRateResolver::class)->defaultProfileFixed((int) $workspaceB->id)['rate']);
    }
}
