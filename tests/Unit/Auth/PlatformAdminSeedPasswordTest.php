<?php

namespace Tests\Unit\Auth;

use App\Exceptions\PlatformAdminSeedPasswordException;
use App\Support\Auth\PlatformAdminSeedPassword;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlatformAdminSeedPasswordTest extends TestCase
{
    public function test_missing_password_fails(): void
    {
        $this->expectException(PlatformAdminSeedPasswordException::class);
        $this->expectExceptionMessage('PLATFORM_ADMIN_PASSWORD is missing.');

        PlatformAdminSeedPassword::require(null);
    }

    public function test_empty_password_fails(): void
    {
        $this->expectException(PlatformAdminSeedPasswordException::class);
        $this->expectExceptionMessage('PLATFORM_ADMIN_PASSWORD is empty.');

        PlatformAdminSeedPassword::require('');
    }

    public function test_whitespace_password_fails(): void
    {
        $this->expectException(PlatformAdminSeedPasswordException::class);
        $this->expectExceptionMessage('PLATFORM_ADMIN_PASSWORD is empty.');

        PlatformAdminSeedPassword::require("  \t\n");
    }

    #[DataProvider('rejectedPasswords')]
    public function test_weak_or_default_password_is_rejected(string $password): void
    {
        try {
            PlatformAdminSeedPassword::require($password);
            $this->fail('Expected PlatformAdminSeedPasswordException.');
        } catch (PlatformAdminSeedPasswordException $exception) {
            $this->assertSame(
                'PLATFORM_ADMIN_PASSWORD is too weak or matches a known default. Set a strong password in the environment before db:seed.',
                $exception->getMessage()
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedPasswords(): array
    {
        return [
            'default password' => ['password'],
            'default password uppercase' => ['PASSWORD'],
            'admin' => ['admin'],
            'numeric default' => ['123456'],
            'too short' => ['shortpwd'],
            'known long default' => ['platformadmin'],
        ];
    }

    public function test_strong_password_is_accepted(): void
    {
        $password = 'HasemUnitTest-PlatformAdmin-NotAProdSecret';

        $this->assertSame($password, PlatformAdminSeedPassword::require($password));
    }

    public function test_exception_does_not_include_the_supplied_value(): void
    {
        $password = 'shortpwd';

        try {
            PlatformAdminSeedPassword::require($password);
            $this->fail('Expected PlatformAdminSeedPasswordException.');
        } catch (PlatformAdminSeedPasswordException $exception) {
            $this->assertStringNotContainsString($password, $exception->getMessage());
        }
    }
}
