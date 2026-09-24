<?php

/**
 * One-shot Phase 3.1 live Gemini verification. Run:
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

use App\Models\AiSetting;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use App\Services\AI\Inbox\InboxAgentOrchestrator;
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
    'ai.inbox_agent.enabled' => true,
    'ai.inbox_agent.tools_enabled' => true,
    'ai.inbox_agent.max_iterations' => 5,
    'ai.inbox_agent.max_tool_rounds' => 3,
    'ai.default_provider' => 'google_ai_studio',
]);

$registry = app(InboxAgentToolRegistry::class);
if (! $registry->has('search_products')) {
    $registry->register(app(SearchProductsTool::class));
}

$startedAt = microtime(true);
$report = [
    'executed' => true,
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

    [$conversation, $message, $setting] = app(WorkspaceContext::class)->runFor($workspace, function () use ($workspace, $plan): array {
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

        $setting = AiSetting::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Phase31 Assistant',
            'instructions' => 'When the customer asks about products, use search_products before answering. Be concise.',
            'provider' => 'google_ai_studio',
            'model' => (string) config('ai.google_ai_studio.model', 'gemini-2.5-flash'),
            'temperature' => 0.2,
            'max_tokens' => 512,
            'is_active' => true,
        ]);

        return [$conversation, $message, $setting];
    });

    $result = app(InboxAgentOrchestrator::class)->run($workspace, $conversation, $message, $setting);

    $firstRun = $result->runs[0] ?? null;
    $firstRaw = is_array($firstRun?->providerRaw) ? $firstRun->providerRaw : [];
    $parts = $firstRaw['candidates'][0]['content']['parts'] ?? [];
    $functionCalls = [];
    foreach (is_array($parts) ? $parts : [] as $part) {
        if (is_array($part) && isset($part['functionCall']) && is_array($part['functionCall'])) {
            $args = $part['functionCall']['args'] ?? [];
            if ($args instanceof stdClass) {
                $args = (array) $args;
            }
            $functionCalls[] = [
                'name' => (string) ($part['functionCall']['name'] ?? ''),
                'id' => (string) ($part['functionCall']['id'] ?? ''),
                'arguments' => is_array($args) ? $args : [],
            ];
        }
    }

    $report['gemini_native_function_call'] = $functionCalls !== [];
    $report['tool_name'] = $functionCalls[0]['name'] ?? null;
    $report['tool_call_id'] = $functionCalls[0]['id'] ?? null;
    $report['sanitized_tool_arguments'] = $functionCalls[0]['arguments'] ?? null;
    $report['agent_success'] = $result->isSuccessful();
    $report['agent_iterations'] = $result->iterations;
    $report['agent_tool_rounds'] = $result->metadata['agent_tool_rounds'] ?? null;
    $report['agent_tool_calls_total'] = $result->metadata['agent_tool_calls_total'] ?? null;
    $report['agent_tool_outcome'] = $result->metadata['agent_tool_outcome'] ?? null;
    $report['tokens_used'] = $result->tokensUsed;
    $report['latency_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
    $report['second_generation_occurred'] = ($result->iterations ?? 0) >= 2;
    $report['final_reply_chars'] = mb_strlen($result->reply);
    $report['final_reply_preview'] = mb_substr($result->reply, 0, 160);
    $report['first_run_status'] = $firstRun?->status;
    $report['run_count'] = count($result->runs);

    $secondRun = $result->runs[1] ?? null;
    $secondRaw = is_array($secondRun?->providerRaw) ? $secondRun->providerRaw : [];
    $report['second_run_status'] = $secondRun?->status;
    $report['second_run_finish_reason'] = $secondRaw['candidates'][0]['finishReason'] ?? null;
    $secondParts = $secondRaw['candidates'][0]['content']['parts'] ?? [];
    $report['second_run_part_keys'] = array_values(array_unique(array_map(static function ($part): string {
        if (! is_array($part)) {
            return 'invalid';
        }

        return implode(',', array_keys($part));
    }, is_array($secondParts) ? $secondParts : [])));
    $report['second_run_has_function_call'] = collect(is_array($secondParts) ? $secondParts : [])
        ->contains(fn ($part): bool => is_array($part) && isset($part['functionCall']));
    $report['second_run_prompt_feedback'] = $secondRaw['promptFeedback'] ?? null;
    $report['second_run_provider_error'] = $secondRaw['provider_error'] ?? null;
    $report['second_run_http_status'] = $secondRaw['http_status'] ?? null;
    $report['second_run_body_preview'] = isset($secondRaw['body_preview'])
        ? mb_substr((string) $secondRaw['body_preview'], 0, 800)
        : null;

    if (! $report['gemini_native_function_call']) {
        $report['failure_category'] = 'tool-call parsing';
        $report['sanitized_error'] = 'Gemini did not return a native functionCall in the first provider response.';
    } elseif (($report['tool_name'] ?? '') !== 'search_products') {
        $report['failure_category'] = 'tool-call parsing';
        $report['sanitized_error'] = 'Unexpected tool name from Gemini.';
    } elseif (! $report['agent_success']) {
        $report['failure_category'] = 'final provider response';
        $report['sanitized_error'] = $result->failureReason ?? 'Agent failed after tool round.';
    } elseif (($report['agent_tool_rounds'] ?? 0) < 1) {
        $report['failure_category'] = 'Laravel tool execution';
        $report['sanitized_error'] = 'No tool round recorded by Agent loop.';
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

exit(isset($report['agent_success']) && $report['agent_success'] === true && ($report['gemini_native_function_call'] ?? false) ? 0 : 1);
