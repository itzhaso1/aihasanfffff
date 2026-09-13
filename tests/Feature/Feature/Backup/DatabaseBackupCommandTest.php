<?php

namespace Tests\Feature\Feature\Backup;

use App\Services\Backup\DatabaseBackupService;
use App\Services\Backup\SecretRedactor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupCommandTest extends TestCase
{
    public function test_scheduler_registers_database_backup(): void
    {
        $events = collect(app(Schedule::class)->events());

        $this->assertTrue(
            $events->contains(
                fn ($event): bool => str_contains((string) ($event->command ?? $event->description ?? ''), 'backup:database')
            )
        );
    }

    public function test_sqlite_backup_fails_with_nonzero_exit(): void
    {
        config(['backup.connection' => 'sqlite']);

        $code = Artisan::call('backup:database', ['--no-retain' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString((string) config('app.key'), $output);
        $this->assertStringNotContainsString('base64:', $output);
    }

    public function test_missing_dump_binary_fails(): void
    {
        config([
            'backup.connection' => 'backup_source',
            'backup.dump_binary' => storage_path('app/private/backups/missing-mysqldump'),
        ]);

        $code = Artisan::call('backup:database', ['--no-retain' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not found', Artisan::output());
    }

    public function test_unwritable_destination_fails(): void
    {
        $blocker = tempnam(sys_get_temp_dir(), 'hasem-backup-block-');
        $this->assertNotFalse($blocker);
        config([
            'backup.connection' => 'backup_source',
            'filesystems.disks.backups.root' => $blocker,
        ]);
        $this->app['filesystem']->forgetDisk('backups');

        $code = Artisan::call('backup:database', ['--no-retain' => true]);
        @unlink($blocker);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not writable', Artisan::output());
    }

    public function test_empty_artifact_fails_verification(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('database/hasem-db-20000101-000000.sql.gz', '');

        $code = Artisan::call('backup:verify', ['path' => 'database/hasem-db-20000101-000000.sql.gz']);

        $this->assertSame(1, $code);
    }

    public function test_corrupt_gzip_fails_verification(): void
    {
        Storage::fake('backups');
        Storage::disk('backups')->put('database/hasem-db-20000101-000001.sql.gz', 'not-gzip');

        $code = Artisan::call('backup:verify', ['path' => 'database/hasem-db-20000101-000001.sql.gz']);

        $this->assertSame(1, $code);
    }

    public function test_retention_deletes_only_old_matching_backups_and_keeps_one(): void
    {
        Storage::fake('backups');
        $disk = Storage::disk('backups');
        $disk->put('database/readme.txt', 'keep me');
        $disk->put('database/hasem-db-20200101-010101.sql.gz', 'old');
        $disk->put('database/hasem-db-20200101-010101.sql.gz.sha256', 'x');
        $disk->put('database/hasem-db-20300101-010101.sql.gz', 'new');

        $service = app(DatabaseBackupService::class);
        $root = $disk->path('database');
        touch($root.'/hasem-db-20200101-010101.sql.gz', now()->subDays(30)->getTimestamp());
        touch($root.'/hasem-db-20300101-010101.sql.gz', now()->getTimestamp());

        $deleted = $service->retain(14);

        $this->assertSame(1, $deleted);
        $this->assertFalse($disk->exists('database/hasem-db-20200101-010101.sql.gz'));
        $this->assertTrue($disk->exists('database/hasem-db-20300101-010101.sql.gz'));
        $this->assertTrue($disk->exists('database/readme.txt'));
    }

    public function test_retention_does_not_delete_the_only_backup(): void
    {
        Storage::fake('backups');
        $disk = Storage::disk('backups');
        $disk->put('database/hasem-db-20200101-010101.sql.gz', 'only');
        touch($disk->path('database/hasem-db-20200101-010101.sql.gz'), now()->subDays(40)->getTimestamp());

        $this->assertSame(0, app(DatabaseBackupService::class)->retain(14));
        $this->assertTrue($disk->exists('database/hasem-db-20200101-010101.sql.gz'));
    }

    public function test_restore_refuses_production_database(): void
    {
        $code = Artisan::call('backup:restore', [
            'path' => 'database/missing.sql.gz',
            '--database' => 'hasem',
            '--force' => true,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Refusing', Artisan::output());
    }

    public function test_restore_without_force_fails(): void
    {
        $code = Artisan::call('backup:restore', [
            'path' => 'database/missing.sql.gz',
            '--database' => 'hasem_p0_restore_drill',
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('--force', Artisan::output());
    }

    public function test_redactor_strips_password_and_app_key(): void
    {
        $redactor = new SecretRedactor;
        $text = $redactor->redact(
            'mysql://root:super-secret@127.0.0.1/hasem APP_KEY='.config('app.key'),
            $redactor->secretsFromConnection(['username' => 'root', 'password' => 'super-secret', 'host' => '127.0.0.1', 'database' => 'hasem'])
        );

        $this->assertStringNotContainsString('super-secret', $text);
        $this->assertStringNotContainsString((string) config('app.key'), $text);
        $this->assertStringContainsString('[redacted]', $text);
    }

    public function test_invalid_credentials_fail_without_leaking_password(): void
    {
        config([
            'backup.connection' => 'backup_source',
            'database.connections.backup_source.password' => 'wrong-password-p03',
            'backup.dump_binary' => is_file('C:\\xampp\\mysql\\bin\\mysqldump.exe')
                ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe'
                : null,
        ]);

        $code = Artisan::call('backup:database', ['--no-retain' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('wrong-password-p03', $output);
    }
}
