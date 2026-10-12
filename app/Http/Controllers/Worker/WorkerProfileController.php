<?php

declare(strict_types=1);

namespace App\Http\Controllers\Worker;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worker\SubmitWorkerVerificationRequest;
use App\Http\Requests\Worker\UpdateWorkerProfileRequest;
use App\Http\Requests\Worker\UploadWorkerVerificationDocumentRequest;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Services\Worker\WorkerProfileService;
use App\Services\Worker\WorkerVerificationRequirementCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class WorkerProfileController extends Controller
{
    public function __construct(
        private readonly WorkerProfileService $workerProfileService,
        private readonly WorkerVerificationRequirementCatalog $verificationRequirements,
    ) {}

    public function edit(Request $request): View
    {
        $profile = $this->profileFor($request)
            ->load([
                'privateIdentity',
                'discoverySettings',
                'documents' => fn ($query) => $query->latest('id'),
                'latestVerification',
                'skills',
                'languages',
                'experiences',
            ]);

        $country = (string) $profile->country_code;

        return view('workers.profile', [
            'profile' => $profile,
            'skillCatalog' => WorkerSkill::query()->orderBy('sort_order')->orderBy('id')->get(),
            'languageCatalog' => config('worker_professional.languages', []),
            'requiredDocuments' => in_array($country, WorkerProfile::COUNTRIES, true)
                ? $this->verificationRequirements->requiredDocumentTypes($country)
                : [],
            'verificationNotice' => $profile->workerVisibleVerificationNotice(),
        ]);
    }

    public function update(UpdateWorkerProfileRequest $request): RedirectResponse
    {
        $profile = $this->workerProfileService->updateProfile(
            $this->profileFor($request),
            $request->validated(),
            $request->file('profile_photo'),
        );

        return back()->with('success', 'تم حفظ بيانات الملف والهوية الخاصة.');
    }

    public function uploadDocument(UploadWorkerVerificationDocumentRequest $request): RedirectResponse
    {
        try {
            $this->workerProfileService->uploadDocument(
                $this->profileFor($request),
                $request->user(),
                $request->file('document'),
                $request->string('document_type_code')->trim()->toString(),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['document' => $exception->getMessage()]);
        }

        return back()->with('success', 'تم رفع المستند بشكل آمن.');
    }

    public function submit(SubmitWorkerVerificationRequest $request): RedirectResponse
    {
        try {
            $this->workerProfileService->submitForReview($this->profileFor($request));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['verification' => $exception->getMessage()]);
        }

        return back()->with('success', 'تم إرسال الملف للمراجعة.');
    }

    private function profileFor(Request $request): WorkerProfile
    {
        return WorkerProfile::query()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
