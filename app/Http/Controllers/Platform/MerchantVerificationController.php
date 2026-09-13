<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\MerchantDocument;
use App\Models\MerchantProfile;
use App\Services\Merchant\MerchantVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MerchantVerificationController extends Controller
{
    public function __construct(
        private readonly MerchantVerificationService $verificationService,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->string('status')->toString();

        $profiles = MerchantProfile::withoutGlobalScopes()
            ->with(['workspace.owner'])
            ->when($status !== '', fn ($q) => $q->where('verification_status', $status))
            ->when($status === '', fn ($q) => $q->whereIn('verification_status', [
                MerchantProfile::VERIFICATION_PENDING_REVIEW,
                MerchantProfile::VERIFICATION_DOCUMENTS_REQUIRED,
                MerchantProfile::VERIFICATION_APPROVED,
                MerchantProfile::VERIFICATION_REJECTED,
                MerchantProfile::VERIFICATION_SUSPENDED,
            ]))
            ->latest('submitted_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('platform.merchant-verifications.index', compact('profiles', 'status'));
    }

    public function show(int $merchantProfile): View
    {
        $profile = MerchantProfile::withoutGlobalScopes()->findOrFail($merchantProfile);
        $profile->load(['workspace.owner', 'reviewer']);
        $documents = MerchantDocument::withoutGlobalScopes()
            ->where('merchant_profile_id', $profile->id)
            ->latest('id')
            ->get();

        return view('platform.merchant-verifications.show', [
            'profile' => $profile,
            'documents' => $documents,
            'verificationService' => $this->verificationService,
        ]);
    }

    public function approve(Request $request, int $merchantProfile): RedirectResponse
    {
        $profile = MerchantProfile::withoutGlobalScopes()->findOrFail($merchantProfile);
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->verificationService->approve(
            $profile,
            $request->user('platform_admin'),
            $validated['notes'] ?? null,
        );

        return redirect()
            ->route('platform.merchant-verifications.show', $profile->id)
            ->with('success', 'تمت الموافقة على التوثيق.');
    }

    public function reject(Request $request, int $merchantProfile): RedirectResponse
    {
        $profile = MerchantProfile::withoutGlobalScopes()->findOrFail($merchantProfile);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->verificationService->reject(
            $profile,
            $request->user('platform_admin'),
            $validated['reason'],
        );

        return redirect()
            ->route('platform.merchant-verifications.show', $profile->id)
            ->with('success', 'تم رفض التوثيق.');
    }

    public function suspend(Request $request, int $merchantProfile): RedirectResponse
    {
        $profile = MerchantProfile::withoutGlobalScopes()->findOrFail($merchantProfile);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->verificationService->suspend(
            $profile,
            $request->user('platform_admin'),
            $validated['reason'],
        );

        return redirect()
            ->route('platform.merchant-verifications.show', $profile->id)
            ->with('success', 'تم تعليق حساب التاجر.');
    }

    public function requestDocuments(Request $request, int $merchantProfile): RedirectResponse
    {
        $profile = MerchantProfile::withoutGlobalScopes()->findOrFail($merchantProfile);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $this->verificationService->requestDocuments(
            $profile,
            $request->user('platform_admin'),
            $validated['reason'],
        );

        return redirect()
            ->route('platform.merchant-verifications.show', $profile->id)
            ->with('success', 'تم طلب مستندات إضافية.');
    }

    public function downloadDocument(Request $request, int $document): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $doc = MerchantDocument::withoutGlobalScopes()->findOrFail($document);
        $disk = Storage::disk($doc->disk ?: 'local');
        abort_unless($disk->exists($doc->path), 404);

        return $disk->response(
            $doc->path,
            $doc->original_name ?: basename($doc->path),
            [
                'Content-Type' => $doc->mime_type ?: 'application/octet-stream',
            ]
        );
    }
}
