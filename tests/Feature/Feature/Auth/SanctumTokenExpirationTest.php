<?php

namespace Tests\Feature\Feature\Auth;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class SanctumTokenExpirationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_tokens_are_issued_with_expires_at(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $payload = app(AuthenticationService::class)->loginWithPassword(
            $user->email,
            'password',
            $workspace->id
        );

        $token = $payload['token']->accessToken;
        $this->assertInstanceOf(PersonalAccessToken::class, $token);
        $this->assertNotNull($token->expires_at);
        $this->assertTrue($token->expires_at->greaterThan(now()->addDays(20)));
        $this->assertTrue($token->expires_at->lessThanOrEqualTo(now()->addDays(31)));
    }
}
