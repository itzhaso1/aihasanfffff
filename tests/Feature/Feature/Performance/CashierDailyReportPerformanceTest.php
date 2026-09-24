<?php

namespace Tests\Feature\Feature\Performance;

use App\Models\Order;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pos\PosOrderStatsService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CashierDailyReportPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
    }

    public function test_daily_report_compact_mode_skips_order_payload(): void
    {
        [$user, $workspace] = $this->makePosWorkspace();

        Order::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'order_number' => 'ORD-PERF-1',
            'source' => 'pos',
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'currency' => 'SAR',
            'subtotal' => 10,
            'total_amount' => 10,
            'placed_at' => now(),
            'pos_status' => 'completed',
        ]);

        Sanctum::actingAs($user);
        $response = $this->withHeader('X-Workspace-Id', (string) $workspace->id)
            ->getJson('/api/cashier/v1/reports/daily?include_orders=0&include_invoices=0')
            ->assertOk();

        $this->assertSame([], $response->json('data.all_orders'));
        $this->assertSame(1, $response->json('data.summary.orders_count'));
        $this->assertFalse($response->json('meta.include_orders'));
    }

    public function test_daily_summary_aggregate_uses_single_query(): void
    {
        [$user, $workspace] = $this->makePosWorkspace();
        $today = now()->toDateString();

        for ($i = 0; $i < 5; $i++) {
            Order::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'order_number' => 'ORD-AGG-'.$i,
                'source' => 'pos',
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'currency' => 'SAR',
                'subtotal' => 10,
                'total_amount' => 10,
                'placed_at' => $today,
                'pos_status' => 'completed',
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $summary = app(WorkspaceContext::class)->runFor($workspace, function () use ($today) {
            DB::flushQueryLog();
            DB::enableQueryLog();

            return app(PosOrderStatsService::class)->dailyReportSummary($today, $today);
        });

        $queryCount = count(DB::getQueryLog());
        $this->assertSame(5, $summary['orders_count']);
        $this->assertLessThanOrEqual(3, $queryCount);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function makePosWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->enableWorkspaceFeature($workspace, 'pos');

        return [$user, $workspace->fresh()];
    }
}
