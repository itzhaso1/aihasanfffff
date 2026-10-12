<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\WorkerOperationalReportRequest;
use App\Http\Requests\Worker\WorkerWorkSessionActionRequest;
use App\Models\User;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Worker\WorkerWorkSessionSegment;
use App\Services\Worker\WorkerOperationalReportingService;
use App\Services\Worker\WorkerWorkSessionException;
use App\Services\Worker\WorkerWorkSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkerWorkSessionController extends Controller
{
    public function __construct(
        private readonly WorkerWorkSessionService $sessions,
        private readonly WorkerOperationalReportingService $reporting,
    ) {}

    public function show(
        WorkerOperationalReportRequest $request,
        int $engagement,
    ): View {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);
        $data = $this->reporting->workerSessionReport(
            $worker,
            $engagement,
            $request->validated(),
        );
        $activeSession = $data['currentSession'];
        $openSegment = $activeSession?->segments->first(
            fn (WorkerWorkSessionSegment $segment): bool => $segment->ended_at === null,
        );

        return view('worker.engagements.sessions', [
            'engagement' => $data['engagement'],
            'sessions' => $data['sessions'],
            'activeSession' => $activeSession,
            'openSegment' => $openSegment,
            'activities' => $data['activities'],
            'sessionSummary' => $data['sessionSummary'],
            'workerSuspended' => $worker->workerProfile()->first()?->isSuspended() === true,
        ]);
    }

    public function start(
        WorkerWorkSessionActionRequest $request,
        int $engagement,
    ): RedirectResponse {
        return $this->runAction(
            $request,
            $engagement,
            fn (User $worker): WorkerWorkSession => $this->sessions->start(
                $worker,
                $engagement,
                $request,
            ),
            'تم بدء جلسة العمل.',
        );
    }

    public function end(
        WorkerWorkSessionActionRequest $request,
        int $engagement,
    ): RedirectResponse {
        return $this->runAction(
            $request,
            $engagement,
            fn (User $worker): WorkerWorkSession => $this->sessions->end(
                $worker,
                $engagement,
                $request,
            ),
            'تم إنهاء جلسة العمل.',
        );
    }

    public function beginBreak(
        WorkerWorkSessionActionRequest $request,
        int $engagement,
    ): RedirectResponse {
        return $this->runAction(
            $request,
            $engagement,
            fn (User $worker): WorkerWorkSession => $this->sessions->beginBreak(
                $worker,
                $engagement,
                $request,
            ),
            'تم تسجيل بداية الاستراحة.',
        );
    }

    public function resume(
        WorkerWorkSessionActionRequest $request,
        int $engagement,
    ): RedirectResponse {
        return $this->runAction(
            $request,
            $engagement,
            fn (User $worker): WorkerWorkSession => $this->sessions->resume(
                $worker,
                $engagement,
                $request,
            ),
            'تم استئناف العمل.',
        );
    }

    /**
     * @param  \Closure(User): WorkerWorkSession  $operation
     */
    private function runAction(
        WorkerWorkSessionActionRequest $request,
        int $engagement,
        \Closure $operation,
        string $message,
    ): RedirectResponse {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);

        try {
            $operation($worker);
        } catch (WorkerWorkSessionException $exception) {
            return back()->withErrors(['session' => $exception->getMessage()]);
        }

        return redirect()
            ->route('worker.engagements.sessions.show', $engagement)
            ->with('success', $message);
    }
}
