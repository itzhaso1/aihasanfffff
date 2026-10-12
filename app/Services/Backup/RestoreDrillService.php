<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RestoreDrillService
{
    public function __construct(
        private readonly DatabaseBackupService $backups,
        private readonly DatabaseRestoreService $restore,
    ) {}

    /**
     * @return array{
     *     artifact: BackupArtifact,
     *     source_database: string,
     *     restore_database: string,
     *     source_counts: array<string, int>,
     *     restore_counts: array<string, int>,
     *     duration_ms: int
     * }
     */
    public function run(bool $dropRestoreAfter = true): array
    {
        $started = hrtime(true);
        $sourceName = (string) config('backup.source_database');
        $restoreName = (string) config('backup.restore_database');
        $this->restore->assertSafeTarget($sourceName);
        $this->restore->assertSafeTarget($restoreName);

        $admin = $this->restore->adminConfig('backup_source');
        $this->restore->createDatabase($admin, $sourceName);
        $this->restore->dropDatabase('backup_source', $restoreName);

        $migrate = Artisan::call('migrate', [
            '--database' => 'backup_source',
            '--force' => true,
        ]);
        if ($migrate !== 0) {
            throw BackupException::failed('Restore drill could not migrate the isolated source database.');
        }

        try {
            $this->seedMarkers();
        } catch (\Throwable $exception) {
            throw BackupException::failed('Restore drill could not seed isolated marker rows.');
        }
        $sourceCounts = $this->restore->integrityCounts('backup_source');

        $previous = config('backup.connection');
        config(['backup.connection' => 'backup_source']);
        try {
            $artifact = $this->backups->create('backup_source');
        } finally {
            config(['backup.connection' => $previous]);
        }

        $this->restore->restore($artifact->relativePath, $restoreName, 'backup_source');
        $restoreCounts = $this->restore->integrityCounts('backup_restore');
        $this->assertRestored($sourceCounts, $restoreCounts);

        if ($dropRestoreAfter) {
            $this->restore->dropDatabase('backup_source', $restoreName);
        }

        return [
            'artifact' => $artifact,
            'source_database' => $sourceName,
            'restore_database' => $restoreName,
            'source_counts' => $sourceCounts,
            'restore_counts' => $restoreCounts,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ];
    }

    /**
     * @param  array<string, int>  $source
     * @param  array<string, int>  $restored
     */
    private function assertRestored(array $source, array $restored): void
    {
        foreach ($source as $table => $count) {
            if ($count < 0) {
                throw BackupException::failed('Required table missing before backup: '.$table);
            }
            if (($restored[$table] ?? -1) < 0) {
                throw BackupException::failed('Required table missing after restore: '.$table);
            }
            if ((int) $restored[$table] !== (int) $count) {
                throw BackupException::failed('Restore row count mismatch for '.$table.'.');
            }
        }

        if (($restored['migrations'] ?? 0) < 1) {
            throw BackupException::failed('Restored database has no migrations rows.');
        }
    }

    private function seedMarkers(): void
    {
        $db = DB::connection('backup_source');
        $now = now();

        $userId = $db->table('users')->insertGetId([
            'name' => 'P0-3 Drill User',
            'email' => 'p03-drill-'.Str::lower(Str::random(8)).'@example.test',
            'password' => Hash::make(Str::random(32)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $workspaceColumns = $db->getSchemaBuilder()->getColumnListing('workspaces');
        $workspaceRow = array_intersect_key([
            'uuid' => (string) Str::uuid(),
            'name' => 'P0-3 Drill Workspace',
            'slug' => 'p03-drill-'.Str::lower(Str::random(8)),
            'type' => 'store',
            'owner_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ], array_flip($workspaceColumns));
        $workspaceId = $db->table('workspaces')->insertGetId($workspaceRow);

        $db->table('workspace_users')->insert([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'membership_role' => 'owner',
            'status' => 'active',
            'is_primary' => true,
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertIfPossible($db, 'products', [
            'workspace_id' => $workspaceId,
            'name' => 'Drill Product',
            'slug' => 'drill-product-'.Str::lower(Str::random(6)),
            'sku' => 'DRILL-'.Str::upper(Str::random(6)),
            'price' => 10,
            'currency' => 'SAR',
            'stock' => 5,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertIfPossible($db, 'customers', [
            'workspace_id' => $workspaceId,
            'name' => 'Drill Customer',
            'email' => 'customer-'.Str::lower(Str::random(6)).'@example.test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertIfPossible($db, string $table, array $row): void
    {
        if (! $db->getSchemaBuilder()->hasTable($table)) {
            return;
        }

        try {
            $columns = $db->getSchemaBuilder()->getColumnListing($table);
            $filtered = array_intersect_key($row, array_flip($columns));
            $db->table($table)->insert($filtered);
        } catch (\Throwable) {
            // Marker insert is best-effort; schema presence is still verified.
        }
    }
}
