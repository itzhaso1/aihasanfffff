<?php

namespace App\Services\Merchant;

use App\Models\MerchantDocument;
use App\Models\MerchantDocumentType;
use App\Models\MerchantProfile;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Services\Payment\Contracts\MerchantSettlementProviderInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

class MerchantVerificationService
{
    private const ALLOWED_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly MerchantSettlementProviderInterface $settlementProvider,
    ) {}

    public function profile(Workspace $workspace): MerchantProfile
    {
        $profile = MerchantProfile::withoutGlobalScopes()->firstOrCreate(
            ['workspace_id' => $workspace->id],
        );

        if ($profile->wasRecentlyCreated || blank($profile->verification_status)) {
            $profile->forceFill([
                'verification_status' => $profile->verification_status ?: MerchantProfile::VERIFICATION_NOT_REQUESTED,
                'provider_onboarding_status' => $profile->provider_onboarding_status ?: MerchantProfile::PROVIDER_NOT_STARTED,
            ])->save();
        }

        return $profile->refresh();
    }

    public function requestVerification(Workspace $workspace, User $user): MerchantProfile
    {
        $profile = $this->profile($workspace);

        if (in_array($profile->verification_status, [
            MerchantProfile::VERIFICATION_PENDING_REVIEW,
            MerchantProfile::VERIFICATION_APPROVED,
        ], true)) {
            return $profile;
        }

        $old = $profile->verification_status;
        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_DOCUMENTS_REQUIRED,
        ])->save();

        $this->auditLogService->log(
            action: 'merchant.verification.requested',
            entityType: MerchantProfile::class,
            entityId: $profile->id,
            oldValues: ['verification_status' => $old],
            newValues: ['verification_status' => $profile->verification_status],
            actor: $user,
            workspaceId: $workspace->id,
        );

        return $profile->refresh();
    }

    public function uploadDocument(
        Workspace $workspace,
        User $user,
        UploadedFile $file,
        string $typeCode,
        ?string $number = null,
        ?string $expiresAt = null,
    ): MerchantDocument {
        $this->assertValidUpload($file);

        $type = MerchantDocumentType::query()
            ->where('code', $typeCode)
            ->where('is_active', true)
            ->first();

        if (! $type) {
            throw new RuntimeException('نوع المستند غير معروف أو غير مفعّل.');
        }

        $profile = $this->profile($workspace);
        if ($profile->verification_status === MerchantProfile::VERIFICATION_NOT_REQUESTED) {
            $this->requestVerification($workspace, $user);
            $profile = $profile->refresh();
        }

        $directory = 'merchant-documents/'.$workspace->id;
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $filename = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $path = $file->storeAs($directory, $filename, 'local');

        if (! $path) {
            throw new RuntimeException('تعذر حفظ المستند.');
        }

        return DB::transaction(function () use ($workspace, $user, $file, $type, $profile, $path, $number, $expiresAt): MerchantDocument {
            MerchantDocument::withoutGlobalScopes()
                ->where('merchant_profile_id', $profile->id)
                ->where('document_type_code', $type->code)
                ->where('status', MerchantDocument::STATUS_SUBMITTED)
                ->update(['status' => MerchantDocument::STATUS_REPLACED]);

            $document = MerchantDocument::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'merchant_profile_id' => $profile->id,
                'document_type_id' => $type->id,
                'document_type_code' => $type->code,
                'document_number' => $number,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'expires_at' => $expiresAt,
                'uploaded_by' => $user->id,
            ]);

            $document->forceFill(['status' => MerchantDocument::STATUS_SUBMITTED])->save();

            $this->auditLogService->log(
                action: 'merchant.document.uploaded',
                entityType: MerchantDocument::class,
                entityId: $document->id,
                newValues: [
                    'document_type_code' => $type->code,
                    'original_name' => $document->original_name,
                    'mime_type' => $document->mime_type,
                ],
                actor: $user,
                workspaceId: $workspace->id,
            );

            return $document->refresh();
        });
    }

    public function submitForReview(Workspace $workspace, User $user): MerchantProfile
    {
        $profile = $this->profile($workspace);

        $hasDocuments = MerchantDocument::withoutGlobalScopes()
            ->where('merchant_profile_id', $profile->id)
            ->where('status', MerchantDocument::STATUS_SUBMITTED)
            ->exists();

        if (! $hasDocuments) {
            throw new RuntimeException('يجب رفع مستند واحد على الأقل قبل الإرسال للمراجعة.');
        }

        if (! in_array($profile->verification_status, [
            MerchantProfile::VERIFICATION_DOCUMENTS_REQUIRED,
            MerchantProfile::VERIFICATION_REJECTED,
            MerchantProfile::VERIFICATION_NOT_REQUESTED,
        ], true)) {
            throw new RuntimeException('لا يمكن إرسال الطلب للمراجعة في الحالة الحالية.');
        }

        $old = $profile->only(['verification_status', 'submitted_at']);
        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_PENDING_REVIEW,
            'submitted_at' => now(),
            'rejection_reason' => null,
        ])->save();

        $this->auditLogService->log(
            action: 'merchant.verification.submitted',
            entityType: MerchantProfile::class,
            entityId: $profile->id,
            oldValues: $old,
            newValues: $profile->only(['verification_status', 'submitted_at']),
            actor: $user,
            workspaceId: $workspace->id,
        );

        return $profile->refresh();
    }

    public function approve(MerchantProfile $profile, PlatformAdmin $admin, ?string $notes = null): MerchantProfile
    {
        $old = $profile->only(['verification_status', 'provider_onboarding_status', 'approved_at']);

        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_APPROVED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'approved_at' => now(),
            'rejection_reason' => null,
            'suspended_at' => null,
            'metadata' => array_merge(is_array($profile->metadata) ? $profile->metadata : [], [
                'approval_notes' => $notes,
            ]),
        ])->save();

        $onboarding = $this->settlementProvider->startOnboarding(
            $profile->workspace ?? Workspace::query()->findOrFail($profile->workspace_id),
            $profile->refresh()
        );

        $profile->forceFill([
            'provider' => 'hyperpay',
            'provider_onboarding_status' => $onboarding['status'] ?? MerchantProfile::PROVIDER_PENDING,
            'provider_merchant_id' => $onboarding['provider_merchant_id'] ?? $profile->provider_merchant_id,
            'metadata' => array_merge(is_array($profile->metadata) ? $profile->metadata : [], [
                'provider_onboarding_message' => $onboarding['message'] ?? null,
            ]),
        ])->save();

        $this->auditLogService->log(
            action: 'merchant.verification.approved',
            entityType: MerchantProfile::class,
            entityId: $profile->id,
            oldValues: $old,
            newValues: $profile->only([
                'verification_status',
                'provider_onboarding_status',
                'provider_merchant_id',
                'approved_at',
            ]),
            meta: ['platform_admin_id' => $admin->id, 'notes' => $notes],
            workspaceId: $profile->workspace_id,
        );

        return $profile->refresh();
    }

    public function reject(MerchantProfile $profile, PlatformAdmin $admin, string $reason): MerchantProfile
    {
        $old = $profile->only(['verification_status', 'rejection_reason']);

        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_REJECTED,
            'rejection_reason' => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ])->save();

        $this->auditLogService->log(
            action: 'merchant.verification.rejected',
            entityType: MerchantProfile::class,
            entityId: $profile->id,
            oldValues: $old,
            newValues: $profile->only(['verification_status', 'rejection_reason']),
            meta: ['platform_admin_id' => $admin->id],
            workspaceId: $profile->workspace_id,
        );

        return $profile->refresh();
    }

    public function suspend(MerchantProfile $profile, PlatformAdmin $admin, string $reason): MerchantProfile
    {
        $old = $profile->only(['verification_status', 'rejection_reason', 'suspended_at']);

        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_SUSPENDED,
            'rejection_reason' => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'suspended_at' => now(),
        ])->save();

        $this->auditLogService->log(
            action: 'merchant.verification.suspended',
            entityType: MerchantProfile::class,
            entityId: $profile->id,
            oldValues: $old,
            newValues: $profile->only(['verification_status', 'rejection_reason', 'suspended_at']),
            meta: ['platform_admin_id' => $admin->id],
            workspaceId: $profile->workspace_id,
        );

        return $profile->refresh();
    }

    public function requestDocuments(MerchantProfile $profile, PlatformAdmin $admin, string $reason): MerchantProfile
    {
        $old = $profile->only(['verification_status', 'rejection_reason']);

        $profile->forceFill([
            'verification_status' => MerchantProfile::VERIFICATION_DOCUMENTS_REQUIRED,
            'rejection_reason' => $reason,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
        ])->save();

        $this->auditLogService->log(
            action: 'merchant.verification.documents_required',
            entityType: MerchantProfile::class,
            entityId: $profile->id,
            oldValues: $old,
            newValues: $profile->only(['verification_status', 'rejection_reason']),
            meta: ['platform_admin_id' => $admin->id],
            workspaceId: $profile->workspace_id,
        );

        return $profile->refresh();
    }

    public function temporaryDocumentUrl(MerchantDocument $document, int $expiresMinutes = 15): string
    {
        return URL::temporarySignedRoute(
            'platform.merchant-verifications.documents.download',
            now()->addMinutes(max(1, $expiresMinutes)),
            ['document' => $document->id]
        );
    }

    private function assertValidUpload(UploadedFile $file): void
    {
        if ($file->getSize() !== null && $file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('حجم الملف يتجاوز الحد الأقصى (8 ميجابايت).');
        }

        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'svg' || str_contains($mime, 'svg')) {
            throw new RuntimeException('ملفات SVG غير مسموحة.');
        }

        if (! in_array($mime, self::ALLOWED_MIMES, true)
            && ! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)
        ) {
            throw new RuntimeException('نوع الملف غير مسموح. المسموح: PDF, JPEG, PNG, WEBP.');
        }
    }
}
