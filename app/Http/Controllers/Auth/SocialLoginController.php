<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuthIdentity;
use App\Models\User;
use App\Services\Cashier\CashierGoogleBrowserLogin;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class SocialLoginController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
        private readonly CashierGoogleBrowserLogin $cashierGoogleBrowserLogin,
    ) {}

    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, ['google', 'facebook'], true), 404);

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider)
    {
        abort_unless(in_array($provider, ['google', 'facebook'], true), 404);

        $state = trim((string) request()->input('state', ''));
        if ($provider === 'google') {
            $ticket = $this->cashierGoogleBrowserLogin->parseTicket($state);
            if ($ticket !== null) {
                return $this->cashierGoogleBrowserLogin->complete($provider, $ticket);
            }
        }

        if (Auth::check()) {
            return redirect()->route('workspace.choose');
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (InvalidStateException) {
            return redirect()
                ->route('login')
                ->withErrors([
                    'email' => 'تعذر إكمال تسجيل الدخول عبر Google. أعد المحاولة.',
                ]);
        }

        $identity = AuthIdentity::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $socialUser->getId())
            ->first();

        if ($identity) {
            $user = $identity->user;
        } else {
            $email = $socialUser->getEmail() ?: 'social-'.Str::lower(Str::random(12)).'@local.invalid';

            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $socialUser->getName() ?: 'Social User',
                    'password' => Str::password(24),
                    'email_verified_at' => now(),
                ]
            );

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
        }

        $workspace = $user->workspaces()->wherePivot('status', 'active')->first()
            ?? $this->workspaceService->createForUser($user, 'individual');

        Auth::login($user);
        request()->session()->put('current_workspace_id', $workspace->id);

        return redirect()->route('workspace.choose');
    }
}
