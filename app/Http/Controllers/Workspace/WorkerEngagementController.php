<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Worker\CreateWorkerEngagementRequest;
use App\Http\Requests\Worker\TerminateWorkerEngagementRequest;
use App\Http\Requests\Worker\UpdateWorkerEngagementGuideRequest;
use App\Http\Requests\Worker\WorkerEngagementActionRequest;
use App\Http\Requests\Worker\WorkerOperationalReportRequest;
use App\Models\Worker\WorkerEngagement;
use App\Services\Worker\WorkerEngagementException;
use App\Services\Worker\WorkerEngagementService;
use App\Services\Worker\WorkerOperationalReportingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WorkerEngagementController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly WorkerEngagementService $engagements,
        private readonly WorkerOperationalReportingService $reporting,
    ) {}

    public function index(WorkerOperationalReportRequest $request): View
    {
        $this->authorize('viewAny', WorkerEngagement::class);
        $workspace = $this->currentWorkspace();

        return view('workspace.worker-engagements.index', [
            'workspace' => $workspace,
            'engagements' => $this->reporting->companyOverview(
                $request->user(),
                $workspace,
                $request->validated(),
            ),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', WorkerEngagement::class);

        return view('workspace.worker-engagements.create', [
            'products' => $this->engagements->availableProducts($this->currentWorkspace()),
        ]);
    }

    public function store(CreateWorkerEngagementRequest $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();

        try {
            $engagement = $this->engagements->createOffer(
                $workspace,
                $request->user(),
                $request->validated(),
                $request,
            );
        } catch (WorkerEngagementException $exception) {
            return back()->withInput()->withErrors(['engagement' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.worker-engagements.show', $engagement)
            ->with('success', 'تم إرسال عرض الارتباط إلى العامل.');
    }

    public function show(
        WorkerOperationalReportRequest $request,
        WorkerEngagement $engagement,
    ): View {
        $this->authorize('view', $engagement);
        $workspace = $this->currentWorkspace();
        $report = $this->reporting->companyOverviewDetail(
            $request->user(),
            $workspace,
            $engagement,
            $request->validated(),
        );
        $report['engagement']->load(['creator', 'acceptances.user', 'guide']);

        return view('workspace.worker-engagements.show', $report);
    }

    public function updateGuide(
        UpdateWorkerEngagementGuideRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        try {
            $this->engagements->updateGuide(
                $this->currentWorkspace(),
                $request->user(),
                $engagement,
                $request->validated()['guide'] ?? [],
                $request,
            );
        } catch (WorkerEngagementException $exception) {
            return back()->withInput()->withErrors(['guide' => $exception->getMessage()]);
        }

        return back()->with('success', 'تم حفظ دليل العمل.');
    }

    public function activate(
        WorkerEngagementActionRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        return $this->transition(
            $engagement,
            fn (): WorkerEngagement => $this->engagements->activate(
                $this->currentWorkspace(),
                $request->user(),
                $engagement,
                $request,
            ),
            'تم تفعيل الارتباط.',
        );
    }

    public function pause(
        WorkerEngagementActionRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        return $this->transition(
            $engagement,
            fn (): WorkerEngagement => $this->engagements->pause(
                $this->currentWorkspace(),
                $request->user(),
                $engagement,
                $request,
            ),
            'تم إيقاف الارتباط مؤقتاً.',
        );
    }

    public function resume(
        WorkerEngagementActionRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        return $this->transition(
            $engagement,
            fn (): WorkerEngagement => $this->engagements->resume(
                $this->currentWorkspace(),
                $request->user(),
                $engagement,
                $request,
            ),
            'تم استئناف الارتباط.',
        );
    }

    public function complete(
        WorkerEngagementActionRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        return $this->transition(
            $engagement,
            fn (): WorkerEngagement => $this->engagements->complete(
                $this->currentWorkspace(),
                $request->user(),
                $engagement,
                $request,
            ),
            'تم إكمال الارتباط.',
        );
    }

    public function terminate(
        TerminateWorkerEngagementRequest $request,
        WorkerEngagement $engagement,
    ): RedirectResponse {
        return $this->transition(
            $engagement,
            fn (): WorkerEngagement => $this->engagements->terminate(
                $this->currentWorkspace(),
                $request->user(),
                $engagement,
                $request->string('reason')->toString(),
                $request,
            ),
            'تم إنهاء الارتباط.',
        );
    }

    /**
     * @param  \Closure(): WorkerEngagement  $operation
     */
    private function transition(
        WorkerEngagement $engagement,
        \Closure $operation,
        string $message,
    ): RedirectResponse {
        $this->authorize('manage', $engagement);

        try {
            $operation();
        } catch (WorkerEngagementException $exception) {
            return back()->withErrors(['engagement' => $exception->getMessage()]);
        }

        return redirect()
            ->route('workspace.worker-engagements.show', $engagement)
            ->with('success', $message);
    }
}
