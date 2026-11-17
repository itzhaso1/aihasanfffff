<?php

namespace App\Console\Commands;

use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\Models\AiEmployee;
use App\AI\Inbox\HasimInboxRuntime;
use App\AI\Models\ProviderId;
use App\AI\Training\GreetingStyleSelector;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use App\Services\AI\AIService;
use App\Services\Chat\ChatCatalogRetrievalService;
use App\Services\Platform\PlatformAiConfiguration;
use App\Support\Chat\CatalogBrowseQueryDetector;
use App\Support\Chat\GulfArabicInterpreter;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class AiDiagnoseCommand extends Command
{
    protected $signature = 'ai:diagnose {workspace? : Optional workspace ID to inspect}';

    protected $description = 'Deep diagnostic tool for AI chatbot, catalog retrieval, API keys, cache, and queue on production';

    public function handle(
        PlatformAiConfiguration $platformAi,
        CatalogBrowseQueryDetector $browseDetector,
        GreetingStyleSelector $greetingSelector,
        ChatCatalogRetrievalService $catalogRetrieval,
        GulfArabicInterpreter $arabicInterpreter,
        WorkspaceContext $workspaceContext,
    ): int {
        $this->info('=====================================================');
        $this->info('           HASEM AI PRODUCTION DIAGNOSTICS           ');
        $this->info('=====================================================');

        // 1. PHP & OPcache & Laravel Cache Checks
        $this->newLine();
        $this->line('<fg=cyan;options=bold>1. Runtime & Cache Status:</>');
        $this->checkRuntimeCaches();

        // 2. AI Platform & API Keys
        $this->newLine();
        $this->line('<fg=cyan;options=bold>2. AI Gateway & Credentials:</>');
        $this->checkAiCredentials($platformAi);

        // 3. Workspace & Employee
        $this->newLine();
        $this->line('<fg=cyan;options=bold>3. Workspace & AI Employee:</>');
        try {
            $workspaceId = $this->argument('workspace');
            $workspace = $workspaceId ? Workspace::query()->find($workspaceId) : Workspace::query()->first();

            if (! $workspace) {
                $this->error('   ❌ No workspace found in database!');

                return self::FAILURE;
            }

            $this->line("   • Workspace: <fg=yellow>{$workspace->name}</> (ID: {$workspace->id})");
            $employee = AiEmployee::query()->where('workspace_id', $workspace->id)->where('is_active', true)->first();
            if ($employee) {
                $this->line("   • Active AI Employee: <fg=green>{$employee->name}</> (Role: {$employee->role})");
                if (! empty($employee->instructions)) {
                    $this->line("     - Custom instructions: {$employee->instructions}");
                }
            } else {
                $this->warn('   ⚠️ No active AiEmployee record found for this workspace in ai_employees table!');
            }

            // 4. Catalog Database Items
            $this->newLine();
            $this->line('<fg=cyan;options=bold>4. Products / Books in Database (chat_catalog_items):</>');
            $itemsQuery = ChatCatalogItem::withoutGlobalScopes()->where('workspace_id', $workspace->id);
            $totalItems = $itemsQuery->count();
            $activeItems = (clone $itemsQuery)->where('is_active', true)->count();
            $searchableItems = (clone $itemsQuery)->where('is_active', true)->where('is_searchable', true)->count();

            $this->line("   • Total items: {$totalItems}");
            $this->line("   • Active items: {$activeItems}");
            $this->line("   • Active & Searchable: {$searchableItems}");

            if ($searchableItems === 0) {
                $this->error('   ❌ ZERO searchable items found in chat_catalog_items for this workspace!');
                $this->warn('   💡 If the database has no books/products, the AI CANNOT list them.');
                $this->warn('      Make sure books/products are added to chat_catalog_items with is_active=1 and is_searchable=1.');
            } else {
                $samples = (clone $itemsQuery)->where('is_active', true)->where('is_searchable', true)->limit(5)->get();
                $this->line('   • Sample items in database:');
                foreach ($samples as $sample) {
                    $this->line("     - [ID: {$sample->id}] <fg=green>{$sample->name}</> (Price: {$sample->effectivePrice()->amount()} {$sample->currency})");
                }
            }

            // 5. Query Simulation for "شو الكتب المتوفرة عندك؟"
            $this->newLine();
            $this->line('<fg=cyan;options=bold>5. Query Simulation: "شو الكتب المتوفرة عندك؟"</>');
            $testQuery = 'شو الكتب المتوفرة عندك؟';
            $tokens = $arabicInterpreter->interpret($testQuery)['search_tokens'];

            $isBrowse = $browseDetector->isBrowseCatalogQuery($testQuery, $tokens);
            $this->line('   • isBrowseCatalogQuery: '.($isBrowse ? '<fg=green>true (Catalog will be searched)</>' : '<fg=red>false (NOT recognized as catalog query!)</>'));

            $shouldGreet = $greetingSelector->shouldIncludeGreeting($testQuery);
            $this->line('   • shouldIncludeGreeting: '.($shouldGreet ? '<fg=red>true (Canned greeting will be triggered!)</>' : '<fg=green>false (Direct answer mode)</>'));

            $lookup = $workspaceContext->runFor($workspace, fn () => $catalogRetrieval->lookupForAssistant($workspace, $testQuery, 8));
            $this->line('   • Catalog Retrieval Status: <fg=yellow>'.($lookup['status'] ?? 'unknown').'</>');
            $retrievedCount = count($lookup['items'] ?? []);
            $this->line("   • Items Retrieved: {$retrievedCount}");
            if ($retrievedCount > 0) {
                foreach ($lookup['items'] as $item) {
                    $this->line("     - <fg=green>{$item['name']}</> | {$item['price']}");
                }
            } elseif ($lookup['status'] === 'catalog_empty') {
                $this->warn('   ⚠️ Status is catalog_empty: Database has no discoverable products.');
            } elseif ($lookup['status'] === 'name_not_found') {
                $this->warn('   ⚠️ Status is name_not_found: No products matched the search query.');
            }
        } catch (Throwable $e) {
            $this->error("   ❌ Database operation failed: {$e->getMessage()}");
        }

        // 6. Check Recent Logs for Errors
        $this->newLine();
        $this->line('<fg=cyan;options=bold>6. Recent AI Log Entries (storage/logs/laravel.log):</>');
        $this->checkRecentLogs();

        // 7. Actionable Server Commands
        $this->newLine();
        $this->info('=====================================================');
        $this->info('             REQUIRED SERVER ACTIONS                 ');
        $this->info('=====================================================');
        $this->line('If code was updated on the server, you MUST run:');
        $this->line('  <fg=yellow>1. php artisan optimize:clear</>     (clears config, route, view cache)');
        $this->line('  <fg=yellow>2. php artisan queue:restart</>      (restarts worker daemon with new code)');
        $this->line('  <fg=yellow>3. sudo systemctl reload php8.3-fpm</> (clears PHP OPcache memory)');
        $this->newLine();

        return self::SUCCESS;
    }

    private function checkRuntimeCaches(): void
    {
        $configCached = file_exists(app()->getCachedConfigPath());
        $routesCached = file_exists(app()->getCachedRoutesPath());
        $this->line('   • Config Cached: '.($configCached ? '<fg=yellow>YES (Run php artisan optimize:clear to update)</>' : '<fg=green>NO</>'));
        $this->line('   • Routes Cached: '.($routesCached ? '<fg=yellow>YES (Run php artisan optimize:clear to update)</>' : '<fg=green>NO</>'));
        $this->line('   • Queue Driver: <fg=yellow>'.config('queue.default').'</>');
        $this->line('   • AI Queue Name: <fg=yellow>'.config('inbox.queues.ai', 'ai').'</>');

        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            $opcacheEnabled = is_array($status) && ($status['opcache_enabled'] ?? false);
            $this->line('   • PHP OPcache: '.($opcacheEnabled ? '<fg=yellow>ENABLED (Requires PHP-FPM reload after code upload)</>' : '<fg=green>DISABLED</>'));
        } else {
            $this->line('   • PHP OPcache: <fg=green>Not active on CLI</>');
        }
    }

    private function checkAiCredentials(PlatformAiConfiguration $platformAi): void
    {
        try {
            $snapshot = $platformAi->snapshot();
            $provider = $snapshot->provider;
            $model = $snapshot->model;
            $this->line("   • Configured Provider: <fg=yellow>{$provider}</>");
            $this->line("   • Configured Model: <fg=yellow>{$model}</>");

            $hasCredentials = $platformAi->credentialsPresent($provider);
            $this->line('   • Credentials Present in .env: '.($hasCredentials ? '<fg=green>YES</>' : '<fg=red>NO (Missing API Key!)</>'));

            if (! $hasCredentials) {
                if ($provider === ProviderId::GEMINI) {
                    $this->error('   ❌ Google AI Studio Key is missing! Set GEMINI_API_KEY in .env.');
                } elseif ($provider === ProviderId::OPENAI) {
                    $this->error('   ❌ OpenAI Key is missing! Set OPENAI_API_KEY in .env.');
                }
            }
        } catch (Throwable $e) {
            $this->error("   ❌ Failed reading AI configuration: {$e->getMessage()}");
        }
    }

    private function checkRecentLogs(): void
    {
        $logPath = storage_path('logs/laravel.log');
        if (! File::exists($logPath)) {
            $this->line('   • No log file found at '.$logPath);

            return;
        }

        $lines = array_slice(file($logPath), -100);
        $aiLines = array_filter($lines, fn ($l) => str_contains($l, 'ai.reply') || str_contains($l, 'hasim.inbox') || str_contains($l, 'catalog.assistant'));

        if ($aiLines === []) {
            $this->line('   • No recent AI warning/error lines in the last 100 log lines.');

            return;
        }

        $recent = array_slice($aiLines, -5);
        foreach ($recent as $line) {
            $this->line('   • <fg=yellow>'.trim($line).'</>');
        }
    }
}
