<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\ReviewWorkerVerificationRequest;
use App\Http\Requests\Platform\SuspendWorkerRequest;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerVerificationDocument;
use App\Services\Worker\WorkerProfileService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkerVerificationController extends Controller
{
    public function __construct(
        private readonly WorkerProfileService $workerProfileService,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();
        $profiles = WorkerProfile::query()
            ->with('user')
            ->when($status !== '', fn ($query) => $query->where('verification_status', $status))
            ->when($status === '', fn ($query) => $query->whereIn('verification_status', [
                WorkerProfile::VERIFICATION_PENDING_REVIEW,
                WorkerProfile::VERIFICATION_DOCUMENTS_REQUIRED,
                WorkerProfile::VERIFICATION_REJECTED,
            ]))
            ->latest('updated_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('platform.worker-verifications.index', compact('profiles', 'status'));
    }

    public function show(int $workerProfile): View
    {
        $profile = WorkerProfile::query()
            ->with([
                'user',
                'privateIdentity',
                'latestVerification',
                'skills',
                'languages',
                'experiences',
            ])
            ->findOrFail($workerProfile);

        $documents = $profile->documents()->latest('id')->get();

        return view('platform.worker-verifications.show', compact('profile', 'documents'));
    }

    public function approve(Request $request, int $workerProfile): RedirectResponse
    {
        $profile = WorkerProfile::query()->findOrFail($workerProfile);
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->workerProfileService->approve(
                $profile,
                $request->user('platform_admin'),
                $validated['notes'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['verification' => $exception->getMessage()]);
        }

        return redirect()
            ->route('platform.worker-verifications.show', $profile->id)
            ->with('success', 'تم اعتماد العامل.');
    }

    public function reject(ReviewWorkerVerificationRequest $request, int $workerProfile): RedirectResponse
    {
        $profile = WorkerProfile::query()->findOrFail($workerProfile);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->workerProfileService->reject(
                $profile,
                $request->user('platform_admin'),
                $validated['reason'],
                $request->validated('issues', []),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['verification' => $exception->getMessage()]);
        }

        return redirect()
            ->route('platform.worker-verifications.show', $profile->id)
            ->with('success', 'تم رفض طلب العامل.');
    }

    public function requestDocuments(ReviewWorkerVerificationRequest $request, int $workerProfile): RedirectResponse
    {
        $profile = WorkerProfile::query()->findOrFail($workerProfile);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->workerProfileService->requestDocuments(
                $profile,
                $request->user('platform_admin'),
                $validated['reason'],
                $request->validated('issues', []),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['verification' => $exception->getMessage()]);
        }

        return redirect()
            ->route('platform.worker-verifications.show', $profile->id)
            ->with('success', 'تم طلب مستندات إضافية.');
    }

    public function suspend(SuspendWorkerRequest $request, int $workerProfile): RedirectResponse
    {
        $profile = WorkerProfile::query()->findOrFail($workerProfile);

        $this->workerProfileService->suspend(
            $profile,
            $request->user('platform_admin'),
            $request->validated('reason'),
        );

        return redirect()
            ->route('platform.worker-verifications.show', $profile->id)
            ->with('success', 'تم تعليق حساب العامل.');
    }

    public function downloadDocument(Request $request, int $document): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $workerDocument = WorkerVerificationDocument::query()->findOrFail($document);
        $disk = Storage::disk($workerDocument->disk ?: 'local');
        abort_unless($disk->exists($workerDocument->path), 404);

        return $disk->response(
            $workerDocument->path,
            $workerDocument->original_name ?: basename($workerDocument->path),
            ['Content-Type' => $workerDocument->mime_type ?: 'application/octet-stream'],
        );
    }
}
