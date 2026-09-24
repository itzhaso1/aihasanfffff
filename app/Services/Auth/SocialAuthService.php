<?php

namespace App\Services\Auth;

use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialUser;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;

class SocialAuthService
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
    ) {}

    public function loginWithAccessToken(string $provider, string $accessToken, ?int $workspaceId = null): array
    {
        if (! in_array($provider, ['google', 'facebook'], true)) {
            throw new RuntimeException('Unsupported social provider.');
        }

        /** @var SocialUser $socialUser */
        $socialUser = Socialite::driver($provider)->stateless()->userFromToken($accessToken);

        return DB::transaction(function () use ($provider, $socialUser, $workspaceId): array {
            $user = $this->findOrProvisionUser($provider, $socialUser);

            $workspace = null;
            if ($workspaceId !== null) {
                $workspace = $user->workspaces()
                    ->where('workspaces.id', $workspaceId)
                    ->wherePivot('status', 'active')
                    ->first();
            }

            if (! $workspace) {
                $workspace = $user->workspaces()->wherePivot('status', 'active')->first()
                    ?? $this->workspaceService->createForUser($user, 'individual');
            }

            $token = $user->createToken('api', ['*'], now()->addDays((int) config('security.api_token_days', 30)));
            $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

            return compact('user', 'workspace', 'token');
        });
    }

    public function findOrProvisionUser(string $provider, SocialUser $socialUser): User
    {
        if (! in_array($provider, ['google', 'facebook'], true)) {
            throw new RuntimeException('Unsupported social provider.');
        }

        $identity = AuthIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $socialUser->getId())
            ->first();

        if ($identity) {
            return $identity->user;
        }

        $email = $socialUser->getEmail() ?: 'social-'.Str::lower(Str::random(12)).'@local.invalid';

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $user = User::query()->make([
                'name' => $socialUser->getName() ?: 'Social User',
                'email' => $email,
            ]);
            // users.password is NOT NULL. Never persist null/empty, and never use a
            // guessable or deterministic secret. The hashed cast hashes this on set.
            $user->forceFill([
                'password' => $this->randomUnusablePassword(),
                'email_verified_at' => now(),
            ]);
            $user->save();
        }

        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $socialUser->getId(),
            'provider_email' => $socialUser->getEmail(),
            'provider_data' => [
                'name' => $socialUser->getName(),
                'nickname' => $socialUser->getNickname(),
                'avatar' => $socialUser->getAvatar(),
            ],
        ]);

        return $user;
    }

    /**
     * CSPRNG secret for OAuth-only users. It satisfies the NOT NULL column and
     * cannot be used as a password-login bypass because it is never disclosed.
     */
    private function randomUnusablePassword(): string
    {
        return Str::password(32);
    }
}
