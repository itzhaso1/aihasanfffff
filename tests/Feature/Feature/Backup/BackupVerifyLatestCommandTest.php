<?php

namespace Tests\Feature\Feature\Backup;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupVerifyLatestCommandTest extends TestCase
{
    public function test_verify_latest_fails_when_no_artifacts(): void
    {
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);

        $code = Artisan::call('backup:verify-latest');

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No database backup', Artisan::output());
    }
}
