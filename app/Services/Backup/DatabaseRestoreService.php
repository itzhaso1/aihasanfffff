<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\Process;

class DatabaseRestoreService
{
    public function __construct(
        private readonly DatabaseBackupService $backups,
        private readonly SecretRedactor $redactor,
        private readonly MysqlClientLocator $locator,
    ) {}

    public function assertSafeTarget(string $database): void
    {
        $database = trim($database);
        if ($database === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw BackupException::failed('Restore target database name is invalid.');
        }

        $forbidden = array_map('strtolower', (array) config('backup.forbidden_restore_databases', []));
        if (in_array(strtolower($database), $forbidden, true)) {
            throw BackupException::failed('Refusing to restore over a production or system database.');
        }
    }

    public function restore(string $relativeOrAbsolute, string $targetDatabase, string $adminConnection = 'backup_source'): void
    {
        $this->assertSafeTarget($targetDatabase);

        $absolute = is_file($relativeOrAbsolute)
            ? $relativeOrAbsolute
            : $this->backups->absolutePath($relativeOrAbsolute);
        $this->backups->verifyArtifact($absolute);

        $sql = $this->decompress($absolute);
        if (! str_contains($sql, 'CREATE TABLE') && ! str_contains($sql, 'INSERT INTO')) {
            throw BackupException::failed('Decompressed dump does not look like a usable SQL backup.');
        }

        $admin = $this->adminConfig($adminConnection);
        $this->createDatabase($admin, $targetDatabase);

        $mysql = $this->locator->find(
            ['mysql', 'mariadb'],
            config('backup.mysql_binary') ? (string) config('backup.mysql_binary') : null,
        );

        $defaults = $this->backups->writeDefaultsFile($admin);
        $sqlFile = tempnam(sys_get_temp_dir(), 'hasem-restore-');
        if ($sqlFile === false) {
            @unlink($defaults);
            throw BackupException::failed('Unable to create a temporary restore file.');
        }

        try {
            file_put_contents($sqlFile, $sql);
            $process = new Process([
                $mysql,
                '--defaults-extra-file='.$defaults,
                '--default-character-set=utf8mb4',
                $targetDatabase,
            ]);
            $process->setTimeout((int) config('backup.timeout', 600));
            $process->setInput($sql);
            $process->run();

            if (! $process->isSuccessful()) {
                throw BackupException::failed(
                    $this->redactor->redact(
                        'Restore failed.'.($process->getErrorOutput() !== '' ? ' '.$process->getErrorOutput() : ''),
                        $this->redactor->secretsFromConnection($admin)
                    )
                );
            }
        } finally {
            @unlink($defaults);
            @unlink($sqlFile);
        }
    }

    /**
     * @return array<string, int>
     */
    public function integrityCounts(string $connection): array
    {
        $tables = [
            'migrations',
            'workspaces',
            'users',
            'workspace_users',
            'products',
            'customers',
            'orders',
            'finance_invoices',
            'finance_journal_entries',
            'payments',
            'pos_sync_operations',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = $this->tableExists($connection, $table)
                ? (int) DB::connection($connection)->table($table)->count()
                : -1;
        }

        return $counts;
    }

    public function tableExists(string $connection, string $table): bool
    {
        return DB::connection($connection)->getSchemaBuilder()->hasTable($table);
    }

    public function dropDatabase(string $adminConnection, string $database): void
    {
        $this->assertSafeTarget($database);
        $admin = $this->adminConfig($adminConnection);
        $pdo = $this->serverPdo($admin);
        $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');
    }

    public function createDatabase(array $admin, string $database): void
    {
        $this->assertSafeTarget($database);
        $pdo = $this->serverPdo($admin);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    }

    /**
     * @return array<string, mixed>
     */
    public function adminConfig(string $connection): array
    {
        $config = config('database.connections.'.$connection);
        if (! is_array($config)) {
            throw BackupException::failed('Unknown restore admin connection.');
        }

        return $config;
    }

    private function decompress(string $absolute): string
    {
        $raw = file_get_contents($absolute);
        if ($raw === false || $raw === '') {
            throw BackupException::failed('Backup artifact is empty.');
        }

        $sql = gzdecode($raw);
        if ($sql === false || trim($sql) === '') {
            throw BackupException::failed('Backup gzip archive is corrupt or empty.');
        }

        return $sql;
    }

    /**
     * @param  array<string, mixed>  $admin
     */
    private function serverPdo(array $admin): PDO
    {
        $dsn = sprintf('mysql:host=%s;port=%s', $admin['host'] ?? '127.0.0.1', $admin['port'] ?? 3306);
        try {
            return new PDO(
                $dsn,
                (string) ($admin['username'] ?? ''),
                (string) ($admin['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (\PDOException $exception) {
            throw BackupException::failed(
                $this->redactor->redact(
                    'Invalid database credentials.',
                    $this->redactor->secretsFromConnection($admin)
                )
            );
        }
    }
}
