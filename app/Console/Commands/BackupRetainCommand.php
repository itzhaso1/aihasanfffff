<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\SecretRedactor;
use Illuminate\Console\Command;

class BackupRetainCommand extends Command
{
    protected $signature = 'backup:retain {--days= : Hot retention in days}';

    protected $description = 'Delete expired database backups; never deletes the last remaining backup';

    public function handle(DatabaseBackupService $backups, SecretRedactor $redactor): int
    {
        try {
            $days = $this->option('days');
            $deleted = $backups->retain($days !== null ? (int) $days : null);
            $this->info('Retention complete.');
            $this->line('deleted='.$deleted);

            return self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error($redactor->redact($exception->getMessage()));

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error($redactor->redact('Retention failed.'));

            return self::FAILURE;
        }
    }
}
