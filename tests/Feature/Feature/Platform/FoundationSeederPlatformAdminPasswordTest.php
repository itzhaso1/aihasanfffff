<?php

namespace Tests\Feature\Feature\Platform;

use App\Exceptions\PlatformAdminSeedPasswordException;
use App\Models\PlatformAdmin;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FoundationSeederPlatformAdminPasswordTest extends TestCase
{
    use RefreshDatabase;

    private bool $hadPlatformAdminPassword = false;

    private ?string $previousPlatformAdminPassword = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hadPlatformAdminPassword = array_key_exists('PLATFORM_ADMIN_PASSWORD', $_ENV)
            || array_key_exists('PLATFORM_ADMIN_PASSWORD', $_SERVER)
            || getenv('PLATFORM_ADMIN_PASSWORD') !== false;

        $previous = $_ENV['PLATFORM_ADMIN_PASSWORD']
            ?? $_SERVER['PLATFORM_ADMIN_PASSWORD']
            ?? getenv('PLATFORM_ADMIN_PASSWORD');

        $this->previousPlatformAdminPassword = $previous === false ? null : (string) $previous;
    }

    protected function tearDown(): void
    {
        if ($this->hadPlatformAdminPassword && $this->previousPlatformAdminPassword !== null) {
            $this->writePlatformAdminPasswordEnv($this->previousPlatformAdminPassword);
        } else {
            $this->clearPlatformAdminPasswordEnv();
        }

        parent::tearDown();
    }

    public function test_missing_password_fails_seed(): void
    {
        $this->clearPlatformAdminPasswordEnv();

        $this->expectException(PlatformAdminSeedPasswordException::class);
        $this->expectExceptionMessage('PLATFORM_ADMIN_PASSWORD is missing.');

        $this->seed(FoundationSeeder::class);
    }

    public function test_empty_password_fails_seed(): void
    {
        $this->writePlatformAdminPasswordEnv('');

        $this->expectException(PlatformAdminSeedPasswordException::class);
        $this->expectExceptionMessage('PLATFORM_ADMIN_PASSWORD is empty.');

        $this->seed(FoundationSeeder::class);
    }

    public function test_default_password_value_is_rejected_by_seed(): void
    {
        $this->writePlatformAdminPasswordEnv('password');

        $this->expectException(PlatformAdminSeedPasswordException::class);
        $this->expectExceptionMessage('PLATFORM_ADMIN_PASSWORD is too weak or matches a known default.');

        $this->seed(FoundationSeeder::class);
    }

    public function test_strong_env_password_allows_seed_and_rejects_old_default_login(): void
    {
        $password = 'HasemFeatureTest-PlatformAdmin-NotAProdSecret';
        $this->writePlatformAdminPasswordEnv($password);

        $this->seed(FoundationSeeder::class);

        $admin = PlatformAdmin::query()->firstOrFail();

        $this->assertTrue(Hash::check($password, $admin->password));
        $this->assertFalse(Hash::check('password', $admin->password));

        $this->postJson('/platform/login', [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertUnprocessable();

        $this->postJson('/platform/login', [
            'email' => $admin->email,
            'password' => $password,
        ])->assertOk();
    }

    public function test_platform_admin_factory_remains_independent_of_seed_password_rules(): void
    {
        $this->clearPlatformAdminPasswordEnv();

        $admin = PlatformAdmin::factory()->create([
            'email' => 'factory-only@example.com',
            'password' => 'password',
        ]);

        $this->assertTrue(Hash::check('password', $admin->password));
        $this->assertSame(0, PlatformAdmin::query()->where('email', 'admin@hasem.local')->count());
    }

    private function writePlatformAdminPasswordEnv(string $password): void
    {
        $_ENV['PLATFORM_ADMIN_PASSWORD'] = $password;
        $_SERVER['PLATFORM_ADMIN_PASSWORD'] = $password;
        putenv('PLATFORM_ADMIN_PASSWORD='.$password);
    }

    private function clearPlatformAdminPasswordEnv(): void
    {
        unset($_ENV['PLATFORM_ADMIN_PASSWORD'], $_SERVER['PLATFORM_ADMIN_PASSWORD']);
        putenv('PLATFORM_ADMIN_PASSWORD');
    }
}
