<?php

namespace App\Console\Commands;

use App\Services\Observability\OperationalHealthService;
use App\Support\Observability\OpsLogger;
use Illuminate\Console\Command;

class OpsAlertsEvaluateCommand extends Command
{
    protected $signature = 'ops:alerts:evaluate';

    protected $description = 'Evaluate operational alert thresholds (exit 1 when action needed)';

    public function handle(OperationalHealthService $health): int
    {
        $snapshot = $health->snapshot(
            productionStrict: app()->environment('production'),
            requireRedis: app()->environment('production'),
        );

        $violations = [];
        foreach ($snapshot['checks'] as $name => $check) {
            if (! $check['ok']) {
                $violations[] = $name.': '.$check['detail'];
            }
        }

        if ($violations === []) {
            OpsLogger::info('ops.alerts.ok', ['status' => $snapshot['status']]);

            return self::SUCCESS;
        }

        foreach ($violations as $violation) {
            $this->error($violation);
        }

        OpsLogger::warning('ops.alerts.triggered', [
            'status' => $snapshot['status'],
            'violations' => count($violations),
        ]);

        return self::FAILURE;
    }
}
