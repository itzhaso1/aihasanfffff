<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\SecretRedactor;
use Illuminate\Console\Command;

class BackupVerifyCommand extends Command
{
    protected $signature = 'backup:verify {path : Relative path on the backup disk}';

    protected $description = 'Verify gzip integrity and checksum of a database backup';

    public function handle(DatabaseBackupService $backups, SecretRedactor $redactor): int
    {
        try {
            $artifact = $backups->verifyRelative((string) $this->argument('path'));
            $this->info('Backup verified.');
            $this->line('path='.$artifact->relativePath);
            $this->line('bytes='.$artifact->bytes);
            $this->line('sha256='.$artifact->sha256);

            return self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error($redactor->redact($exception->getMessage()));

            return self::FAILURE;
        }
    }
}
