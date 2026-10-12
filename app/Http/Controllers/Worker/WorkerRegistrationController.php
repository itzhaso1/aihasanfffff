<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\WorkerRegistrationRequest;
use App\Services\Worker\WorkerProfileService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class WorkerRegistrationController extends Controller
{
    public function __construct(
        private readonly WorkerProfileService $workerProfileService,
    ) {}

    public function create(): View
    {
        return view('workers.register');
    }

    public function store(WorkerRegistrationRequest $request): RedirectResponse
    {
        $profile = $this->workerProfileService->register($request->validated());
        $user = $profile->user;

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('worker.inbox.home')
            ->with('success', 'تم إنشاء حساب العامل. المحادثات المسندة إليك تظهر هنا.');
    }
}
