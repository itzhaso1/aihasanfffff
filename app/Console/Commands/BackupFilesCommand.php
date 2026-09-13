<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BackupFilesCommand extends Command
{
    protected $signature = 'backup:files';

    protected $description = 'Archive storage/app/private (except backups/) onto the private backup disk';

    public function handle(): int
    {
        $root = storage_path('app/private');
        if (! is_dir($root)) {
            $this->error('Private storage directory is missing.');

            return self::FAILURE;
        }

        $relative = trim((string) config('backup.files_path', 'files'), '/').'/'
            .((string) config('backup.files_prefix', 'hasem-files')).'-'.now()->format('Ymd-His').'.zip';
        $disk = Storage::disk((string) config('backup.disk', 'backups'));
        $absolute = $disk->path($relative);
        $dir = dirname($absolute);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error('Backup destination is not writable.');

            return self::FAILURE;
        }

        $zip = new ZipArchive;
        if ($zip->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->error('Unable to create files archive.');

            return self::FAILURE;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $full = $file->getPathname();
            $rel = str_replace('\\', '/', substr($full, strlen($root) + 1));
            if (str_starts_with($rel, 'backups/')) {
                continue;
            }
            $zip->addFile($full, $rel);
        }
        $zip->close();

        if (! is_file($absolute) || filesize($absolute) <= 0) {
            $this->error('Files backup artifact is empty.');

            return self::FAILURE;
        }

        $sha = hash_file('sha256', $absolute);
        file_put_contents($absolute.'.sha256', $sha.'  '.basename($relative).PHP_EOL);
        $this->info('Files backup created.');
        $this->line('path='.$relative);
        $this->line('bytes='.filesize($absolute));
        $this->line('sha256='.$sha);

        return self::SUCCESS;
    }
}
