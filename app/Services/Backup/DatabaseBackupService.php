<?php

namespace App\Services\Backup;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    public function __construct(
        private readonly SecretRedactor $redactor,
        private readonly MysqlClientLocator $locator,
    ) {}

    public function disk(): Filesystem
    {
        try {
            return Storage::disk((string) config('backup.disk', 'backups'));
        } catch (\Throwable) {
            throw BackupException::failed('Backup destination is not writable.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionConfig(?string $connection = null): array
    {
        $name = $connection ?: (string) config('backup.connection', 'mysql');
        $config = config('database.connections.'.$name);
        if (! is_array($config)) {
            throw BackupException::failed('Unknown database connection.');
        }

        $driver = (string) ($config['driver'] ?? '');
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            throw BackupException::failed('Backup supports MySQL/MariaDB only.');
        }

        if (($config['database'] ?? '') === '' || ($config['database'] ?? '') === ':memory:') {
            throw BackupException::failed('Backup source database is not a MySQL/MariaDB name.');
        }

        return $config + ['_name' => $name];
    }

    public function create(?string $connection = null): BackupArtifact
    {
        $config = $this->connectionConfig($connection);
        $this->assertWritable();

        $dumpBinary = $this->locator->find(
            ['mysqldump', 'mariadb-dump'],
            config('backup.dump_binary') ? (string) config('backup.dump_binary') : null,
        );

        $stamp = now()->format('Ymd-His');
        $relative = trim((string) config('backup.path', 'database'), '/').'/'
            .((string) config('backup.prefix', 'hasem-db')).'-'.$stamp.'.sql.gz';

        $directory = dirname($this->absolutePath($relative));
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw BackupException::failed('Backup destination is not writable.');
        }

        $sqlPath = $this->absolutePath($relative.'.tmp.sql');
        $defaults = $this->writeDefaultsFile($config);

        try {
            $process = new Process([
                $dumpBinary,
                '--defaults-extra-file='.$defaults,
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--hex-blob',
                '--default-character-set=utf8mb4',
                '--result-file='.$sqlPath,
                (string) $config['database'],
            ]);
            $process->setTimeout((int) config('backup.timeout', 600));
            $process->run();

            if (! $process->isSuccessful()) {
                throw BackupException::failed(
                    $this->redactor->redact(
                        'Database dump failed.'.($process->getErrorOutput() !== '' ? ' '.$process->getErrorOutput() : ''),
                        $this->redactor->secretsFromConnection($config)
                    )
                );
            }

            if (! is_file($sqlPath) || filesize($sqlPath) <= 0) {
                throw BackupException::failed('Dump produced an empty or missing file.');
            }

            $sql = file_get_contents($sqlPath);
            if ($sql === false || trim($sql) === '') {
                throw BackupException::failed('Dump produced an empty or missing file.');
            }

            $gzip = gzencode($sql, 9);
            if ($gzip === false || $gzip === '') {
                throw BackupException::failed('Failed to compress dump.');
            }

            $absolute = $this->absolutePath($relative);
            if (file_put_contents($absolute, $gzip) === false) {
                throw BackupException::failed('Failed to write compressed backup.');
            }

            $this->verifyArtifact($absolute);

            $sha = hash_file('sha256', $absolute);
            if (! is_string($sha) || $sha === '') {
                throw BackupException::failed('Failed to hash backup.');
            }

            $checksumRelative = $relative.'.sha256';
            file_put_contents($this->absolutePath($checksumRelative), $sha.'  '.basename($relative).PHP_EOL);

            return new BackupArtifact(
                relativePath: $relative,
                absolutePath: $absolute,
                bytes: (int) filesize($absolute),
                sha256: $sha,
                checksumRelativePath: $checksumRelative,
            );
        } finally {
            if (is_file($sqlPath)) {
                @unlink($sqlPath);
            }
            if (is_file($defaults)) {
                @unlink($defaults);
            }
        }
    }

    public function verifyArtifact(string $absolutePath): void
    {
        if (! is_file($absolutePath)) {
            throw BackupException::failed('Backup artifact is missing.');
        }

        $size = filesize($absolutePath);
        if ($size === false || $size <= 0) {
            throw BackupException::failed('Backup artifact is empty.');
        }

        $raw = file_get_contents($absolutePath);
        if ($raw === false || strlen($raw) < 3 || substr($raw, 0, 2) !== "\x1f\x8b") {
            throw BackupException::failed('Backup gzip archive is corrupt or empty.');
        }

        $decoded = @gzdecode($raw);
        if ($decoded === false || $decoded === '') {
            throw BackupException::failed('Backup gzip archive is corrupt or empty.');
        }
    }

    public function verifyRelative(string $relativePath): BackupArtifact
    {
        $absolute = $this->absolutePath($relativePath);
        $this->verifyArtifact($absolute);

        $expected = null;
        $checksumPath = $absolute.'.sha256';
        if (is_file($checksumPath)) {
            $expected = strtolower(trim(strtok((string) file_get_contents($checksumPath), ' ') ?: ''));
        }

        $sha = hash_file('sha256', $absolute);
        if (! is_string($sha)) {
            throw BackupException::failed('Failed to hash backup.');
        }
        if ($expected && ! hash_equals($expected, strtolower($sha))) {
            throw BackupException::failed('Backup checksum mismatch.');
        }

        return new BackupArtifact(
            relativePath: $relativePath,
            absolutePath: $absolute,
            bytes: (int) filesize($absolute),
            sha256: $sha,
            checksumRelativePath: $relativePath.'.sha256',
        );
    }

    public function retain(?int $retainDays = null): int
    {
        $days = $retainDays ?? (int) config('backup.retain_days', 14);
        $prefix = (string) config('backup.prefix', 'hasem-db');
        $path = trim((string) config('backup.path', 'database'), '/');
        $disk = $this->disk();
        $files = collect($disk->files($path))
            ->filter(fn (string $file): bool => (bool) preg_match('/'.preg_quote($prefix, '/').'-\d{8}-\d{6}\.sql\.gz$/', basename($file)))
            ->sortByDesc(fn (string $file): int => (int) $disk->lastModified($file))
            ->values();

        if ($files->count() <= 1) {
            return 0;
        }

        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $deleted = 0;
        $kept = $files->count();

        foreach ($files->reverse() as $file) {
            if ($kept <= 1) {
                break;
            }
            if ((int) $disk->lastModified($file) >= $cutoff) {
                continue;
            }
            $disk->delete($file);
            $disk->delete($file.'.sha256');
            $deleted++;
            $kept--;
        }

        return $deleted;
    }

    public function absolutePath(string $relative): string
    {
        return $this->disk()->path($relative);
    }

    public function assertWritable(): void
    {
        $diskName = (string) config('backup.disk', 'backups');
        $configuredRoot = rtrim((string) config('filesystems.disks.'.$diskName.'.root', ''), '/\\');

        if ($configuredRoot === '' || is_file($configuredRoot)) {
            throw BackupException::failed('Backup destination is not writable.');
        }

        if (! is_dir($configuredRoot) && ! @mkdir($configuredRoot, 0700, true) && ! is_dir($configuredRoot)) {
            throw BackupException::failed('Backup destination is not writable.');
        }

        if (! is_writable($configuredRoot)) {
            throw BackupException::failed('Backup destination is not writable.');
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function writeDefaultsFile(array $config): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hasem-mycnf-');
        if ($path === false) {
            throw BackupException::failed('Unable to create a temporary client config.');
        }

        $lines = [
            '[client]',
            'host='.$this->iniValue((string) ($config['host'] ?? '127.0.0.1')),
            'port='.(int) ($config['port'] ?? 3306),
            'user='.$this->iniValue((string) ($config['username'] ?? '')),
        ];
        $password = (string) ($config['password'] ?? '');
        if ($password !== '') {
            $lines[] = 'password='.$this->iniValue($password);
        }
        file_put_contents($path, implode(PHP_EOL, $lines).PHP_EOL);
        @chmod($path, 0600);

        return $path;
    }

    private function iniValue(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
