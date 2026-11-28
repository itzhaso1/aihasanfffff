<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\CustomerStatus;
use App\Models\Booking;
use App\Models\BookingBranch;
use App\Models\BookingProvider;
use App\Models\BookingReminder;
use App\Models\BookingRevision;
use App\Models\BookingService;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Booking\Exceptions\BookingException;
use App\Services\Customer\CustomerOrderCaptureService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class BookingManagementService
{
    public function __construct(
        private readonly BookingSettingsService $settingsService,
        private readonly BookingAvailabilityService $availability,
        private readonly BookingNotificationService $notifications,
        private readonly CustomerOrderCaptureService $orderCapture,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBooking(
        Workspace $workspace,
        array $data,
        ?Conversation $conversation = null,
        ?User $actor = null,
        bool $allowOverrideRules = false,
        bool $notifyCustomerViaMessage = true,
    ): Booking {
        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $data,
            $conversation,
            $actor,
            $allowOverrideRules,
            $notifyCustomerViaMessage,
        ): Booking {
            $idempotencyKey = isset($data['idempotency_key']) && is_string($data['idempotency_key']) && trim($data['idempotency_key']) !== ''
                ? trim($data['idempotency_key'])
                : null;

            if ($idempotencyKey !== null) {
                $existing = Booking::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->with(['service', 'branch', 'provider', 'customer'])
                    ->first();

                if ($existing instanceof Booking) {
                    return $existing;
                }
            }

            $setting = $this->settingsService->getOrCreate($workspace);
            $service = $this->resolveService($workspace, $data);
            $branch = $this->resolveBranch($workspace, $data);
            $provider = $this->resolveProvider($workspace, $data);

            if (! $allowOverrideRules && $setting->require_branch && $branch === null) {
                throw new BookingException('يرجى تحديد الفرع أو الموقع المطلوب قبل إتمام الحجز.');
            }
            if (! $allowOverrideRules && $setting->require_staff && $provider === null && BookingProvider::withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('is_active', true)->exists() === false) {
                throw new BookingException('لا يوجد مقدمو خدمة مفعلون في المنشأة حالياً.');
            }

            $serviceName = trim((string) ($service?->name ?: ($data['service_name'] ?? '')));
            if ($serviceName === '') {
                throw new BookingException('يرجى تحديد نوع الخدمة أو الحجز المطلوب.');
            }

            $partySize = isset($data['party_size']) && $data['party_size'] !== '' ? max(1, (int) $data['party_size']) : null;
            if (! $allowOverrideRules && ($service?->requires_party_size === true) && $partySize === null) {
                throw new BookingException('يرجى تحديد عدد الأشخاص لهذا النوع من الحجز.');
            }

            $date = trim((string) ($data['booking_date'] ?? $data['date'] ?? ''));
            $startTime = trim((string) ($data['start_time'] ?? ''));
            if ($date === '' || $startTime === '') {
                throw new BookingException('يرجى تحديد تاريخ ووقت الحجز.');
            }

            $customerName = trim((string) ($data['customer_name'] ?? $conversation?->customer?->name ?? 'عميل جديد'));
            if ($customerName === '') {
                $customerName = 'عميل جديد';
            }
            $rawPhone = trim((string) ($data['customer_phone'] ?? $data['phone'] ?? $conversation?->customer?->phone ?? ''));
            $normalizedPhone = $rawPhone !== '' ? $this->orderCapture->normalizePhone($rawPhone) : null;

            $lock = Cache::lock('booking_ws_lock:'.$workspace->id, 15);
            $booking = $lock->block(10, function () use (
                $workspace,
                $setting,
                $data,
                $conversation,
                $actor,
                $allowOverrideRules,
                $service,
                $branch,
                $provider,
                $serviceName,
                $partySize,
                $date,
                $startTime,
                $customerName,
                $normalizedPhone,
                $idempotencyKey,
            ): Booking {
                return DB::transaction(function () use (
                    $workspace,
                    $setting,
                    $data,
                    $conversation,
                    $actor,
                    $allowOverrideRules,
                    $service,
                    $branch,
                    $provider,
                    $serviceName,
                    $partySize,
                    $date,
                    $startTime,
                    $customerName,
                    $normalizedPhone,
                    $idempotencyKey,
                ): Booking {
                    if ($idempotencyKey !== null) {
                        $replay = Booking::query()
                            ->where('workspace_id', $workspace->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();
                        if ($replay instanceof Booking) {
                            return $replay;
                        }
                    }

                    // Lock overlapping booking rows in transaction before capacity check.
                    Booking::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('booking_date', $date)
                        ->whereIn('status', BookingStatus::activeValues())
                        ->lockForUpdate()
                        ->get(['id']);

                    $slotCheck = $this->availability->assertSlotAvailable(
                        workspace: $workspace,
                        date: $date,
                        startTime: $startTime,
                        service: $service,
                        branch: $branch,
                        provider: $provider,
                        partySize: $partySize,
                        ignoreAdvanceRules: $allowOverrideRules,
                        ignoreDisabledSetting: $allowOverrideRules,
                    );

                    $resolvedProviderId = $provider?->id ?? $slotCheck['resolved_provider_id'];
                    $customer = $this->resolveOrCreateCustomer(
                        workspace: $workspace,
                        conversation: $conversation,
                        customerName: $customerName,
                        normalizedPhone: $normalizedPhone,
                    );

                    $requestedStatus = isset($data['status']) && is_string($data['status'])
                        ? BookingStatus::tryFrom(strtolower(trim($data['status'])))
                        : null;
                    $initialStatus = $requestedStatus ?? ($setting->auto_confirm ? BookingStatus::Confirmed : BookingStatus::Pending);

                    $channel = trim((string) ($data['channel'] ?? $conversation?->channel ?? 'manual')) ?: 'manual';
                    $source = trim((string) ($data['source'] ?? ($actor ? 'manual' : 'ai'))) ?: 'manual';
                    $deliveryLocationId = ! empty($data['delivery_location_id'])
                        ? (int) $data['delivery_location_id']
                        : $customer?->primary_location_id;

                    $booking = Booking::query()->create([
                        'workspace_id' => $workspace->id,
                        'booking_reference' => $this->nextReference($workspace),
                        'customer_id' => $customer?->id,
                        'conversation_id' => $conversation?->id,
                        'service_id' => $service?->id,
                        'branch_id' => $branch?->id ?? ($resolvedProviderId ? BookingProvider::query()->whereKey($resolvedProviderId)->value('branch_id') : null),
                        'provider_id' => $resolvedProviderId,
                        'delivery_location_id' => $deliveryLocationId,
                        'service_name' => $serviceName,
                        'customer_name' => $customerName,
                        'customer_phone' => $normalizedPhone,
                        'booking_date' => $date,
                        'start_time' => $slotCheck['starts_at_utc']->setTimezone($setting->timezone ?: 'Asia/Riyadh')->format('H:i'),
                        'end_time' => $slotCheck['end_time'],
                        'starts_at' => $slotCheck['starts_at_utc'],
                        'ends_at' => $slotCheck['ends_at_utc'],
                        'duration_minutes' => $slotCheck['duration_minutes'],
                        'party_size' => $partySize,
                        'status' => $initialStatus->value,
                        'source' => $source,
                        'channel' => $channel,
                        'customer_notes' => isset($data['customer_notes']) && trim((string) $data['customer_notes']) !== '' ? trim((string) $data['customer_notes']) : null,
                        'internal_notes' => isset($data['internal_notes']) && trim((string) $data['internal_notes']) !== '' ? trim((string) $data['internal_notes']) : null,
                        'custom_fields' => $this->sanitizeCustomFields($data['custom_fields'] ?? null),
                        'source_message_id' => ! empty($data['source_message_id']) ? (int) $data['source_message_id'] : null,
                        'idempotency_key' => $idempotencyKey,
                        'confirmed_at' => $initialStatus === BookingStatus::Confirmed ? now() : null,
                        'created_by_user_id' => $actor?->id,
                        'updated_by_user_id' => $actor?->id,
                        'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
                    ]);

                    $this->recordRevision(
                        booking: $booking,
                        eventType: 'created',
                        actor: $actor,
                        source: $source,
                        newStatus: $booking->status,
                        newStartsAt: $booking->starts_at,
                        payload: ['service_name' => $booking->service_name, 'channel' => $booking->channel],
                    );

                    $this->notifications->syncUpcomingReminders($booking, $setting);

                    return $booking->fresh(['service', 'branch', 'provider', 'customer', 'conversation']) ?? $booking;
                });
            });

            $this->notifications->notifyWorkspaceTeam($booking, 'created');
            $this->notifications->sendEventMessageToCustomer(
                $booking,
                BookingReminder::TYPE_CONFIRMATION,
                $actor,
                $notifyCustomerViaMessage,
            );

            return $booking;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function rescheduleBooking(
        Workspace $workspace,
        Booking $booking,
        string $newDate,
        string $newStartTime,
        array $data = [],
        ?User $actor = null,
        bool $allowOverrideRules = false,
        bool $notifyCustomerViaMessage = true,
    ): Booking {
        if ((int) $booking->workspace_id !== (int) $workspace->id) {
            throw new BookingException('الحجز غير موجود في هذه المنشأة.');
        }

        if ($booking->statusEnum()->isTerminal()) {
            throw new BookingException('لا يمكن تأجيل حجز بحالة '.$booking->statusLabelAr().'.');
        }

        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $booking,
            $newDate,
            $newStartTime,
            $data,
            $actor,
            $allowOverrideRules,
            $notifyCustomerViaMessage,
        ): Booking {
            $setting = $this->settingsService->getOrCreate($workspace);
            $service = array_key_exists('service_id', $data) || array_key_exists('service_name', $data)
                ? $this->resolveService($workspace, $data) ?? $booking->service
                : $booking->service;
            $branch = array_key_exists('branch_id', $data) || array_key_exists('branch_name', $data)
                ? $this->resolveBranch($workspace, $data)
                : $booking->branch;
            $provider = array_key_exists('provider_id', $data) || array_key_exists('provider_name', $data)
                ? $this->resolveProvider($workspace, $data)
                : $booking->provider;

            $partySize = array_key_exists('party_size', $data)
                ? ($data['party_size'] !== null && $data['party_size'] !== '' ? max(1, (int) $data['party_size']) : null)
                : $booking->party_size;

            $lock = Cache::lock('booking_ws_lock:'.$workspace->id, 15);
            $updated = $lock->block(10, function () use (
                $workspace,
                $booking,
                $setting,
                $newDate,
                $newStartTime,
                $data,
                $actor,
                $allowOverrideRules,
                $service,
                $branch,
                $provider,
                $partySize,
            ): Booking {
                return DB::transaction(function () use (
                    $workspace,
                    $booking,
                    $setting,
                    $newDate,
                    $newStartTime,
                    $data,
                    $actor,
                    $allowOverrideRules,
                    $service,
                    $branch,
                    $provider,
                    $partySize,
                ): Booking {
                    /** @var Booking $locked */
                    $locked = Booking::query()
                        ->where('workspace_id', $workspace->id)
                        ->whereKey($booking->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    Booking::query()
                        ->where('workspace_id', $workspace->id)
                        ->where('booking_date', $newDate)
                        ->whereIn('status', BookingStatus::activeValues())
                        ->lockForUpdate()
                        ->get(['id']);

                    $slotCheck = $this->availability->assertSlotAvailable(
                        workspace: $workspace,
                        date: $newDate,
                        startTime: $newStartTime,
                        service: $service,
                        branch: $branch,
                        provider: $provider,
                        partySize: $partySize,
                        excludeBookingId: $locked->id,
                        ignoreAdvanceRules: $allowOverrideRules,
                        ignoreDisabledSetting: $allowOverrideRules,
                    );

                    $prevStatus = $locked->status;
                    $prevStartsAt = $locked->starts_at;
                    $timezone = $setting->timezone ?: 'Asia/Riyadh';

                    $locked->fill([
                        'service_id' => $service?->id ?? $locked->service_id,
                        'service_name' => $service?->name ?? $locked->service_name,
                        'branch_id' => $branch?->id ?? $locked->branch_id,
                        'provider_id' => $provider?->id ?? $slotCheck['resolved_provider_id'],
                        'party_size' => $partySize,
                        'booking_date' => $newDate,
                        'start_time' => $slotCheck['starts_at_utc']->setTimezone($timezone)->format('H:i'),
                        'end_time' => $slotCheck['end_time'],
                        'starts_at' => $slotCheck['starts_at_utc'],
                        'ends_at' => $slotCheck['ends_at_utc'],
                        'duration_minutes' => $slotCheck['duration_minutes'],
                        'previous_starts_at' => $prevStartsAt,
                        'reschedule_count' => (int) $locked->reschedule_count + 1,
                        'status' => BookingStatus::Rescheduled->value,
                        'rescheduled_at' => now(),
                        'updated_by_user_id' => $actor?->id,
                    ]);
                    if (isset($data['customer_notes']) && is_string($data['customer_notes'])) {
                        $locked->customer_notes = trim($data['customer_notes']) !== '' ? trim($data['customer_notes']) : null;
                    }
                    $locked->save();

                    $this->recordRevision(
                        booking: $locked,
                        eventType: 'rescheduled',
                        actor: $actor,
                        source: $actor ? 'manual' : 'ai',
                        previousStatus: $prevStatus,
                        newStatus: $locked->status,
                        previousStartsAt: $prevStartsAt,
                        newStartsAt: $locked->starts_at,
                        payload: ['reason' => $data['reason'] ?? null],
                    );

                    $this->notifications->syncUpcomingReminders($locked, $setting);

                    return $locked->fresh(['service', 'branch', 'provider', 'customer', 'conversation']) ?? $locked;
                });
            });

            $this->notifications->notifyWorkspaceTeam($updated, 'rescheduled');
            $this->notifications->sendEventMessageToCustomer(
                $updated,
                BookingReminder::TYPE_RESCHEDULED,
                $actor,
                $notifyCustomerViaMessage,
            );

            return $updated;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBookingDetails(
        Workspace $workspace,
        Booking $booking,
        array $data,
        ?User $actor = null,
        bool $allowOverrideRules = false,
        bool $notifyCustomerViaMessage = true,
    ): Booking {
        if ((int) $booking->workspace_id !== (int) $workspace->id) {
            throw new BookingException('الحجز غير موجود في هذه المنشأة.');
        }

        if ($booking->statusEnum()->isTerminal() && ! $allowOverrideRules) {
            throw new BookingException('لا يمكن تعديل حجز بحالة '.$booking->statusLabelAr().'.');
        }

        if (! empty($data['booking_date']) || ! empty($data['date']) || ! empty($data['start_time'])) {
            $targetDate = trim((string) ($data['booking_date'] ?? $data['date'] ?? $booking->booking_date?->format('Y-m-d')));
            $targetTime = trim((string) ($data['start_time'] ?? $booking->start_time));

            if ($targetDate !== $booking->booking_date?->format('Y-m-d') || $targetTime !== $booking->start_time) {
                return $this->rescheduleBooking(
                    workspace: $workspace,
                    booking: $booking,
                    newDate: $targetDate,
                    newStartTime: $targetTime,
                    data: $data,
                    actor: $actor,
                    allowOverrideRules: $allowOverrideRules,
                    notifyCustomerViaMessage: $notifyCustomerViaMessage,
                );
            }
        }

        return $this->workspaceContext->runFor($workspace, function () use (
            $workspace,
            $booking,
            $data,
            $actor,
            $allowOverrideRules,
            $notifyCustomerViaMessage,
        ): Booking {
            $service = array_key_exists('service_id', $data) || array_key_exists('service_name', $data)
                ? $this->resolveService($workspace, $data)
                : $booking->service;
            $branch = array_key_exists('branch_id', $data) || array_key_exists('branch_name', $data)
                ? $this->resolveBranch($workspace, $data)
                : $booking->branch;
            $provider = array_key_exists('provider_id', $data) || array_key_exists('provider_name', $data)
                ? $this->resolveProvider($workspace, $data)
                : $booking->provider;

            $updated = DB::transaction(function () use ($workspace, $booking, $data, $actor, $allowOverrideRules, $service, $branch, $provider): Booking {
                $locked = Booking::query()->where('workspace_id', $workspace->id)->whereKey($booking->id)->lockForUpdate()->firstOrFail();

                $partySize = array_key_exists('party_size', $data)
                    ? ($data['party_size'] !== null && $data['party_size'] !== '' ? max(1, (int) $data['party_size']) : null)
                    : $locked->party_size;

                $recheckRequired = ($service?->id !== $locked->service_id)
                    || ($branch?->id !== $locked->branch_id)
                    || ($provider?->id !== $locked->provider_id)
                    || ($partySize !== $locked->party_size);

                $newEnd = $locked->end_time;
                $newEndsAt = $locked->ends_at;
                $newDuration = $locked->duration_minutes;
                $resolvedProviderId = $provider?->id;

                if ($recheckRequired && $locked->statusEnum()->isOccupyingCapacity()) {
                    $slotCheck = $this->availability->assertSlotAvailable(
                        workspace: $workspace,
                        date: $locked->booking_date?->format('Y-m-d') ?? '',
                        startTime: $locked->start_time,
                        service: $service,
                        branch: $branch,
                        provider: $provider,
                        partySize: $partySize,
                        excludeBookingId: $locked->id,
                        ignoreAdvanceRules: $allowOverrideRules,
                        ignoreDisabledSetting: $allowOverrideRules,
                    );
                    $newEnd = $slotCheck['end_time'];
                    $newEndsAt = $slotCheck['ends_at_utc'];
                    $newDuration = $slotCheck['duration_minutes'];
                    $resolvedProviderId = $provider?->id ?? $slotCheck['resolved_provider_id'];
                }

                $phone = array_key_exists('customer_phone', $data) || array_key_exists('phone', $data)
                    ? (trim((string) ($data['customer_phone'] ?? $data['phone'] ?? '')) !== ''
                        ? $this->orderCapture->normalizePhone((string) ($data['customer_phone'] ?? $data['phone']))
                        : null)
                    : $locked->customer_phone;

                $mergedCustom = $locked->custom_fields ?? [];
                if (is_array($data['custom_fields'] ?? null)) {
                    $mergedCustom = array_merge($mergedCustom, $this->sanitizeCustomFields($data['custom_fields']));
                }

                $locked->fill([
                    'customer_name' => isset($data['customer_name']) && trim((string) $data['customer_name']) !== '' ? trim((string) $data['customer_name']) : $locked->customer_name,
                    'customer_phone' => $phone,
                    'service_id' => $service?->id,
                    'service_name' => $service?->name ?? (isset($data['service_name']) && trim((string) $data['service_name']) !== '' ? trim((string) $data['service_name']) : $locked->service_name),
                    'branch_id' => $branch?->id,
                    'provider_id' => $resolvedProviderId,
                    'party_size' => $partySize,
                    'end_time' => $newEnd,
                    'ends_at' => $newEndsAt,
                    'duration_minutes' => $newDuration,
                    'customer_notes' => array_key_exists('customer_notes', $data) || array_key_exists('notes', $data)
                        ? (trim((string) ($data['customer_notes'] ?? $data['notes'] ?? '')) !== '' ? trim((string) ($data['customer_notes'] ?? $data['notes'])) : null)
                        : $locked->customer_notes,
                    'internal_notes' => array_key_exists('internal_notes', $data)
                        ? (trim((string) ($data['internal_notes'] ?? '')) !== '' ? trim((string) $data['internal_notes']) : null)
                        : $locked->internal_notes,
                    'custom_fields' => $mergedCustom,
                    'updated_by_user_id' => $actor?->id,
                ]);
                $locked->save();

                $this->recordRevision(
                    booking: $locked,
                    eventType: 'updated',
                    actor: $actor,
                    source: $actor ? 'manual' : 'ai',
                    previousStatus: $locked->status,
                    newStatus: $locked->status,
                    payload: array_intersect_key($data, array_flip(['service_name', 'customer_name', 'customer_phone', 'party_size', 'customer_notes'])),
                );

                return $locked->fresh(['service', 'branch', 'provider', 'customer', 'conversation']) ?? $locked;
            });

            $this->notifications->notifyWorkspaceTeam($updated, 'updated');
            $this->notifications->sendEventMessageToCustomer(
                $updated,
                BookingReminder::TYPE_UPDATED,
                $actor,
                $notifyCustomerViaMessage,
            );

            return $updated;
        });
    }

    public function transitionStatus(
        Workspace $workspace,
        Booking $booking,
        BookingStatus $targetStatus,
        ?string $reason = null,
        ?User $actor = null,
        bool $notifyCustomerViaMessage = true,
    ): Booking {
        if ((int) $booking->workspace_id !== (int) $workspace->id) {
            throw new BookingException('الحجز غير موجود في هذه المنشأة.');
        }

        $setting = $this->settingsService->getOrCreate($workspace);

        $updated = $this->workspaceContext->runFor($workspace, function () use ($workspace, $booking, $targetStatus, $reason, $actor, $setting): Booking {
            return DB::transaction(function () use ($workspace, $booking, $targetStatus, $reason, $actor, $setting): Booking {
                $locked = Booking::query()->where('workspace_id', $workspace->id)->whereKey($booking->id)->lockForUpdate()->firstOrFail();
                $prevStatus = $locked->status;

                if ($targetStatus === BookingStatus::Confirmed && ! $locked->statusEnum()->isOccupyingCapacity()) {
                    $this->availability->assertSlotAvailable(
                        workspace: $workspace,
                        date: $locked->booking_date?->format('Y-m-d') ?? '',
                        startTime: $locked->start_time,
                        service: $locked->service,
                        branch: $locked->branch,
                        provider: $locked->provider,
                        partySize: $locked->party_size,
                        excludeBookingId: $locked->id,
                        ignoreAdvanceRules: true,
                        ignoreDisabledSetting: true,
                    );
                }

                $updates = [
                    'status' => $targetStatus->value,
                    'updated_by_user_id' => $actor?->id,
                ];
                if ($targetStatus === BookingStatus::Confirmed) {
                    $updates['confirmed_at'] = $locked->confirmed_at ?? now();
                } elseif ($targetStatus === BookingStatus::Cancelled) {
                    $updates['cancelled_at'] = now();
                    $updates['cancellation_reason'] = $reason !== null && trim($reason) !== '' ? trim($reason) : $locked->cancellation_reason;
                } elseif ($targetStatus === BookingStatus::Completed) {
                    $updates['completed_at'] = now();
                } elseif ($targetStatus === BookingStatus::NoShow) {
                    $updates['no_show_at'] = now();
                }

                $locked->forceFill($updates)->save();

                if ($targetStatus->isTerminal()) {
                    $this->notifications->cancelPendingUpcomingReminders($locked);
                } else {
                    $this->notifications->syncUpcomingReminders($locked, $setting);
                }

                $this->recordRevision(
                    booking: $locked,
                    eventType: $targetStatus->value,
                    actor: $actor,
                    source: $actor ? 'manual' : 'ai',
                    previousStatus: $prevStatus,
                    newStatus: $targetStatus->value,
                    payload: $reason ? ['reason' => $reason] : [],
                );

                return $locked->fresh(['service', 'branch', 'provider', 'customer', 'conversation']) ?? $locked;
            });
        });

        if (in_array($targetStatus, [BookingStatus::Confirmed, BookingStatus::Cancelled], true)) {
            $notifyType = $targetStatus === BookingStatus::Confirmed
                ? BookingReminder::TYPE_CONFIRMATION
                : BookingReminder::TYPE_CANCELLED;

            $this->notifications->notifyWorkspaceTeam($updated, $targetStatus->value);
            $this->notifications->sendEventMessageToCustomer(
                $updated,
                $notifyType,
                $actor,
                $notifyCustomerViaMessage,
            );
        }

        return $updated;
    }

    /**
     * Strictly scoped booking lookup for the active conversation/customer.
     *
     * @return Collection<int, Booking>
     */
    public function customerBookings(
        Workspace $workspace,
        Conversation $conversation,
        bool $includePast = true,
        int $limit = 10,
    ): Collection {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $conversation, $includePast, $limit): Collection {
            return Booking::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where(function ($query) use ($conversation): void {
                    $query->where('conversation_id', $conversation->id);
                    if ($conversation->customer_id !== null) {
                        $query->orWhere('customer_id', $conversation->customer_id);
                    }
                })
                ->when(! $includePast, fn ($q) => $q->whereIn('status', BookingStatus::activeValues()))
                ->with(['service', 'branch', 'provider'])
                ->orderByDesc('starts_at')
                ->limit($limit)
                ->get();
        });
    }

    public function findCustomerBookingByReferenceOrSingleActive(
        Workspace $workspace,
        Conversation $conversation,
        ?string $bookingReference = null,
    ): ?Booking {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace, $conversation, $bookingReference): ?Booking {
            $ref = $bookingReference !== null ? strtoupper(ltrim(trim($bookingReference), '#')) : null;
            if ($ref !== null && $ref !== '') {
                return Booking::withoutGlobalScopes()
                    ->where('workspace_id', $workspace->id)
                    ->where(function ($query) use ($conversation): void {
                        $query->where('conversation_id', $conversation->id);
                        if ($conversation->customer_id !== null) {
                            $query->orWhere('customer_id', $conversation->customer_id);
                        }
                    })
                    ->where('booking_reference', $ref)
                    ->with(['service', 'branch', 'provider', 'customer', 'conversation'])
                    ->first();
            }

            $active = Booking::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where(function ($query) use ($conversation): void {
                    $query->where('conversation_id', $conversation->id);
                    if ($conversation->customer_id !== null) {
                        $query->orWhere('customer_id', $conversation->customer_id);
                    }
                })
                ->whereIn('status', BookingStatus::activeValues())
                ->with(['service', 'branch', 'provider', 'customer', 'conversation'])
                ->orderBy('starts_at')
                ->get();

            if ($active->count() === 1) {
                return $active->first();
            }

            if ($active->count() > 1) {
                $refs = $active->pluck('booking_reference')->implode('، ');
                throw new BookingException('يوجد أكثر من حجز نشط للعميل ('.$refs.'). يرجى تحديد رقم الحجز المرجعي المطلوب.');
            }

            return null;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolveService(Workspace $workspace, array $data): ?BookingService
    {
        if (! empty($data['service_id'])) {
            $service = BookingService::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->find((int) $data['service_id']);
            if ($service instanceof BookingService) {
                return $service;
            }
        }

        $name = isset($data['service_name']) && is_string($data['service_name']) ? trim($data['service_name']) : '';
        if ($name !== '') {
            $exact = BookingService::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();
            if ($exact instanceof BookingService) {
                return $exact;
            }

            $like = BookingService::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->where('name', 'like', '%'.$name.'%')
                ->first();
            if ($like instanceof BookingService) {
                return $like;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolveBranch(Workspace $workspace, array $data): ?BookingBranch
    {
        if (! empty($data['branch_id'])) {
            $branch = BookingBranch::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->find((int) $data['branch_id']);
            if ($branch instanceof BookingBranch) {
                return $branch;
            }
        }

        $name = isset($data['branch_name']) && is_string($data['branch_name']) ? trim($data['branch_name']) : '';
        if ($name !== '') {
            return BookingBranch::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->where(function ($q) use ($name): void {
                    $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                        ->orWhere('name', 'like', '%'.$name.'%');
                })
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolveProvider(Workspace $workspace, array $data): ?BookingProvider
    {
        if (! empty($data['provider_id'])) {
            $provider = BookingProvider::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->find((int) $data['provider_id']);
            if ($provider instanceof BookingProvider) {
                return $provider;
            }
        }

        $name = isset($data['provider_name']) && is_string($data['provider_name']) ? trim($data['provider_name']) : '';
        if ($name !== '') {
            return BookingProvider::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->where('is_active', true)
                ->where(function ($q) use ($name): void {
                    $q->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                        ->orWhere('name', 'like', '%'.$name.'%');
                })
                ->first();
        }

        return null;
    }

    private function resolveOrCreateCustomer(
        Workspace $workspace,
        ?Conversation $conversation,
        string $customerName,
        ?string $normalizedPhone,
    ): ?Customer {
        $customer = $conversation?->customer_id
            ? Customer::query()->where('workspace_id', $workspace->id)->find($conversation->customer_id)
            : null;

        if (! $customer instanceof Customer && $normalizedPhone !== null && $normalizedPhone !== '') {
            $customer = Customer::query()
                ->where('workspace_id', $workspace->id)
                ->where('phone', $normalizedPhone)
                ->first();
        }

        if ($customer instanceof Customer) {
            $updates = [];
            if ($customerName !== '' && $customerName !== 'عميل جديد' && in_array(trim((string) $customer->name), ['', 'عميل جديد'], true)) {
                $updates['name'] = $customerName;
            }
            if ($normalizedPhone !== null && trim((string) $customer->phone) === '') {
                $updates['phone'] = $normalizedPhone;
            }
            if ($updates !== []) {
                $customer->forceFill($updates)->save();
            }

            return $customer;
        }

        return Customer::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $customerName,
            'phone' => $normalizedPhone,
            'whatsapp' => $conversation?->channel === 'whatsapp' ? $normalizedPhone : null,
            'status' => CustomerStatus::InProgress->value,
            'metadata' => ['source' => 'booking'],
        ]);
    }

    private function nextReference(Workspace $workspace): string
    {
        $maxId = (int) Booking::withoutGlobalScopes()
            ->withTrashed()
            ->where('workspace_id', $workspace->id)
            ->count();

        $sequence = $maxId + 1;
        do {
            $candidate = 'BK-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
            $exists = Booking::withoutGlobalScopes()
                ->withTrashed()
                ->where('workspace_id', $workspace->id)
                ->where('booking_reference', $candidate)
                ->exists();
            $sequence++;
        } while ($exists);

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordRevision(
        Booking $booking,
        string $eventType,
        ?User $actor = null,
        string $source = 'system',
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?\DateTimeInterface $previousStartsAt = null,
        ?\DateTimeInterface $newStartsAt = null,
        array $payload = [],
    ): void {
        BookingRevision::query()->create([
            'workspace_id' => $booking->workspace_id,
            'booking_id' => $booking->id,
            'event_type' => $eventType,
            'actor_type' => $actor ? 'user' : ($source === 'ai' ? 'ai' : 'system'),
            'actor_id' => $actor?->id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'previous_starts_at' => $previousStartsAt,
            'new_starts_at' => $newStartsAt,
            'payload' => $payload,
            'created_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeCustomFields(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key) || trim($key) === '' || is_array($value) || is_object($value)) {
                continue;
            }
            $clean[trim($key)] = is_string($value) ? trim($value) : $value;
        }

        return $clean;
    }
}
