<?php

namespace App\Console\Commands;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppPhoneNumber;
use App\Models\Workspace;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\Observability\Percentile;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class OpsLoadTestSyntheticInboundCommand extends Command
{
    protected $signature = 'ops:load-test:synthetic-inbound
        {--workspace= : Workspace ID}
        {--count=50 : Messages to process}
        {--async : Dispatch to inbound queue instead of sync handle}';

    protected $description = 'Safe synthetic inbound load test (no provider HTTP). Staging/local only.';

    public function handle(WhatsAppService $whatsAppService): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run load test in production environment.');

            return self::FAILURE;
        }

        $count = min(
            max(1, (int) $this->option('count')),
            (int) config('observability.load_test.max_messages_per_run', 500),
        );

        $workspace = Workspace::withoutGlobalScopes()->find((int) $this->option('workspace'));
        if (! $workspace) {
            $workspace = Workspace::factory()->create();
            $this->warn('Created ephemeral workspace '.$workspace->id);
        }

        $phone = $this->ensurePhone($workspace);

        $durations = [];

        for ($i = 0; $i < $count; $i++) {
            $messageData = [
                'from' => '9665'.str_pad((string) ($i % 9999), 8, '0', STR_PAD_LEFT),
                'id' => 'wamid.load.'.uniqid('', true),
                'type' => 'text',
                'text' => ['body' => 'load test '.$i],
            ];

            if ($this->option('async')) {
                Bus::dispatch(new ProcessIncomingWhatsAppMessage(
                    (int) $workspace->id,
                    $messageData,
                    0,
                    $phone->phone_number_id,
                ));

                continue;
            }

            $started = microtime(true);
            app(WorkspaceContext::class)->runForId((int) $workspace->id, function () use ($whatsAppService, $workspace, $messageData, $phone): void {
                $whatsAppService->storeIncomingMessage((int) $workspace->id, $messageData, $phone->phone_number_id);
            });
            $durations[] = (int) round((microtime(true) - $started) * 1000);
        }

        if ($this->option('async')) {
            $this->info("Dispatched {$count} inbound jobs to queue.");

            return self::SUCCESS;
        }

        sort($durations);
        $this->table(['metric', 'ms'], [
            ['p50', Percentile::calculate($durations, 50)],
            ['p95', Percentile::calculate($durations, 95)],
            ['p99', Percentile::calculate($durations, 99)],
            ['count', count($durations)],
        ]);

        $this->warn('These numbers measure local persist path only, not production capacity.');

        return self::SUCCESS;
    }

    private function ensurePhone(Workspace $workspace): WhatsAppPhoneNumber
    {
        $existing = WhatsAppPhoneNumber::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        $account = WhatsAppAccount::query()->create([
            'workspace_id' => $workspace->id,
            'business_account_id' => 'load_test_waba',
            'display_name' => 'Load Test',
            'status' => 'connected',
            'metadata' => [],
        ]);

        return WhatsAppPhoneNumber::query()->create([
            'workspace_id' => $workspace->id,
            'whats_app_account_id' => $account->id,
            'phone_number_id' => 'pn_load_test',
            'display_phone_number' => '+966500000000',
            'verified_name' => 'Load',
            'status' => 'connected',
        ]);
    }
}
