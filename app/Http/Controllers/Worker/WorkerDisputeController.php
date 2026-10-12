<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\CreateWorkerDisputeRequest;
use App\Http\Requests\Worker\WorkerDisputeFilterRequest;
use App\Models\User;
use App\Services\Worker\WorkerDisputeException;
use App\Services\Worker\WorkerDisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkerDisputeController extends Controller
{
    public function __construct(
        private readonly WorkerDisputeService $disputes,
    ) {}

    public function index(WorkerDisputeFilterRequest $request): View
    {
        $worker = $this->worker($request);

        return view('worker.disputes.index', [
            'disputes' => $this->disputes->workerIndex(
                $worker,
                $request->validated(),
            ),
        ]);
    }

    public function show(Request $request, int $dispute): View
    {
        $worker = $this->worker($request);

        return view('worker.disputes.show', [
            'dispute' => $this->disputes->workerShow($worker, $dispute),
        ]);
    }

    public function store(
        CreateWorkerDisputeRequest $request,
        int $engagement,
    ): RedirectResponse {
        $worker = $this->worker($request);

        try {
            $dispute = $this->disputes->openForWorker(
                $worker,
                $engagement,
                $request->validated(),
                $request,
            );
        } catch (WorkerDisputeException $exception) {
            return back()->withInput()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return redirect()
            ->route('worker.disputes.show', $dispute->id)
            ->with('success', 'تم تسجيل النزاع للمراجعة.');
    }

    private function worker(Request $request): User
    {
        $worker = $request->user();
        abort_unless($worker instanceof User, 403);

        return $worker;
    }
}
