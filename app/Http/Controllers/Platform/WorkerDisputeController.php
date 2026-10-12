<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\OpenWorkerDisputeRequest;
use App\Http\Requests\Platform\ReviewWorkerDisputeRequest;
use App\Http\Requests\Platform\WorkerDisputeFilterRequest;
use App\Models\PlatformAdmin;
use App\Services\Worker\WorkerDisputeException;
use App\Services\Worker\WorkerDisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class WorkerDisputeController extends Controller
{
    public function __construct(
        private readonly WorkerDisputeService $disputes,
    ) {}

    public function index(WorkerDisputeFilterRequest $request): View
    {
        $admin = $this->admin($request);

        return view('platform.worker-disputes.index', [
            'disputes' => $this->disputes->platformIndex($admin, $request->validated()),
        ]);
    }

    public function show(WorkerDisputeFilterRequest $request, int $dispute): View
    {
        return view('platform.worker-disputes.show', [
            'dispute' => $this->disputes->platformShow($this->admin($request), $dispute),
        ]);
    }

    public function store(
        OpenWorkerDisputeRequest $request,
        int $engagement,
    ): RedirectResponse {
        try {
            $dispute = $this->disputes->openForPlatform(
                $this->admin($request),
                $engagement,
                $request->validated(),
                $request,
            );
        } catch (WorkerDisputeException $exception) {
            return back()->withInput()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return redirect()
            ->route('platform.worker-disputes.show', $dispute->id)
            ->with('success', 'تم تسجيل النزاع للمراجعة.');
    }

    public function beginReview(
        ReviewWorkerDisputeRequest $request,
        int $dispute,
    ): RedirectResponse {
        try {
            $this->disputes->beginReview(
                $this->admin($request),
                $dispute,
                $request->validated('review_notes'),
                $request,
            );
        } catch (WorkerDisputeException $exception) {
            return back()->withInput()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return $this->redirectToDispute($dispute, 'تم تحويل النزاع إلى قيد المراجعة.');
    }

    public function resolve(
        ReviewWorkerDisputeRequest $request,
        int $dispute,
    ): RedirectResponse {
        try {
            $this->disputes->resolve(
                $this->admin($request),
                $dispute,
                (string) $request->validated('resolution_summary'),
                $request->validated('review_notes'),
                $request,
            );
        } catch (WorkerDisputeException $exception) {
            return back()->withInput()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return $this->redirectToDispute($dispute, 'تم حل النزاع.');
    }

    public function close(
        ReviewWorkerDisputeRequest $request,
        int $dispute,
    ): RedirectResponse {
        try {
            $this->disputes->close(
                $this->admin($request),
                $dispute,
                $request->validated('review_notes'),
                $request,
            );
        } catch (WorkerDisputeException $exception) {
            return back()->withInput()->withErrors(['dispute' => $exception->getMessage()]);
        }

        return $this->redirectToDispute($dispute, 'تم إغلاق النزاع.');
    }

    private function admin(WorkerDisputeFilterRequest|ReviewWorkerDisputeRequest|OpenWorkerDisputeRequest $request): PlatformAdmin
    {
        $admin = $request->user('platform_admin');
        abort_unless($admin instanceof PlatformAdmin, 403);

        return $admin;
    }

    private function redirectToDispute(int $dispute, string $message): RedirectResponse
    {
        return redirect()
            ->route('platform.worker-disputes.show', $dispute)
            ->with('success', $message);
    }
}
