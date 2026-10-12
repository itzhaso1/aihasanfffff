<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1\Worker;

use App\Http\Controllers\Api\Mobile\MobileController;
use App\Http\Requests\Worker\CreateWorkerDisputeRequest;
use App\Http\Requests\Worker\DisputeWorkerEarningPeriodRequest;
use App\Http\Requests\Worker\StoreWorkerExperienceRequest;
use App\Http\Requests\Worker\StoreWorkerInboxMessageRequest;
use App\Http\Requests\Worker\SubmitWorkerVerificationRequest;
use App\Http\Requests\Worker\SyncWorkerLanguagesRequest;
use App\Http\Requests\Worker\SyncWorkerSkillsRequest;
use App\Http\Requests\Worker\UpdateWorkerExperienceRequest;
use App\Http\Requests\Worker\UpdateWorkerIdentityRequest;
use App\Http\Requests\Worker\UpdateWorkerProfessionalProfileRequest;
use App\Http\Requests\Worker\UploadWorkerVerificationDocumentRequest;
use App\Http\Requests\Worker\WorkerDisputeFilterRequest;
use App\Http\Resources\Mobile\MessageResource;
use App\Http\Resources\Mobile\Worker\WorkerConversationResource;
use App\Http\Resources\Mobile\Worker\WorkerDisputeResource;
use App\Http\Resources\Mobile\Worker\WorkerEarningSummaryResource;
use App\Http\Resources\Mobile\Worker\WorkerEngagementResource;
use App\Http\Resources\Mobile\Worker\WorkerIdentityResource;
use App\Http\Resources\Mobile\Worker\WorkerPayoutResource;
use App\Http\Resources\Mobile\Worker\WorkerProductResource;
use App\Http\Resources\Mobile\Worker\WorkerProfileResource;
use App\Http\Resources\Mobile\Worker\WorkerSessionStateResource;
use App\Http\Resources\Mobile\Worker\WorkerVerificationDocumentResource;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerSkill;
use App\Models\Worker\WorkerVerificationDocument;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Workspace;
use App\Services\Worker\WorkerDisputeException;
use App\Services\Worker\WorkerDisputeService;
use App\Services\Worker\WorkerEarningsException;
use App\Services\Worker\WorkerEarningsService;
use App\Services\Worker\WorkerEngagementException;
use App\Services\Worker\WorkerEngagementProductService;
use App\Services\Worker\WorkerEngagementService;
use App\Services\Worker\WorkerInboxService;
use App\Services\Worker\WorkerPayoutService;
use App\Services\Worker\WorkerProfileService;
use App\Services\Worker\WorkerTaxonomyService;
use App\Services\Worker\WorkerWorkSessionException;
use App\Services\Worker\WorkerWorkSessionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class WorkerAccountController extends MobileController
{
    public function __construct(
        private readonly WorkerEngagementService $engagements,
        private readonly WorkerEngagementProductService $products,
        private readonly WorkerWorkSessionService $sessions,
        private readonly WorkerEarningsService $earnings,
        private readonly WorkerPayoutService $payouts,
        private readonly WorkerDisputeService $disputes,
        private readonly WorkerTaxonomyService $taxonomy,
        private readonly WorkerProfileService $profiles,
        private readonly WorkerInboxService $inbox,
    ) {}

    public function profile(Request $request): JsonResponse
    {
        return $this->ok(new WorkerProfileResource($this->ownedProfile($request)));
    }

    public function updateProfile(UpdateWorkerProfessionalProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->updateProfile(
            $this->ownedProfile($request),
            $request->validated(),
            $request->file('profile_photo'),
        );

        return $this->ok(new WorkerProfileResource($profile->load([
            'skills',
            'languages',
            'experiences',
            'latestVerification',
            'openVerificationIssues',
        ])));
    }

    public function skillCatalog(): JsonResponse
    {
        $skills = WorkerSkill::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'skill_key', 'display_name']);

        return $this->ok($skills->map(fn (WorkerSkill $skill): array => [
            'id' => $skill->id,
            'skill_key' => $skill->skill_key,
            'display_name' => $skill->display_name,
        ])->values()->all());
    }

    public function languageCatalog(): JsonResponse
    {
        $languages = collect(config('worker_professional.languages', []))
            ->filter(fn (mixed $language): bool => is_array($language) && filled($language['code'] ?? null))
            ->map(fn (array $language): array => [
                'code' => (string) $language['code'],
                'display_name' => (string) ($language['display_name'] ?? $language['code']),
            ])
            ->values()
            ->all();

        return $this->ok($languages);
    }

    public function destroyExperience(Request $request, int $experience): JsonResponse
    {
        try {
            $this->taxonomy->deleteExperience($this->user($request), $experience);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok(new WorkerProfileResource($this->ownedProfile($request)));
    }

    public function identity(Request $request): JsonResponse
    {
        $identity = $this->ownedProfile($request)->privateIdentity;

        if ($identity === null) {
            return $this->ok([
                'legal_name' => null,
                'date_of_birth' => null,
                'identity_number' => null,
                'address' => null,
            ]);
        }

        return $this->ok(new WorkerIdentityResource($identity));
    }

    public function updateIdentity(UpdateWorkerIdentityRequest $request): JsonResponse
    {
        $profile = $this->ownedProfile($request);
        $this->profiles->updateProfile($profile, [
            'display_name' => $profile->display_name,
            'country_code' => $profile->country_code,
            'nationality' => $profile->nationality,
            'professional_summary' => $profile->professional_summary,
            'sales_experience_level' => $profile->sales_experience_level,
            'availability_status' => $profile->availability_status,
            ...$request->validated(),
        ], null, false);

        return $this->ok(new WorkerIdentityResource($profile->privateIdentity()->firstOrFail()));
    }

    public function documents(Request $request): JsonResponse
    {
        $documents = WorkerVerificationDocument::query()
            ->where('worker_profile_id', $this->ownedProfile($request)->id)
            ->latest('id')
            ->get();

        return $this->ok(WorkerVerificationDocumentResource::collection($documents));
    }

    public function uploadDocument(UploadWorkerVerificationDocumentRequest $request): JsonResponse
    {
        try {
            $document = $this->profiles->uploadDocument(
                $this->ownedProfile($request),
                $this->user($request),
                $request->file('document'),
                $request->string('document_type_code')->trim()->toString(),
            );
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new WorkerVerificationDocumentResource($document), [], '', 201);
    }

    public function submitVerification(SubmitWorkerVerificationRequest $request): JsonResponse
    {
        try {
            $profile = $this->profiles->submitForReview($this->ownedProfile($request));
        } catch (RuntimeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new WorkerProfileResource($profile->load([
            'skills',
            'languages',
            'experiences',
            'latestVerification',
            'openVerificationIssues',
        ])));
    }

    public function syncSkills(SyncWorkerSkillsRequest $request): JsonResponse
    {
        $profile = $this->taxonomy->syncSkills(
            $request->user(),
            array_map('intval', $request->validated('skill_ids')),
        );

        return $this->ok(new WorkerProfileResource($profile->load([
            'languages',
            'experiences',
            'openVerificationIssues',
        ])));
    }

    public function syncLanguages(SyncWorkerLanguagesRequest $request): JsonResponse
    {
        $profile = $this->taxonomy->syncLanguages(
            $request->user(),
            $request->validated('language_codes'),
        );

        return $this->ok(new WorkerProfileResource($profile->load([
            'skills',
            'experiences',
            'openVerificationIssues',
        ])));
    }

    public function storeExperience(StoreWorkerExperienceRequest $request): JsonResponse
    {
        $this->taxonomy->createExperience($request->user(), $this->experienceAttributes($request));

        return $this->ok(new WorkerProfileResource($this->ownedProfile($request)), [], '', 201);
    }

    public function updateExperience(UpdateWorkerExperienceRequest $request, int $experience): JsonResponse
    {
        try {
            $this->taxonomy->updateExperience(
                $request->user(),
                $experience,
                $this->experienceAttributes($request),
            );
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok(new WorkerProfileResource($this->ownedProfile($request)));
    }

    public function engagements(Request $request): JsonResponse
    {
        $records = $this->engagements->forWorker($this->user($request));
        $records->load(['permissions.capabilityDefinition', 'guide']);

        return $this->ok(WorkerEngagementResource::collection($records));
    }

    public function engagement(Request $request, int $engagement): JsonResponse
    {
        try {
            $record = $this->engagements->forWorkerOrFail($this->user($request), $engagement);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        $record->load(['permissions.capabilityDefinition', 'guide']);

        return $this->ok(new WorkerEngagementResource($record));
    }

    public function products(Request $request, int $engagement): JsonResponse
    {
        try {
            $items = $this->products->forWorker($this->user($request), $engagement);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok(WorkerProductResource::collection($items));
    }

    public function acceptEngagement(Request $request, int $engagement): JsonResponse
    {
        return $this->respondToOffer(
            fn () => $this->engagements->accept($this->user($request), $engagement, $request),
        );
    }

    public function rejectEngagement(Request $request, int $engagement): JsonResponse
    {
        return $this->respondToOffer(
            fn () => $this->engagements->reject($this->user($request), $engagement, $request),
        );
    }

    public function session(Request $request, int $engagement): JsonResponse
    {
        try {
            $state = $this->sessions->forWorker($this->user($request), $engagement);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok(WorkerSessionStateResource::collection($state['sessions']));
    }

    public function startSession(Request $request, int $engagement): JsonResponse
    {
        return $this->runSession(
            fn (): WorkerWorkSession => $this->sessions->start($this->user($request), $engagement, $request),
        );
    }

    public function breakSession(Request $request, int $engagement): JsonResponse
    {
        return $this->runSession(
            fn (): WorkerWorkSession => $this->sessions->beginBreak($this->user($request), $engagement, $request),
        );
    }

    public function resumeSession(Request $request, int $engagement): JsonResponse
    {
        return $this->runSession(
            fn (): WorkerWorkSession => $this->sessions->resume($this->user($request), $engagement, $request),
        );
    }

    public function endSession(Request $request, int $engagement): JsonResponse
    {
        return $this->runSession(
            fn (): WorkerWorkSession => $this->sessions->end($this->user($request), $engagement, $request),
        );
    }

    public function earnings(Request $request, int $engagement): JsonResponse
    {
        try {
            $summary = $this->earnings->forWorker($this->user($request), $engagement, includeDraft: false);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok(WorkerEarningSummaryResource::collection($summary['periods']));
    }

    public function disputePeriod(
        DisputeWorkerEarningPeriodRequest $request,
        int $engagement,
        int $period,
    ): JsonResponse {
        try {
            $record = $this->earnings->dispute(
                $this->user($request),
                $engagement,
                $period,
                $request->validated('reason'),
                $request,
            );
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        } catch (WorkerEarningsException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new WorkerEarningSummaryResource($record));
    }

    public function payouts(Request $request): JsonResponse
    {
        return $this->ok(WorkerPayoutResource::collection(
            $this->payouts->forWorker($this->user($request)),
        ));
    }

    public function disputes(WorkerDisputeFilterRequest $request): JsonResponse
    {
        $page = $this->disputes->workerIndex($this->user($request), $request->validated());

        return $this->ok(
            WorkerDisputeResource::collection($page->getCollection()),
            $this->pageMeta($page),
        );
    }

    public function dispute(Request $request, int $dispute): JsonResponse
    {
        try {
            $record = $this->disputes->workerShow($this->user($request), $dispute);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok(new WorkerDisputeResource($record));
    }

    public function storeDispute(CreateWorkerDisputeRequest $request, int $engagement): JsonResponse
    {
        try {
            $record = $this->disputes->openForWorker(
                $this->user($request),
                $engagement,
                $request->validated(),
                $request,
            );
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        } catch (WorkerDisputeException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new WorkerDisputeResource($record), [], '', 201);
    }

    public function inbox(Request $request, Workspace $workspace): JsonResponse
    {
        try {
            $conversations = $this->inbox->conversations($this->user($request), $workspace);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok(WorkerConversationResource::collection($conversations));
    }

    public function conversation(Request $request, Workspace $workspace, int $conversation): JsonResponse
    {
        try {
            $record = $this->inbox->conversation($this->user($request), $workspace, $conversation);
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok([
            'conversation' => new WorkerConversationResource($record),
            'messages' => MessageResource::collection($record->messages),
        ]);
    }

    public function sendMessage(
        StoreWorkerInboxMessageRequest $request,
        Workspace $workspace,
        int $conversation,
    ): JsonResponse {
        try {
            $message = $this->inbox->sendMessage(
                $this->user($request),
                $workspace,
                $conversation,
                $request->validated(),
                $request,
            );
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        }

        return $this->ok(new MessageResource($message), [], '', 201);
    }

    /**
     * @param  \Closure(): WorkerWorkSession  $operation
     */
    private function runSession(\Closure $operation): JsonResponse
    {
        try {
            $session = $operation();
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        } catch (WorkerWorkSessionException $exception) {
            return $this->fail($exception->getMessage(), 422);
        }

        return $this->ok(new WorkerSessionStateResource($session));
    }

    /**
     * @param  \Closure(): WorkerEngagement  $operation
     */
    private function respondToOffer(\Closure $operation): JsonResponse
    {
        try {
            $engagement = $operation();
        } catch (ModelNotFoundException) {
            return $this->fail('غير موجود.', 404);
        } catch (WorkerEngagementException) {
            return $this->fail('لا يمكن تنفيذ هذا الإجراء على هذا الارتباط.', 422);
        }

        $engagement->load(['workspace', 'permissions.capabilityDefinition', 'guide']);

        return $this->ok(new WorkerEngagementResource($engagement));
    }

    private function ownedProfile(Request $request): WorkerProfile
    {
        $profile = WorkerProfile::query()
            ->where('user_id', $this->user($request)->id)
            ->with([
                'skills',
                'languages',
                'experiences',
                'latestVerification',
                'openVerificationIssues',
            ])
            ->first();

        abort_if($profile === null, 404);

        return $profile;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return $user;
    }

    /**
     * @return array{title:string, organization_name:string, started_on:string, ended_on:?string, is_current:bool, description:?string}
     */
    private function experienceAttributes(StoreWorkerExperienceRequest $request): array
    {
        $validated = $request->validated();

        return [
            'title' => $validated['title'],
            'organization_name' => $validated['organization_name'],
            'started_on' => $validated['started_on'],
            'ended_on' => $validated['ended_on'] ?? null,
            'is_current' => (bool) $validated['is_current'],
            'description' => $validated['description'] ?? null,
        ];
    }
}
