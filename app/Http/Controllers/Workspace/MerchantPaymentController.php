<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Models\MerchantDocumentType;
use App\Services\Merchant\MerchantPaymentEligibilityService;
use App\Services\Merchant\MerchantVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MerchantPaymentController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly MerchantVerificationService $verificationService,
        private readonly MerchantPaymentEligibilityService $eligibilityService,
    ) {}

    public function show(): View
    {
        $workspace = $this->currentWorkspace();
        $profile = $this->verificationService->profile($workspace);
        $documents = $profile->documents()
            ->withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->get();
        $documentTypes = MerchantDocumentType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
        $eligibility = $this->eligibilityService->statusSnapshot($workspace);

        return view('workspace.payments.merchant-settings', compact(
            'workspace',
            'profile',
            'documents',
            'documentTypes',
            'eligibility',
        ));
    }

    public function requestVerification(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->verificationService->requestVerification($workspace, $request->user());

        return redirect()
            ->route('workspace.payments.merchant.show')
            ->with('success', 'تم بدء طلب توثيق التاجر. ارفع المستندات ثم أرسل للمراجعة.');
    }

    public function uploadDocument(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $validated = $request->validate([
            'document_type_code' => ['required', 'string', 'max:64'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'document' => ['required', 'file', 'max:8192', 'mimes:pdf,jpeg,jpg,png,webp'],
        ]);

        $this->verificationService->uploadDocument(
            workspace: $workspace,
            user: $request->user(),
            file: $request->file('document'),
            typeCode: $validated['document_type_code'],
            number: $validated['document_number'] ?? null,
            expiresAt: $validated['expires_at'] ?? null,
        );

        return redirect()
            ->route('workspace.payments.merchant.show')
            ->with('success', 'تم رفع المستند بنجاح.');
    }

    public function submit(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->verificationService->submitForReview($workspace, $request->user());

        return redirect()
            ->route('workspace.payments.merchant.show')
            ->with('success', 'تم إرسال طلب التوثيق للمراجعة.');
    }

    public function downloadDocument(int $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $workspace = $this->currentWorkspace();
        $doc = \App\Models\MerchantDocument::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereKey($document)
            ->firstOrFail();

        $disk = \Illuminate\Support\Facades\Storage::disk($doc->disk ?: 'local');
        abort_unless($disk->exists($doc->path), 404);

        return $disk->response(
            $doc->path,
            $doc->original_name ?: basename($doc->path),
            ['Content-Type' => $doc->mime_type ?: 'application/octet-stream']
        );
    }
}
