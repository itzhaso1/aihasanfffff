<?php

namespace App\Console\Commands;

use App\Services\Observability\OperationalHealthService;
use Illuminate\Console\Command;

class OpsHealthCommand extends Command
{
    protected $signature = 'ops:health {--strict : Production-strict runtime checks} {--json : JSON output}';

    protected $description = 'Operational health snapshot (database, redis, queues, disk)';

    public function handle(OperationalHealthService $health): int
    {
        $strict = (bool) $this->option('strict') || app()->environment('production');
        $snapshot = $health->snapshot(productionStrict: $strict, requireRedis: $strict);

        if ($this->option('json')) {
            $this->line(json_encode($snapshot, JSON_UNESCAPED_UNICODE));

            return $snapshot['status'] === 'unhealthy' ? self::FAILURE : self::SUCCESS;
        }

        $this->info('Status: '.$snapshot['status']);
        foreach ($snapshot['checks'] as $name => $check) {
            $line = $name.' '.($check['ok'] ? 'PASS' : 'FAIL').' '.$check['detail'];
            $check['ok'] ? $this->line($line) : $this->error($line);
        }

        return $snapshot['status'] === 'unhealthy' ? self::FAILURE : self::SUCCESS;
    }
}
