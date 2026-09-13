<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RuntimeWorkCommand extends Command
{
    protected $signature = 'runtime:work
        {--once : Process a single job then exit}
        {--stop-when-empty : Exit when the queue is empty}';

    protected $description = 'Run a Redis queue worker with the documented timeout/retry budget';

    public function handle(): int
    {
        $options = [
            'connection' => (string) config('runtime.production.queue_connection', 'redis'),
            '--queue' => (string) config('runtime.queue.name', 'default'),
            '--sleep' => 1,
            '--tries' => (int) config('runtime.queue.tries', 3),
            '--backoff' => (int) config('runtime.queue.backoff', 5),
            '--timeout' => (int) config('runtime.queue.worker_timeout', 200),
        ];

        if ($this->option('once')) {
            $options['--once'] = true;
        }
        if ($this->option('stop-when-empty')) {
            $options['--stop-when-empty'] = true;
        }

        return Artisan::call('queue:work', $options, $this->output);
    }
}
