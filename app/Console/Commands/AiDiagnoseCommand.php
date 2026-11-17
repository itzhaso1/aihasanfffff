<?php

namespace App\Console\Commands;

use App\AI\Context\AiContextProvider;
use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Employees\DatabaseEmployeeResolver;
use App\AI\Employees\EmployeeRepository;
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
    protected $signature = 'ai:diagnose {workspace? : Optional workspace ID to inspect} {--conversation= : Optional conversation ID to test context building}';

    protected $description = 'Deep diagnostic tool for AI chatbot, catalog retrieval, API keys, cache, and queue on production';

    public function handle(
        PlatformAiConfiguration $platformAi,
        CatalogBrowseQueryDetector $browseDetector,
        GreetingStyleSelector $greetingSelector,
        ChatCatalogRetrievalService $catalogRetrieval,
        GulfArabicInterpreter $arabicInterpreter,
        WorkspaceContext $workspaceContext,
        EmployeeRepository $employeeRepo,
        DatabaseEmployeeResolver $employeeResolver,
        AiContextProvider $contextProvider,
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

        // 3. Workspace Selection
        $workspaceId = $this->argument('workspace');
        try {
            $workspace = $workspaceId ? Workspace::query()->find($workspaceId) : Workspace::query()->first();
        } catch (Throwable $e) {
            $this->error("   ❌ Database connection failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $workspace) {
            $this->error('   ❌ No workspace found in database!');

            return self::FAILURE;
        }

        // Run all workspace-scoped logic inside WorkspaceContext
        return $workspaceContext->runFor($workspace, function () use (
            $workspace,
            $employeeRepo,
            $employeeResolver,
            $contextProvider,
            $browseDetector,
            $greetingSelector,
            $catalogRetrieval,
            $arabicInterpreter,
        ): int {
            $this->newLine();
            $this->line("<fg=cyan;options=bold>3. Workspace & AI Employee (Workspace ID: {$workspace->id}):</>");
            $this->line("   • Workspace Name: <fg=yellow>{$workspace->name}</>");

            $employee = $employeeRepo->findDefault((int) $workspace->id)
                ?? AiEmployee::queryForWorkspace((int) $workspace->id)->where('is_active', true)->first();

            if ($employee) {
                $this->line("   • Active AI Employee: <fg=green>{$employee->name}</> (ID: {$employee->id}, Role: {$employee->role})");
                $persona = $employee->persona;
                if ($persona) {
                    $this->line("     - Tone: {$persona->tone} | Formality: {$persona->formality} | Dialect: {$persona->dialect}");
                }
                if (! empty($employee->instructions)) {
                    $this->line("     - Custom instructions: <fg=yellow>{$employee->instructions}</>");
                }
            } else {
                $this->error('   ❌ No active AiEmployee record found for this workspace in ai_employees table!');
                $this->warn('   💡 Run migrations/seeders or add an employee in the dashboard.');
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
            $this->line("   • Active & Searchable items: {$searchableItems}");

            if ($searchableItems === 0) {
                $this->error('   ❌ ZERO searchable items found in chat_catalog_items for this workspace!');
                $this->warn('   💡 If the database has no books/products, the AI CANNOT list them.');
                $this->warn('      Add books to chat_catalog_items with is_active=1 and is_searchable=1.');
            } else {
                $samples = (clone $itemsQuery)->where('is_active', true)->where('is_searchable', true)->limit(5)->get();
                $this->line('   • Sample items currently in database:');
                foreach ($samples as $sample) {
                    $price = $sample->price ?? '0.00';
                    $this->line("     - [ID: {$sample->id}] <fg=green>{$sample->name}</> (Price: {$price} {$sample->currency})");
                }
            }

            // 5. Query Simulation for "شو الكتب المتوفرة عندك؟"
            $this->newLine();
            $this->line('<fg=cyan;options=bold>5. Query Simulation: "شو الكتب المتوفرة عندك؟"</>');
            $testQuery = 'شو الكتب المتوفرة عندك؟';
            $tokens = $arabicInterpreter->interpret($testQuery)['search_tokens'];

            $isBrowse = $browseDetector->isBrowseCatalogQuery($testQuery, $tokens);
            $this->line('   • isBrowseCatalogQuery: '.($isBrowse ? '<fg=green>true (Catalog lookup triggered)</>' : '<fg=red>false</>'));

            $shouldGreet = $greetingSelector->shouldIncludeGreeting($testQuery);
            $this->line('   • shouldIncludeGreeting: '.($shouldGreet ? '<fg=red>true (Canned greeting will trigger!)</>' : '<fg=green>false (Direct answer mode)</>'));

            $lookup = $catalogRetrieval->lookupForAssistant($workspace, $testQuery, 8);
            $this->line('   • Catalog Lookup Status: <fg=yellow>'.($lookup['status'] ?? 'unknown').'</>');
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

            // 6. Test Specific Conversation Context Building
            $this->newLine();
            $targetConversationId = $this->option('conversation');
            $conversation = $targetConversationId
                ? Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->find($targetConversationId)
                : Conversation::withoutGlobalScopes()->where('workspace_id', $workspace->id)->latest('id')->first();

            if ($conversation) {
                $this->line("<fg=cyan;options=bold>6. Conversation Context Test (Conversation ID: {$conversation->id}):</>");
                $msgCount = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->count();
                $this->line("   • Total messages in conversation: {$msgCount}");

                $latestMsg = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->latest('id')->first();
                $this->line("   • Latest message: \"".($latestMsg ? mb_substr((string) $latestMsg->content, 0, 80) : 'none').'"');

                try {
                    $aiReq = new AiRequest(
                        workspaceId: (int) $workspace->id,
                        conversationId: (int) $conversation->id,
                        employeeId: null,
                        message: new AiInboundMessage(
                            $latestMsg && trim((string) $latestMsg->content) !== '' ? (string) $latestMsg->content : 'شو الكتب المتوفرة عندك؟',
                            $latestMsg?->id,
                        ),
                        runMode: AiRunMode::Live,
                        channel: (string) ($conversation->channel ?? 'chat'),
                    );
                    $resolvedEmployee = $employeeResolver->resolve($aiReq);
                    $contextPayload = $contextProvider->build($aiReq, $resolvedEmployee);
                    $this->line('   • <fg=green>Context built successfully!</> Sections count: '.count($contextPayload->sections));
                } catch (Throwable $e) {
                    $this->error('   ❌ Context Building Failed with ['.get_class($e)."]: {$e->getMessage()}");
                    $this->error("      at {$e->getFile()}:{$e->getLine()}");
                }
            }

            // 7. Recent AI Logs
            $this->newLine();
            $this->line('<fg=cyan;options=bold>7. Recent AI Log Entries (storage/logs/laravel.log):</>');
            $this->checkRecentLogs();

            // 8. Server Actions
            $this->newLine();
            $this->info('=====================================================');
            $this->info('             REQUIRED SERVER ACTIONS                 ');
            $this->info('=====================================================');
            $this->line('If you pulled new code on the server, run:');
            $this->line('  <fg=yellow>1. php artisan optimize:clear</>');
            $this->line('  <fg=yellow>2. php artisan queue:restart</>');
            $this->line('  <fg=yellow>3. sudo systemctl reload php8.3-fpm</>');
            $this->newLine();

            return self::SUCCESS;
        });
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
