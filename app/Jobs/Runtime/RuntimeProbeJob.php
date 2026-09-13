<?php

namespace App\Jobs\Runtime;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RuntimeProbeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public readonly string $token) {}

    public function handle(): void
    {
        Cache::put($this->cacheKey(), 'processed', 300);
    }

    public function cacheKey(): string
    {
        return 'runtime:probe:'.$this->token;
    }
}
