<?php

/**
 * One-shot live Gemini verification of the Hasim Inbox path. Run:
 * php scripts/inbox-phase31-live-verify.php
 */

declare(strict_types=1);

// Isolated in-memory DB for this one-shot script (does not touch project DB).
$_ENV['APP_ENV'] = 'local';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
putenv('APP_ENV=local');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');

use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeService;
use App\AI\Inbox\HasimInboxRuntime;
use App\Models\AiSetting;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\Inbox\Tools\InboxAgentToolRegistry;
use App\Services\AI\Inbox\Tools\SearchProductsTool;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! config('services.google_ai_studio.key')) {
    fwrite(STDERR, json_encode(['executed' => false, 'reason' => 'missing_api_key'], JSON_PRETTY_PRINT).PHP_EOL);
    exit(2);
}

Artisan::call('migrate', ['--force' => true, '--quiet' => true]);

config([
    'ai.inbox_agent.tools_enabled' => true,
    'ai.default_provider' => 'google_ai_studio',
]);

$registry = app(InboxAgentToolRegistry::class);
if (! $registry->has('search_products')) {
    $registry->register(app(SearchProductsTool::class));
}

$startedAt = microtime(true);
$report = [
    'executed' => true,
    'path' => 'hasim_inbox_runtime',
    'provider' => 'google_ai_studio',
    'model' => (string) config('ai.google_ai_studio.model', 'gemini-2.5-flash'),
    'failure_category' => null,
    'sanitized_error' => null,
];

try {
    $workspace = Workspace::factory()->create(['type' => 'company', 'name' => 'Phase31 Verify WS']);

    $plan = Plan::query()->create([
        'code' => 'phase31_live_'.uniqid('', true),
        'name' => 'Phase31 Live',
        'workspace_type' => 'company',
        'billing_period' => 'monthly',
        'currency' => 'USD',
        'price' => 0,
        'is_active' => true,
        'features' => ['ai', 'conversations', 'smart_replies'],
        'limits' => ['ai_usage' => 10000, 'ai_tokens' => 1000000],
    ]);

    [$conversation, $message] = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan): array {
        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);

        $customer = Customer::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Live Verify Customer',
            'phone' => '+966500000777',
            'email' => 'phase31-verify@example.test',
            'orders_count' => 0,
            'total_purchases' => 0,
            'metadata' => [],
        ]);

        $conversation = Conversation::query()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'web',
            'status' => 'open',
            'ai_enabled' => true,
        ]);

        $message = Message::query()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'عندكم حذاء أسود؟',
            'ai_generated' => false,
        ]);

        ChatCatalogItem::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'حذاء أسود',
            'slug' => 'phase31-black-shoe',
            'sku' => 'P31-SHOE-BLACK',
            'price' => 10,
            'currency' => 'USD',
            'stock' => 3,
            'is_active' => true,
            'item_kind' => 'product',
            'tangibility' => 'tangible',
        ]);

        AiSetting::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Phase31 Assistant',
            'instructions' => 'When the customer asks about products, use search_products before answering. Be concise.',
            'provider' => 'google_ai_studio',
            'model' => (string) config('ai.google_ai_studio.model', 'gemini-2.5-flash'),
            'temperature' => 0.2,
            'max_tokens' => 512,
            'is_active' => true,
        ]);

        return [$conversation, $message];
    });

    $draft = app(EmployeeFactory::class)->fromArray([
        'name' => 'Nora',
        'role' => 'sales_agent',
        'persona' => ['language' => 'ar', 'dialect' => 'saudi'],
        'skills' => ['sales'],
    ]);
    app(EmployeeService::class)->create($workspace, $draft);

    $result = app(HasimInboxRuntime::class)->reply($conversation, $message);
    $decision = $result->response?->decision;
    $toolCalls = $decision?->toolCalls ?? [];
    $firstTool = $toolCalls[0] ?? null;

    $report['hasim_deliverable'] = $result->deliverable;
    $report['agent_success'] = $result->deliverable;
    $report['runtime_status'] = $decision?->status->value;
    $report['employee_id'] = $decision?->employeeId;
    $report['gemini_native_function_call'] = $toolCalls !== [];
    $report['tool_name'] = $firstTool?->toolName;
    $report['tool_call_id'] = $firstTool?->callId;
    $report['sanitized_tool_arguments'] = $firstTool?->arguments;
    $report['agent_tool_calls_total'] = count($toolCalls);
    $report['tokens_used'] = $decision?->modelUsage?->totalTokens();
    $report['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    $report['final_reply_chars'] = mb_strlen($result->reply);
    $report['final_reply_preview'] = mb_substr($result->reply, 0, 160);
    $report['failure_code'] = $decision?->failure?->code->value;

    if (! $result->deliverable) {
        $report['failure_category'] = 'hasim_runtime';
        $report['sanitized_error'] = $decision?->failure?->code->value ?? 'Hasim Inbox run was not deliverable.';
    }
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    $report['failure_category'] = str_contains(strtolower($message), 'api key')
        ? 'authentication/configuration'
        : (str_contains(strtolower($message), 'timeout') ? 'timeout/network' : 'provider request schema');
    $report['sanitized_error'] = mb_substr($message, 0, 500);
    $report['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;

exit(isset($report['agent_success']) && $report['agent_success'] === true ? 0 : 1);
