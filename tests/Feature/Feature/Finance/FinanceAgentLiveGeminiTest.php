<?php

namespace Tests\Feature\Feature\Finance;

use App\Enums\Finance\TaxProfileType;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceFeatureFlag;
use App\Services\AI\Contracts\GeminiInteractionsClient;
use App\Services\AI\Gemini\GeminiInteractionsHttpClient;
use App\Services\Finance\FinanceBootstrapService;
use App\Services\Finance\InvoiceService;
use App\Support\Tenancy\WorkspaceContext;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class FinanceAgentLiveGeminiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        RateLimiter::clear('mobile-ai');
    }

    public function test_real_gemini_interactions_function_calling_round_trip(): void
    {
        if (! $this->liveGeminiEnabled()) {
            $this->markTestSkipped('NOT TESTABLE: FINANCE_AGENT_LIVE_GEMINI is not enabled.');
        }

        $apiKey = $this->liveApiKeyFromDotEnv();
        if ($apiKey === '') {
            $this->markTestSkipped('NOT TESTABLE: GEMINI_API_KEY is not configured in .env.');
        }

        config([
            'finance_assistant.agent_enabled' => true,
            'finance_assistant.heuristic_planner_in_testing' => false,
            'services.google_ai_studio.key' => $apiKey,
        ]);
        $this->app->bind(GeminiInteractionsClient::class, GeminiInteractionsHttpClient::class);
        $this->assertInstanceOf(GeminiInteractionsHttpClient::class, $this->app->make(GeminiInteractionsClient::class));

        [$owner, $workspace] = $this->createWorkspaceOwner();
        $customer = $this->customer($workspace, 'Live Buyer');
        $this->issueSales($workspace, $customer, (int) $owner->id, '5000.00');
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/finance/v1/assistant/ask', [
            'question' => 'كم مبيعاتنا اليوم؟',
            'locale' => 'ar',
        ], ['X-Workspace-Id' => (string) $workspace->id]);

        $response->assertOk();
        $tools = $response->json('data.tools_used') ?? [];
        $answer = (string) $response->json('data.answer');
        $this->assertNotEmpty($tools, 'Live Gemini did not request a Laravel tool.');
        $this->assertStringNotContainsString('999999', $answer);

        $audit = AuditLog::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('action', 'finance.assistant.ask')
            ->latest('occurred_at')
            ->first();

        $this->assertNotNull($audit?->meta['interaction_id'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) ($audit?->meta['round_count'] ?? 0));
        $this->assertNotSame('FakeGeminiInteractionsClient', $audit?->meta['model'] ?? null);
    }

    private function liveGeminiEnabled(): bool
    {
        $flag = getenv('FINANCE_AGENT_LIVE_GEMINI');
        if ($flag === false || $flag === '') {
            $flag = (string) env('FINANCE_AGENT_LIVE_GEMINI', '');
        }

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN) === true;
    }

    private function liveApiKeyFromDotEnv(): string
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            return '';
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return '';
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^GEMINI_API_KEY\s*=\s*(.*)$/', $line, $matches) !== 1) {
                continue;
            }

            return trim($matches[1], " \t\"'");
        }

        return '';
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function createWorkspaceOwner(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Live Gemini Co',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        app(WorkspaceContext::class)->set($workspace);

        foreach (['finance', 'products', 'orders', 'customers', 'payments', 'ai'] as $feature) {
            WorkspaceFeatureFlag::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id, 'feature_key' => $feature],
                ['workspace_id' => $workspace->id, 'feature_key' => $feature, 'enabled' => true, 'source' => 'manual']
            );
        }

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

        app(FinanceBootstrapService::class)->ensureWorkspaceFinanceSetup($workspace);
        app(PermissionRegistrar::class)->setPermissionsTeamId($workspace->id);

        return [$user, $workspace->fresh()];
    }

    private function customer(Workspace $workspace, string $name): Customer
    {
        app(WorkspaceContext::class)->set($workspace);

        return Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => '05'.random_int(10000000, 99999999),
            'email' => 'live.'.uniqid('', true).'@example.com',
        ]);
    }

    private function issueSales(Workspace $workspace, Customer $customer, int $actorId, string $amount): void
    {
        app(WorkspaceContext::class)->set($workspace);
        app(InvoiceService::class)->create($workspace, [
            'type' => 'sales',
            'customer_id' => $customer->id,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'currency' => 'SAR',
            'invoice_status' => 'issued',
            'tax_profile_type' => TaxProfileType::ZeroRated->value,
            'tax_rate' => 0,
            'items' => [[
                'product_name' => 'Live line',
                'quantity' => 1,
                'unit_price' => $amount,
                'discount' => 0,
                'tax_rate' => 0,
                'tax_type' => TaxProfileType::ZeroRated->value,
            ]],
        ], $actorId);
    }
}
