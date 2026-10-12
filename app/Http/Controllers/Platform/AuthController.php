<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('platform.auth.login');
    }

    public function login(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = auth('platform_admin')->getProvider()->retrieveByCredentials([
            'email' => $validated['email'],
        ]);

        if (! $admin || ! Hash::check($validated['password'], $admin->password)) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Invalid platform credentials.'], 422);
            }

            return back()
                ->withErrors(['email' => 'بيانات منصة الإدارة غير صحيحة.'])
                ->onlyInput('email');
        }

        Auth::guard('platform_admin')->login($admin);
        $admin->forceFill(['last_login_at' => now()])->save();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Platform admin authenticated.',
                'data' => ['admin' => $admin],
            ]);
        }

        $request->session()->regenerate();

        return redirect()->route('platform.dashboard')->with('success', 'تم تسجيل الدخول كمدير منصة.');
    }

    public function logout(Request $request): RedirectResponse|JsonResponse
    {
        auth('platform_admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Platform admin logged out.']);
        }

        return redirect()->route('platform.login')->with('success', 'تم تسجيل الخروج.');
    }
}
