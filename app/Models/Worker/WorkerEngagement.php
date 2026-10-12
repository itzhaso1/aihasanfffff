<?php

declare(strict_types=1);

namespace App\Models\Worker;

use App\Enums\WorkerEngagementDurationUnit;
use App\Enums\WorkerEngagementStatus;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkspaceScopedModel;
use App\Services\Worker\WorkerEngagementException;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'worker_profile_id',
    'workspace_id',
    'status',
    'job_title',
    'job_description',
    'starts_at',
    'ends_at',
    'duration_value',
    'duration_unit',
    'compensation_terms_json',
    'offered_contract_amount_minor',
    'offered_contract_currency',
    'offered_contract_rate_id',
    'agreed_contract_amount_minor',
    'agreed_contract_currency',
    'agreed_contract_at',
    'created_by',
    'offered_at',
    'accepted_at',
    'rejected_at',
    'active_at',
    'paused_at',
    'completed_at',
    'terminated_at',
    'termination_reason',
])]
class WorkerEngagement extends WorkspaceScopedModel
{
    use BelongsToWorkspace;

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (self $engagement): void {
            if (! $engagement->isDirty('status')) {
                return;
            }

            $current = WorkerEngagementStatus::tryFrom((string) $engagement->getRawOriginal('status'));
            $next = $engagement->getAttribute('status');

            if ($current === null) {
                throw WorkerEngagementException::invalidStoredStatus(
                    (string) $engagement->getRawOriginal('status'),
                );
            }

            if (! $next instanceof WorkerEngagementStatus || ! $current->canTransitionTo($next)) {
                throw WorkerEngagementException::invalidTransition($current, $next);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'status' => WorkerEngagementStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'duration_value' => 'integer',
            'duration_unit' => WorkerEngagementDurationUnit::class,
            'compensation_terms_json' => 'array',
            'offered_contract_amount_minor' => 'integer',
            'offered_contract_rate_id' => 'integer',
            'agreed_contract_amount_minor' => 'integer',
            'agreed_contract_at' => 'datetime',
            'offered_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'active_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'terminated_at' => 'datetime',
        ];
    }

    public function workerProfile(): BelongsTo
    {
        return $this->belongsTo(WorkerProfile::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function offeredContractRate(): BelongsTo
    {
        return $this->belongsTo(WorkerContractRate::class, 'offered_contract_rate_id');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(WorkerEngagementAcceptance::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(WorkerEngagementPermission::class, 'worker_engagement_id');
    }

    public function assignedProducts(): HasMany
    {
        return $this->hasMany(WorkerEngagementProduct::class, 'worker_engagement_id');
    }

    public function guide(): HasOne
    {
        return $this->hasOne(WorkerEngagementGuide::class, 'worker_engagement_id')
            ->withoutGlobalScopes();
    }

    public function workSessions(): HasMany
    {
        return $this->hasMany(WorkerWorkSession::class, 'worker_engagement_id');
    }

    public function activityEvents(): HasMany
    {
        return $this->hasMany(WorkerActivityEvent::class, 'worker_engagement_id');
    }

    public function earningPeriods(): HasMany
    {
        return $this->hasMany(WorkerEarningPeriod::class, 'worker_engagement_id');
    }

    public function earningLines(): HasMany
    {
        return $this->hasMany(WorkerEarningLine::class, 'worker_engagement_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(WorkerPayoutRecord::class, 'worker_engagement_id');
    }

    public function assignedConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'assigned_worker_engagement_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'worker_engagement_id');
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(WorkerDispute::class, 'worker_engagement_id');
    }

    public function contractDurationLabel(): ?string
    {
        if ($this->duration_value === null || $this->duration_value < 1 || $this->duration_unit === null) {
            return null;
        }

        $unit = $this->duration_unit instanceof WorkerEngagementDurationUnit
            ? $this->duration_unit
            : WorkerEngagementDurationUnit::tryFrom((string) $this->duration_unit);

        return $unit?->labelFor((int) $this->duration_value);
    }

    public function contractPriceLabel(): ?string
    {
        $amountMinor = $this->agreed_contract_amount_minor ?? $this->offered_contract_amount_minor;
        $currency = $this->agreed_contract_currency ?? $this->offered_contract_currency;

        if ($amountMinor === null || $currency === null || trim((string) $currency) === '') {
            return null;
        }

        return Money::fromMinor((int) $amountMinor).' '.$currency;
    }

    public function transitionTo(WorkerEngagementStatus $next): void
    {
        $current = $this->status;

        if (! $current instanceof WorkerEngagementStatus) {
            throw WorkerEngagementException::invalidStoredStatus(
                (string) $this->getRawOriginal('status'),
            );
        }

        if (! $current->canTransitionTo($next)) {
            throw WorkerEngagementException::invalidTransition($current, $next);
        }

        $now = now();
        $attributes = ['status' => $next];

        match ($next) {
            WorkerEngagementStatus::Offered => $attributes['offered_at'] = $this->offered_at ?? $now,
            WorkerEngagementStatus::Accepted => $attributes['accepted_at'] = $now,
            WorkerEngagementStatus::Rejected => $attributes['rejected_at'] = $now,
            WorkerEngagementStatus::Active => $attributes['active_at'] = $this->active_at ?? $now,
            WorkerEngagementStatus::Paused => $attributes['paused_at'] = $now,
            WorkerEngagementStatus::Completed => $attributes['completed_at'] = $now,
            WorkerEngagementStatus::Terminated => $attributes['terminated_at'] = $now,
            WorkerEngagementStatus::Draft => null,
        };

        $this->forceFill($attributes)->save();
    }
}
