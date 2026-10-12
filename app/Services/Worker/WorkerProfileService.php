<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerVerification;
use App\Models\Worker\WorkerVerificationDocument;
use App\Models\Worker\WorkerVerificationIssue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class WorkerProfileService
{
    private const ALLOWED_DOCUMENT_MIMES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    private const MAX_DOCUMENT_BYTES = 8 * 1024 * 1024;

    public function register(array $payload): WorkerProfile
    {
        return DB::transaction(function () use ($payload): WorkerProfile {
            $user = User::query()->make([
                'name' => $payload['name'],
                'email' => $payload['email'],
                'phone' => $payload['phone'] ?? null,
            ]);
            $user->forceFill([
                'password' => Hash::make($payload['password']),
                'email_verified_at' => null,
            ])->save();

            $profile = WorkerProfile::query()->create([
                'user_id' => $user->id,
                'display_name' => $payload['name'],
                'country_code' => $payload['country_code'] ?? WorkerProfile::COUNTRY_JORDAN,
                'nationality' => $payload['nationality'] ?? '',
                'verification_status' => WorkerProfile::VERIFICATION_NOT_REQUESTED,
                'lifecycle_status' => WorkerProfile::LIFECYCLE_REGISTERED,
                'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
            ]);

            $profile->discoverySettings()->create([
                'is_discoverable' => false,
            ]);

            $this->audit(
                'worker.profile.registered',
                $profile->id,
                null,
                ['lifecycle_status' => $profile->lifecycle_status],
            );

            return $profile->refresh();
        });
    }

    public function updateProfile(
        WorkerProfile $profile,
        array $payload,
        ?UploadedFile $photo = null,
        bool $resolveProfileIssues = true,
    ): WorkerProfile {
        return DB::transaction(function () use (
            $profile,
            $payload,
            $photo,
            $resolveProfileIssues,
        ): WorkerProfile {
            $profile->loadMissing(['privateIdentity', 'discoverySettings']);

            $old = $profile->only([
                'display_name',
                'profile_photo',
                'country_code',
                'nationality',
                'professional_summary',
                'sales_experience_level',
                'availability_status',
                'lifecycle_status',
            ]);
            $old['is_discoverable'] = $profile->discoverySettings?->is_discoverable ?? false;

            $profile->forceFill([
                'display_name' => $payload['display_name'],
                'country_code' => $payload['country_code'],
                'nationality' => $payload['nationality'],
                'professional_summary' => $payload['professional_summary'],
                'sales_experience_level' => $payload['sales_experience_level'],
                'availability_status' => $payload['availability_status'],
            ]);

            if ($photo !== null) {
                $this->assertValidPhoto($photo);
                $path = $photo->storeAs(
                    'worker-profile-photos/'.$profile->id,
                    Str::uuid()->toString().'.'.strtolower((string) $photo->getClientOriginalExtension()),
                    'local',
                );

                if (! $path) {
                    throw new RuntimeException('تعذر حفظ الصورة الشخصية.');
                }

                if ($profile->profile_photo) {
                    Storage::disk('local')->delete($profile->profile_photo);
                }

                $profile->profile_photo = $path;
            }

            if ($profile->isSuspended()) {
                $profile->lifecycle_status = WorkerProfile::LIFECYCLE_SUSPENDED;
            } elseif ($profile->verification_status === WorkerProfile::VERIFICATION_APPROVED) {
                $profile->lifecycle_status = WorkerProfile::LIFECYCLE_VERIFIED;
            } elseif ($profile->verification_status === WorkerProfile::VERIFICATION_PENDING_REVIEW) {
                $profile->lifecycle_status = WorkerProfile::LIFECYCLE_VERIFICATION_PENDING;
            } elseif ($profile->isProfileComplete()) {
                $profile->lifecycle_status = WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE;
            } else {
                $profile->lifecycle_status = WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE;
            }

            $profile->save();

            if (array_key_exists('legal_name', $payload)) {
                $profile->privateIdentity()->updateOrCreate(
                    ['worker_profile_id' => $profile->id],
                    [
                        'legal_name' => $payload['legal_name'],
                        'date_of_birth' => $payload['date_of_birth'],
                        'identity_number' => $payload['identity_number'],
                        'address' => $payload['address'] ?? null,
                    ],
                );
            }
            $profileFields = [
                'display_name',
                'country_code',
                'nationality',
                'professional_summary',
                'sales_experience_level',
                'availability_status',
                'profile_photo',
            ];
            if ($resolveProfileIssues) {
                $changedProfileFields = array_values(array_intersect(
                    $profileFields,
                    array_keys($payload),
                ));
                if ($photo !== null) {
                    $changedProfileFields[] = 'profile_photo';
                }
                $this->resolveIssuesForFields(
                    $profile,
                    WorkerVerificationIssue::TARGET_PROFILE,
                    $changedProfileFields,
                );
            }
            if (array_key_exists('legal_name', $payload)
                || array_key_exists('date_of_birth', $payload)
                || array_key_exists('identity_number', $payload)
                || array_key_exists('address', $payload)) {
                $this->resolveIssuesForFields(
                    $profile,
                    WorkerVerificationIssue::TARGET_IDENTITY,
                    array_keys(array_intersect_key(
                        $payload,
                        array_flip([
                            'legal_name',
                            'date_of_birth',
                            'identity_number',
                            'address',
                        ]),
                    )),
                );
            }
            $profile->discoverySettings()->updateOrCreate(
                ['worker_profile_id' => $profile->id],
                [
                    'is_discoverable' => array_key_exists('is_discoverable', $payload)
                        ? filter_var($payload['is_discoverable'], FILTER_VALIDATE_BOOLEAN)
                        : ($profile->discoverySettings?->is_discoverable ?? false),
                ],
            );

            $new = $profile->only([
                'display_name',
                'profile_photo',
                'country_code',
                'nationality',
                'professional_summary',
                'sales_experience_level',
                'availability_status',
                'lifecycle_status',
            ]);
            $new['is_discoverable'] = $profile->discoverySettings()->value('is_discoverable') ?? false;

            $this->audit(
                'worker.profile.updated',
                $profile->id,
                $old,
                $new,
            );

            return $profile->refresh();
        });
    }

    public function uploadDocument(
        WorkerProfile $profile,
        User $user,
        UploadedFile $file,
        string $documentTypeCode,
    ): WorkerVerificationDocument {
        if ($profile->isSuspended() || $profile->verification_status === WorkerProfile::VERIFICATION_APPROVED) {
            throw new RuntimeException('لا يمكن رفع مستند في حالة الحساب الحالية.');
        }

        $this->assertValidDocument($file);
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $path = $file->storeAs(
            'worker-verification-documents/'.$profile->id,
            Str::uuid()->toString().($extension !== '' ? '.'.$extension : ''),
            'local',
        );

        if (! $path) {
            throw new RuntimeException('تعذر حفظ المستند.');
        }

        return DB::transaction(function () use ($profile, $user, $file, $documentTypeCode, $path): WorkerVerificationDocument {
            WorkerVerificationDocument::query()
                ->where('worker_profile_id', $profile->id)
                ->where('document_type_code', $documentTypeCode)
                ->whereIn('status', [
                    WorkerVerificationDocument::STATUS_SUBMITTED,
                    WorkerVerificationDocument::STATUS_REJECTED,
                ])
                ->update(['status' => WorkerVerificationDocument::STATUS_REPLACED]);

            $document = WorkerVerificationDocument::query()->create([
                'worker_profile_id' => $profile->id,
                'document_type_code' => $documentTypeCode,
                'disk' => 'local',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                'size_bytes' => $file->getSize(),
                'status' => WorkerVerificationDocument::STATUS_SUBMITTED,
                'uploaded_by' => $user->id,
            ]);

            $this->resolveDocumentIssues($profile, $documentTypeCode);

            $this->audit(
                'worker.verification_document.uploaded',
                $document->id,
                null,
                ['worker_profile_id' => $profile->id, 'document_type_code' => $documentTypeCode],
                null,
                WorkerVerificationDocument::class,
            );

            return $document->refresh();
        });
    }

    public function submitForReview(WorkerProfile $profile): WorkerProfile
    {
        $profile->load('privateIdentity');

        if ($profile->isSuspended()) {
            throw new RuntimeException('الحساب معلّق ولا يمكن إرساله للمراجعة.');
        }

        if (! $profile->isProfileComplete()) {
            throw new RuntimeException('أكمل بيانات الملف والهوية الخاصة قبل الإرسال للمراجعة.');
        }

        if (! $profile->documents()->where('status', WorkerVerificationDocument::STATUS_SUBMITTED)->exists()) {
            throw new RuntimeException('يجب رفع مستند واحد على الأقل قبل الإرسال للمراجعة.');
        }

        if ($profile->verification_status === WorkerProfile::VERIFICATION_PENDING_REVIEW) {
            return $profile;
        }

        if ($profile->verification_status === WorkerProfile::VERIFICATION_APPROVED) {
            throw new RuntimeException('الحساب موثّق بالفعل.');
        }

        return DB::transaction(function () use ($profile): WorkerProfile {
            $verification = WorkerVerification::query()->create([
                'worker_profile_id' => $profile->id,
                'status' => WorkerVerification::STATUS_PENDING_REVIEW,
                'submitted_at' => now(),
            ]);

            $profile->documents()
                ->where('status', WorkerVerificationDocument::STATUS_SUBMITTED)
                ->update(['worker_verification_id' => $verification->id]);

            $old = $profile->only(['verification_status', 'lifecycle_status']);
            $profile->forceFill([
                'verification_status' => WorkerProfile::VERIFICATION_PENDING_REVIEW,
                'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
            ])->save();

            $this->audit(
                'worker.verification.submitted',
                $profile->id,
                $old,
                $profile->only(['verification_status', 'lifecycle_status']),
                ['worker_verification_id' => $verification->id],
            );

            return $profile->refresh();
        });
    }

    public function approve(WorkerProfile $profile, PlatformAdmin $admin, ?string $notes = null): WorkerProfile
    {
        $verification = $this->pendingVerification($profile);
        $old = $profile->only(['verification_status', 'lifecycle_status']);

        DB::transaction(function () use ($profile, $verification, $admin, $notes): void {
            $verification->forceFill([
                'status' => WorkerVerification::STATUS_APPROVED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'review_notes' => $notes,
                'rejection_reason' => null,
            ])->save();

            $profile->documents()
                ->where('worker_verification_id', $verification->id)
                ->where('status', WorkerVerificationDocument::STATUS_SUBMITTED)
                ->update([
                    'status' => WorkerVerificationDocument::STATUS_APPROVED,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ]);

            $profile->forceFill([
                'verification_status' => WorkerProfile::VERIFICATION_APPROVED,
                'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFIED,
            ])->save();

            $this->resolveAllIssues($profile);
        });

        $this->audit(
            'worker.verification.approved',
            $profile->id,
            $old,
            $profile->only(['verification_status', 'lifecycle_status']),
            ['platform_admin_id' => $admin->id, 'notes' => $notes],
        );

        return $profile->refresh();
    }

    /**
     * @param  list<array{target_type:string, target_id?:int|null, field_key?:string|null, reason:string}>  $issues
     */
    public function reject(
        WorkerProfile $profile,
        PlatformAdmin $admin,
        string $reason,
        array $issues = [],
    ): WorkerProfile {
        $verification = $this->pendingVerification($profile);
        $old = $profile->only(['verification_status', 'lifecycle_status']);

        DB::transaction(function () use ($profile, $verification, $admin, $reason, $issues): void {
            $verification->forceFill([
                'status' => WorkerVerification::STATUS_REJECTED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'rejection_reason' => $reason,
            ])->save();
            $this->upsertReviewIssues($profile, $verification, $admin, $issues);

            $profile->forceFill([
                'verification_status' => WorkerProfile::VERIFICATION_REJECTED,
                'lifecycle_status' => WorkerProfile::LIFECYCLE_VERIFICATION_REJECTED,
            ])->save();
        });

        $this->audit(
            'worker.verification.rejected',
            $profile->id,
            $old,
            $profile->only(['verification_status', 'lifecycle_status']),
            ['platform_admin_id' => $admin->id],
        );

        return $profile->refresh();
    }

    /**
     * @param  list<array{target_type:string, target_id?:int|null, field_key?:string|null, reason:string}>  $issues
     */
    public function requestDocuments(
        WorkerProfile $profile,
        PlatformAdmin $admin,
        string $reason,
        array $issues = [],
    ): WorkerProfile {
        $verification = $this->pendingVerification($profile);
        $old = $profile->only(['verification_status', 'lifecycle_status']);

        DB::transaction(function () use ($profile, $verification, $admin, $reason, $issues): void {
            $verification->forceFill([
                'status' => WorkerVerification::STATUS_DOCUMENTS_REQUIRED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'rejection_reason' => $reason,
            ])->save();
            $this->upsertReviewIssues($profile, $verification, $admin, $issues);

            $profile->forceFill([
                'verification_status' => WorkerProfile::VERIFICATION_DOCUMENTS_REQUIRED,
                'lifecycle_status' => WorkerProfile::LIFECYCLE_PROFILE_INCOMPLETE,
            ])->save();
        });

        $this->audit(
            'worker.verification.documents_required',
            $profile->id,
            $old,
            $profile->only(['verification_status', 'lifecycle_status']),
            ['platform_admin_id' => $admin->id],
        );

        return $profile->refresh();
    }

    public function suspend(WorkerProfile $profile, PlatformAdmin $admin, string $reason): WorkerProfile
    {
        $old = $profile->only(['verification_status', 'lifecycle_status']);
        $verification = $profile->verifications()->latest('id')->first();

        if ($verification !== null && $verification->status !== WorkerVerification::STATUS_SUSPENDED) {
            $verification->forceFill([
                'status' => WorkerVerification::STATUS_SUSPENDED,
                'reviewed_at' => now(),
                'reviewed_by' => $admin->id,
                'rejection_reason' => $reason,
            ])->save();
        }

        $profile->forceFill([
            'verification_status' => WorkerProfile::VERIFICATION_SUSPENDED,
            'lifecycle_status' => WorkerProfile::LIFECYCLE_SUSPENDED,
        ])->save();

        $this->audit(
            'worker.verification.suspended',
            $profile->id,
            $old,
            $profile->only(['verification_status', 'lifecycle_status']),
            ['platform_admin_id' => $admin->id],
        );

        return $profile->refresh();
    }

    /**
     * @param  list<array{target_type:string, target_id?:int|null, field_key?:string|null, reason:string}>  $issues
     */
    private function upsertReviewIssues(
        WorkerProfile $profile,
        WorkerVerification $verification,
        PlatformAdmin $admin,
        array $issues,
    ): void {
        foreach ($issues as $issue) {
            $targetType = (string) $issue['target_type'];
            $targetId = isset($issue['target_id']) ? (int) $issue['target_id'] : null;
            $fieldKey = filled($issue['field_key'] ?? null)
                ? trim((string) $issue['field_key'])
                : null;
            $reason = trim((string) $issue['reason']);

            $this->assertReviewIssueTarget($profile, $targetType, $targetId);

            $record = WorkerVerificationIssue::query()
                ->where('worker_profile_id', $profile->id)
                ->where('target_type', $targetType)
                ->where('target_id', $targetId)
                ->where('field_key', $fieldKey)
                ->where('status', WorkerVerificationIssue::STATUS_OPEN)
                ->first();

            if ($record === null) {
                $record = new WorkerVerificationIssue;
            }

            $record->forceFill([
                'worker_profile_id' => $profile->id,
                'worker_verification_id' => $verification->id,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'field_key' => $fieldKey,
                'reason' => $reason,
                'status' => WorkerVerificationIssue::STATUS_OPEN,
                'resolved_at' => null,
            ])->save();

            if ($targetType === WorkerVerificationIssue::TARGET_DOCUMENT && $targetId !== null) {
                $document = WorkerVerificationDocument::query()
                    ->where('worker_profile_id', $profile->id)
                    ->whereKey($targetId)
                    ->firstOrFail();
                $document->forceFill([
                    'status' => WorkerVerificationDocument::STATUS_REJECTED,
                    'rejection_reason' => $reason,
                    'reviewed_by' => $admin->id,
                    'reviewed_at' => now(),
                ])->save();
            }
        }
    }

    private function assertReviewIssueTarget(
        WorkerProfile $profile,
        string $targetType,
        ?int $targetId,
    ): void {
        if ($targetType === WorkerVerificationIssue::TARGET_PROFILE
            || $targetType === WorkerVerificationIssue::TARGET_SKILLS
            || $targetType === WorkerVerificationIssue::TARGET_LANGUAGES
            || $targetType === WorkerVerificationIssue::TARGET_IDENTITY) {
            if ($targetType === WorkerVerificationIssue::TARGET_PROFILE
                || $targetType === WorkerVerificationIssue::TARGET_IDENTITY) {
                if ($targetId !== null && $targetId !== $profile->id) {
                    throw new RuntimeException('هدف مراجعة غير صالح لهذا العامل.');
                }
            }

            if ($targetType === WorkerVerificationIssue::TARGET_SKILLS
                && ($targetId !== null && ! $profile->skills()->whereKey($targetId)->exists())) {
                throw new RuntimeException('المهارة المحددة غير مرتبطة بهذا العامل.');
            }

            if ($targetType === WorkerVerificationIssue::TARGET_LANGUAGES
                && ($targetId !== null && ! $profile->languages()->whereKey($targetId)->exists())) {
                throw new RuntimeException('اللغة المحددة غير مرتبطة بهذا العامل.');
            }

            return;
        }

        if ($targetType === WorkerVerificationIssue::TARGET_EXPERIENCE
            && ($targetId === null || ! $profile->experiences()->whereKey($targetId)->exists())) {
            throw new RuntimeException('الخبرة المحددة غير مرتبطة بهذا العامل.');
        }

        if ($targetType === WorkerVerificationIssue::TARGET_DOCUMENT
            && ($targetId === null || ! $profile->documents()->whereKey($targetId)->exists())) {
            throw new RuntimeException('المستند المحدد غير مرتبط بهذا العامل.');
        }
    }

    /**
     * @param  list<string>  $fieldKeys
     */
    private function resolveIssuesForFields(
        WorkerProfile $profile,
        string $targetType,
        array $fieldKeys,
    ): void {
        if ($fieldKeys === []) {
            return;
        }

        WorkerVerificationIssue::query()
            ->where('worker_profile_id', $profile->id)
            ->where('target_type', $targetType)
            ->where('status', WorkerVerificationIssue::STATUS_OPEN)
            ->whereIn('field_key', $fieldKeys)
            ->update([
                'status' => WorkerVerificationIssue::STATUS_RESOLVED,
                'resolved_at' => now(),
            ]);
    }

    private function resolveDocumentIssues(WorkerProfile $profile, string $documentTypeCode): void
    {
        WorkerVerificationIssue::query()
            ->where('worker_profile_id', $profile->id)
            ->where('target_type', WorkerVerificationIssue::TARGET_DOCUMENT)
            ->where('status', WorkerVerificationIssue::STATUS_OPEN)
            ->where('field_key', $documentTypeCode)
            ->update([
                'status' => WorkerVerificationIssue::STATUS_RESOLVED,
                'resolved_at' => now(),
            ]);
    }

    private function resolveAllIssues(WorkerProfile $profile): void
    {
        WorkerVerificationIssue::query()
            ->where('worker_profile_id', $profile->id)
            ->where('status', WorkerVerificationIssue::STATUS_OPEN)
            ->update([
                'status' => WorkerVerificationIssue::STATUS_RESOLVED,
                'resolved_at' => now(),
            ]);
    }

    private function pendingVerification(WorkerProfile $profile): WorkerVerification
    {
        $verification = $profile->verifications()
            ->where('status', WorkerVerification::STATUS_PENDING_REVIEW)
            ->latest('id')
            ->first();

        if ($verification === null) {
            throw new RuntimeException('لا توجد معاملة مراجعة معلّقة لهذا العامل.');
        }

        return $verification;
    }

    private function assertValidPhoto(UploadedFile $file): void
    {
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        if ($file->getSize() !== null && $file->getSize() > 4 * 1024 * 1024) {
            throw new RuntimeException('حجم الصورة يتجاوز الحد الأقصى (4 ميجابايت).');
        }

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('نوع الصورة غير مسموح. المسموح: JPEG, PNG, WEBP.');
        }
    }

    private function assertValidDocument(UploadedFile $file): void
    {
        if ($file->getSize() !== null && $file->getSize() > self::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('حجم الملف يتجاوز الحد الأقصى (8 ميجابايت).');
        }

        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'svg' || str_contains($mime, 'svg')) {
            throw new RuntimeException('ملفات SVG غير مسموحة.');
        }

        if (! in_array($mime, self::ALLOWED_DOCUMENT_MIMES, true)
            && ! in_array($extension, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('نوع الملف غير مسموح. المسموح: PDF, JPEG, PNG, WEBP.');
        }
    }

    private function audit(
        string $action,
        int $entityId,
        ?array $oldValues,
        ?array $newValues,
        ?array $meta = null,
        string $entityType = WorkerProfile::class,
    ): void {
        AuditLog::withoutGlobalScopes()->create([
            'workspace_id' => null,
            'user_id' => null,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'meta' => $meta,
            'occurred_at' => now(),
        ]);
    }
}
