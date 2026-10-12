<?php

namespace Tests\Feature\Feature\Backup;

use App\Services\Backup\RestoreDrillService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class DatabaseRestoreDrillTest extends TestCase
{
    public function test_isolated_restore_drill_recreates_schema_and_marker_rows(): void
    {
        if (! $this->mysqlReachable()) {
            $this->markTestSkipped('Isolated MariaDB/MySQL is not reachable.');
        }

        $result = app(RestoreDrillService::class)->run(dropRestoreAfter: false);

        $this->assertGreaterThan(0, $result['artifact']->bytes);
        $this->assertNotSame('', $result['artifact']->sha256);
        $this->assertGreaterThan(0, $result['restore_counts']['migrations']);
        $this->assertSame($result['source_counts']['workspaces'], $result['restore_counts']['workspaces']);
        $this->assertGreaterThan(0, $result['restore_counts']['users']);
        $this->assertGreaterThan(0, $result['restore_counts']['workspace_users']);
        foreach ([
            'products',
            'customers',
            'orders',
            'finance_invoices',
            'finance_journal_entries',
            'payments',
            'pos_sync_operations',
        ] as $table) {
            $this->assertGreaterThanOrEqual(0, $result['restore_counts'][$table], $table);
        }

        $verify = Artisan::call('backup:verify', ['path' => $result['artifact']->relativePath]);
        $this->assertSame(0, $verify);
        $output = Artisan::output();
        $this->assertStringNotContainsString((string) config('app.key'), $output);
        $this->assertStringNotContainsString('password=', $output);

        app(\App\Services\Backup\DatabaseRestoreService::class)
            ->dropDatabase('backup_source', $result['restore_database']);
    }

    private function mysqlReachable(): bool
    {
        $config = config('database.connections.backup_source');
        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%s', $config['host'] ?? '127.0.0.1', $config['port'] ?? 3306),
                (string) ($config['username'] ?? ''),
                (string) ($config['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]
            );
            $pdo->query('SELECT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
