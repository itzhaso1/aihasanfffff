<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\RestoreDrillService;
use App\Services\Backup\SecretRedactor;
use Illuminate\Console\Command;

class BackupDrillCommand extends Command
{
    protected $signature = 'backup:drill {--keep-restore : Leave the isolated restore database}';

    protected $description = 'Isolated MySQL/MariaDB backup + restore drill (never production)';

    public function handle(RestoreDrillService $drill, SecretRedactor $redactor): int
    {
        try {
            $result = $drill->run(dropRestoreAfter: ! $this->option('keep-restore'));
            $this->info('Restore drill passed.');
            $this->line('source_database='.$result['source_database']);
            $this->line('restore_database='.$result['restore_database']);
            $this->line('path='.$result['artifact']->relativePath);
            $this->line('bytes='.$result['artifact']->bytes);
            $this->line('sha256='.$result['artifact']->sha256);
            $this->line('duration_ms='.$result['duration_ms']);
            foreach ($result['restore_counts'] as $table => $count) {
                $this->line('table.'.$table.'='.$count);
            }

            return self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error($redactor->redact($exception->getMessage()));

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($redactor->redact('Restore drill failed.'));

            return self::FAILURE;
        }
    }
}
