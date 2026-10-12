<?php

namespace App\Jobs\Runtime;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RuntimeFailingJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        $flag = storage_path('framework/runtime-fail-'.$this->token);
        if (! is_file($flag)) {
            file_put_contents($flag, '1');
            throw new RuntimeException('Runtime failing probe (test-only).');
        }

        @unlink($flag);
        Cache::put($this->cacheKey(), 'recovered', 300);
    }

    public function cacheKey(): string
    {
        return 'runtime:failing:'.$this->token;
    }
}
