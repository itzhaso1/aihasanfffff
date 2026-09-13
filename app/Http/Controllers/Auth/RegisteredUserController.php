<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthenticationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $authenticationService,
    ) {}

    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'unique:users,phone'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'workspace_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Legacy workspace_type column kept; new registrations default to company.
        $workspaceType = 'company';

        $payload = $this->authenticationService->registerForSession([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'phone' => $request->string('phone')->toString() ?: null,
            'password' => $request->string('password')->toString(),
            'workspace_type' => $workspaceType,
            'workspace_name' => $request->string('workspace_name')->toString() ?: null,
        ]);

        $user = $payload['user'];
        $workspace = $payload['workspace'];

        event(new Registered($user));

        Auth::login($user);
        $request->session()->put('current_workspace_id', $workspace->id);

        return redirect(route('workspace.subscriptions.index', absolute: false));
    }
}
