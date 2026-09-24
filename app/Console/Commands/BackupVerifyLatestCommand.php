<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupException;
use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\SecretRedactor;
use Illuminate\Console\Command;

class BackupVerifyLatestCommand extends Command
{
    protected $signature = 'backup:verify-latest';

    protected $description = 'Verify the newest database backup artifact on the backup disk';

    public function handle(DatabaseBackupService $backups, SecretRedactor $redactor): int
    {
        try {
            $relative = $this->newestDatabaseBackupPath($backups);
            if ($relative === null) {
                $this->error('No database backup artifacts found.');

                return self::FAILURE;
            }

            $artifact = $backups->verifyRelative($relative);
            $this->info('Latest backup verified.');
            $this->line('path='.$artifact->relativePath);
            $this->line('bytes='.$artifact->bytes);
            $this->line('sha256='.$artifact->sha256);

            return self::SUCCESS;
        } catch (BackupException $exception) {
            $this->error($redactor->redact($exception->getMessage()));

            return self::FAILURE;
        }
    }

    private function newestDatabaseBackupPath(DatabaseBackupService $backups): ?string
    {
        $disk = $backups->disk();
        $directory = trim((string) config('backup.path', 'database'), '/');
        $prefix = (string) config('backup.prefix', 'hasem-db');

        $files = collect($disk->files($directory))
            ->filter(fn (string $path): bool => str_starts_with(basename($path), $prefix) && str_ends_with($path, '.sql.gz'))
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            return null;
        }

        return $files->last();
    }
}
