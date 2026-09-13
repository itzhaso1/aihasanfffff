<?php

namespace App\Console\Commands;

use App\Services\Backup\SecretRedactor;
use App\Services\Runtime\RuntimeHealthService;
use Illuminate\Console\Command;

class RuntimeCheckCommand extends Command
{
    protected $signature = 'runtime:check
        {--production-strict : Require Redis queue/cache/session}
        {--require-redis : Ping Redis (no business writes)}';

    protected $description = 'Verify production runtime config, scheduler, failed_jobs, and optional Redis';

    public function handle(RuntimeHealthService $health, SecretRedactor $redactor): int
    {
        $strict = (bool) $this->option('production-strict') || app()->environment('production');
        $requireRedis = (bool) $this->option('require-redis') || $strict;

        $result = $health->inspect(productionStrict: $strict, requireRedis: $requireRedis);

        foreach ($result['checks'] as $name => $check) {
            $line = $name.' '.($check['ok'] ? 'PASS' : 'FAIL').' '.$check['detail'];
            $line = $redactor->redact($line, $this->secrets());
            $check['ok'] ? $this->info($line) : $this->error($line);
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        return array_values(array_filter([
            (string) config('database.redis.default.password'),
            (string) config('database.connections.mysql.password'),
            (string) config('app.key'),
        ], fn (string $value): bool => $value !== '' && $value !== 'null'));
    }
}
