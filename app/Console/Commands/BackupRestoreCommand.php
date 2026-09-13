<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseRestoreService;
use App\Services\Backup\SecretRedactor;
use Illuminate\Console\Command;

class BackupRestoreCommand extends Command
{
    protected $signature = 'backup:restore
        {path : Relative backup path on the private backup disk}
        {--database= : Isolated target database name}
        {--force : Required confirmation}';

    protected $description = 'Restore a backup into an isolated non-production database';

    public function handle(DatabaseRestoreService $restore, SecretRedactor $redactor): int
    {
        $database = trim((string) $this->option('database'));
        if ($database === '') {
            $this->error('Missing --database. Restore never targets the production database.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Refusing to restore without --force.');

            return self::FAILURE;
        }

        try {
            $restore->assertSafeTarget($database);
            $restore->restore((string) $this->argument('path'), $database);
            $this->info('Restore completed.');
            $this->line('database='.$database);

            return self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error($redactor->redact($exception->getMessage()));

            return self::FAILURE;
        } catch (\Throwable) {
            $this->error($redactor->redact('Restore failed.'));

            return self::FAILURE;
        }
    }
}
