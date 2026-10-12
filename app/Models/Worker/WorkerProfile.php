<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'user_id',
    'display_name',
    'profile_photo',
    'country_code',
    'nationality',
    'verification_status',
    'lifecycle_status',
    'availability_status',
    'professional_summary',
    'sales_experience_level',
])]
class WorkerProfile extends Model
{
    public const COUNTRY_JORDAN = 'JO';

    public const COUNTRY_SAUDI_ARABIA = 'SA';

    public const COUNTRIES = [
        self::COUNTRY_JORDAN,
        self::COUNTRY_SAUDI_ARABIA,
    ];

    public const VERIFICATION_NOT_REQUESTED = 'not_requested';

    public const VERIFICATION_DOCUMENTS_REQUIRED = 'documents_required';

    public const VERIFICATION_PENDING_REVIEW = 'pending_review';

    public const VERIFICATION_APPROVED = 'approved';

    public const VERIFICATION_REJECTED = 'rejected';

    public const VERIFICATION_SUSPENDED = 'suspended';

    public const LIFECYCLE_REGISTERED = 'registered';

    public const LIFECYCLE_PROFILE_INCOMPLETE = 'profile_incomplete';

    public const LIFECYCLE_VERIFICATION_PENDING = 'verification_pending';

    public const LIFECYCLE_VERIFIED = 'verified';

    public const LIFECYCLE_VERIFICATION_REJECTED = 'verification_rejected';

    public const LIFECYCLE_SUSPENDED = 'suspended';

    public const AVAILABILITY_AVAILABLE = 'available';

    public const AVAILABILITY_LIMITED = 'limited';

    public const AVAILABILITY_UNAVAILABLE = 'unavailable';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function privateIdentity(): HasOne
    {
        return $this->hasOne(WorkerIdentityPrivate::class);
    }

    public function discoverySettings(): HasOne
    {
        return $this->hasOne(WorkerDiscoverySettings::class);
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(WorkerVerification::class);
    }

    public function verificationIssues(): HasMany
    {
        return $this->hasMany(WorkerVerificationIssue::class)->latest('id');
    }

    public function openVerificationIssues(): HasMany
    {
        return $this->verificationIssues()->where(
            'status',
            WorkerVerificationIssue::STATUS_OPEN,
        );
    }

    public function documents(): HasMany
    {
        return $this->hasMany(WorkerVerificationDocument::class);
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(WorkerEngagement::class);
    }

    public function contractRates(): HasMany
    {
        return $this->hasMany(WorkerContractRate::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(WorkerSkill::class, 'worker_profile_skills')
            ->withTimestamps()
            ->orderBy('worker_skills.sort_order')
            ->orderBy('worker_skills.id');
    }

    public function languages(): HasMany
    {
        return $this->hasMany(WorkerProfileLanguage::class)->orderBy('language_code');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(WorkerWorkExperience::class)->orderByDesc('started_on')->orderByDesc('id');
    }

    public function latestVerification(): HasOne
    {
        return $this->hasOne(WorkerVerification::class)->latestOfMany('submitted_at');
    }

    public function isSuspended(): bool
    {
        return $this->lifecycle_status === self::LIFECYCLE_SUSPENDED;
    }

    public function verificationStatusLabel(): string
    {
        return match ($this->verification_status) {
            self::VERIFICATION_NOT_REQUESTED => 'لم يُطلب',
            self::VERIFICATION_DOCUMENTS_REQUIRED => 'مستندات مطلوبة',
            self::VERIFICATION_PENDING_REVIEW => 'قيد المراجعة',
            self::VERIFICATION_APPROVED => 'معتمد',
            self::VERIFICATION_REJECTED => 'مرفوض',
            self::VERIFICATION_SUSPENDED => 'معلّق',
            default => (string) $this->verification_status,
        };
    }

    public function lifecycleStatusLabel(): string
    {
        return match ($this->lifecycle_status) {
            self::LIFECYCLE_REGISTERED => 'مسجّل',
            self::LIFECYCLE_PROFILE_INCOMPLETE => 'الملف غير مكتمل',
            self::LIFECYCLE_VERIFICATION_PENDING => 'التحقق قيد المراجعة',
            self::LIFECYCLE_VERIFIED => 'موثّق',
            self::LIFECYCLE_VERIFICATION_REJECTED => 'التحقق مرفوض',
            self::LIFECYCLE_SUSPENDED => 'معلّق',
            default => (string) $this->lifecycle_status,
        };
    }

    public function availabilityStatusLabel(): string
    {
        return self::availabilityLabel($this->availability_status);
    }

    public static function availabilityLabel(?string $status): string
    {
        return match ($status) {
            self::AVAILABILITY_AVAILABLE => 'متاح',
            self::AVAILABILITY_LIMITED => 'متاح بشكل محدود',
            self::AVAILABILITY_UNAVAILABLE => 'غير متاح',
            default => (string) $status,
        };
    }

    /**
     * Worker-visible verification outcome. Internal review notes stay off this payload.
     *
     * @return array{title: string, next: string, reason: ?string}|null
     */
    public function workerVisibleVerificationNotice(): ?array
    {
        $reason = $this->latestVerification?->rejection_reason;
        $visibleReason = filled($reason) ? $reason : null;

        return match ($this->verification_status) {
            self::VERIFICATION_REJECTED => [
                'title' => 'رُفض التحقق من الحساب.',
                'next' => 'حدّث البيانات المهنية وارفع المستندات ثم أعد إرسال الملف للمراجعة.',
                'reason' => $visibleReason,
            ],
            self::VERIFICATION_DOCUMENTS_REQUIRED => [
                'title' => 'التحقق يحتاج مستندات إضافية.',
                'next' => 'ارفع المستندات المطلوبة ثم أعد إرسال الملف للمراجعة.',
                'reason' => $visibleReason,
            ],
            default => null,
        };
    }

    public function isProfileComplete(): bool
    {
        return filled($this->display_name)
            && in_array($this->country_code, self::COUNTRIES, true)
            && filled($this->nationality)
            && filled($this->professional_summary)
            && filled($this->sales_experience_level)
            && $this->privateIdentity?->isComplete() === true;
    }
}
