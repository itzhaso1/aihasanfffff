<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\SecretRedactor;
use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database
        {--connection= : Database connection name}
        {--no-retain : Skip retention after a successful backup}';

    protected $description = 'Create a private compressed MySQL/MariaDB backup';

    public function handle(DatabaseBackupService $backups, SecretRedactor $redactor): int
    {
        try {
            $artifact = $backups->create($this->option('connection') ?: null);
            $this->info('Backup created.');
            $this->line('path='.$artifact->relativePath);
            $this->line('bytes='.$artifact->bytes);
            $this->line('sha256='.$artifact->sha256);

            if (! $this->option('no-retain')) {
                $deleted = $backups->retain();
                $this->line('retention_deleted='.$deleted);
            }

            return self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error($redactor->redact($exception->getMessage()));

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error($redactor->redact('Backup failed.'));

            return self::FAILURE;
        }
    }
}
