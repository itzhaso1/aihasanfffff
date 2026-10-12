<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SocialAuthService;
use App\Services\Workspace\WorkspaceService;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class SocialLoginController extends Controller
{
    public function __construct(
        private readonly WorkspaceService $workspaceService,
        private readonly SocialAuthService $socialAuthService,
    ) {}

    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, ['google', 'facebook'], true), 404);

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider)
    {
        abort_unless(in_array($provider, ['google', 'facebook'], true), 404);

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

        $user = $this->socialAuthService->findOrProvisionUser($provider, $socialUser);

        $workspace = $user->workspaces()->wherePivot('status', 'active')->first()
            ?? $this->workspaceService->createForUser($user, 'individual');

        Auth::login($user);
        request()->session()->put('current_workspace_id', $workspace->id);

        return redirect()->route('workspace.choose');
    }
}
