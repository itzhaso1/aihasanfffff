<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class ApiPasswordResetHashingTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_password_reset_hashes_plain_password_once(): void
    {
        $user = User::factory()->create(['email' => 'mobile-reset@example.com']);
        $token = Password::broker()->createToken($user);
        $newPassword = 'mobile-reset-password-123';

        $this->postJson('/api/mobile/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check($newPassword, $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
    }

    public function test_cashier_password_reset_hashes_plain_password_once(): void
    {
        $user = User::factory()->create(['email' => 'cashier-reset@example.com']);
        $token = Password::broker()->createToken($user);
        $newPassword = 'cashier-reset-password-123';

        $this->postJson('/api/cashier/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check($newPassword, $fresh->password));
        $this->assertFalse(Hash::check('password', $fresh->password));
    }
}
