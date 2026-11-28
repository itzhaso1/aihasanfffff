<?php

declare(strict_types=1);

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingBlockedSlot;
use App\Models\BookingBranch;
use App\Models\BookingProvider;
use App\Models\BookingService;
use App\Models\BookingSetting;
use App\Models\Workspace;
use App\Services\Booking\Exceptions\BookingUnavailableException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class BookingAvailabilityService
{
    public function __construct(
        private readonly BookingSettingsService $settingsService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function availableSlotsForDate(
        Workspace $workspace,
        CarbonImmutable|string $date,
        ?BookingService $service = null,
        ?BookingBranch $branch = null,
        ?BookingProvider $provider = null,
        ?int $partySize = null,
        ?int $excludeBookingId = null,
        bool $ignoreAdvanceRules = false,
        int $limit = 24,
    ): array {
        $setting = $this->settingsService->getOrCreate($workspace);
        if (! $setting->enabled) {
            return [];
        }

        $timezone = $setting->timezone ?: 'Asia/Riyadh';
        $localDay = $date instanceof CarbonImmutable
            ? $date->setTimezone($timezone)->startOfDay()
            : CarbonImmutable::parse((string) $date, $timezone)->startOfDay();

        $dayEval = $this->evaluateDayOpen($setting, $localDay, $ignoreAdvanceRules);
        if (! $dayEval['open']) {
            return [];
        }

        $duration = $service ? $service->effectiveDurationMinutes($setting) : max(5, (int) $setting->default_duration_minutes);
        $interval = max(5, (int) $setting->slot_interval_minutes);
        $buffer = max(0, (int) $setting->buffer_minutes);

        if ($partySize !== null && $service?->max_party_size !== null && $partySize > (int) $service->max_party_size) {
            return [];
        }

        $providers = $this->compatibleProviders($workspace, $service, $branch, $provider);
        if ($provider !== null && $providers->isEmpty()) {
            return [];
        }

        $effectiveWindow = $this->resolveWorkingWindow($setting, $localDay, $branch, $provider);
        if ($effectiveWindow === null) {
            return [];
        }

        $dayStartLocal = $localDay->setTimeFromTimeString($effectiveWindow['start']);
        $dayEndLocal = $localDay->setTimeFromTimeString($effectiveWindow['end']);
        if ($dayEndLocal->lessThanOrEqualTo($dayStartLocal)) {
            return [];
        }

        $dayStartUtc = $dayStartLocal->copy()->subMinutes($buffer)->utc();
        $dayEndUtc = $dayEndLocal->copy()->addMinutes($buffer)->utc();

        $activeBookings = $this->activeBookingsInWindow($workspace, $dayStartUtc, $dayEndUtc, $branch, $excludeBookingId);
        $blockedSlots = $this->blockedSlotsInWindow($workspace, $dayStartUtc, $dayEndUtc, $branch, $service);

        $nowLocal = CarbonImmutable::now($timezone);
        $minAllowedLocal = $ignoreAdvanceRules
            ? CarbonImmutable::createFromTimestamp(0, $timezone)
            : $nowLocal->addMinutes(max(0, (int) $setting->min_advance_minutes));

        $slots = [];
        $cursor = $dayStartLocal;

        while ($cursor->addMinutes($duration)->lessThanOrEqualTo($dayEndLocal)) {
            $slotEndLocal = $cursor->addMinutes($duration);

            if ($cursor->greaterThanOrEqualTo($minAllowedLocal)) {
                $evaluation = $this->evaluateCandidateSlot(
                    setting: $setting,
                    slotStartLocal: $cursor,
                    slotEndLocal: $slotEndLocal,
                    service: $service,
                    branch: $branch,
                    specificProvider: $provider,
                    compatibleProviders: $providers,
                    breaks: $effectiveWindow['breaks'],
                    activeBookings: $activeBookings,
                    blockedSlots: $blockedSlots,
                );

                if ($evaluation['available']) {
                    $slots[] = [
                        'date' => $localDay->format('Y-m-d'),
                        'start_time' => $cursor->format('H:i'),
                        'end_time' => $slotEndLocal->format('H:i'),
                        'starts_at' => $cursor->utc()->toIso8601String(),
                        'ends_at' => $slotEndLocal->utc()->toIso8601String(),
                        'duration_minutes' => $duration,
                        'remaining_capacity' => $evaluation['remaining_capacity'],
                        'service_id' => $service?->id,
                        'service_name' => $service?->name,
                        'branch_id' => $branch?->id,
                        'branch_name' => $branch?->name,
                        'provider_id' => $evaluation['resolved_provider_id'],
                        'provider_name' => $evaluation['resolved_provider_name'],
                    ];

                    if (count($slots) >= $limit) {
                        break;
                    }
                }
            }

            $cursor = $cursor->addMinutes($interval);
        }

        return $slots;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findNearestAvailableSlots(
        Workspace $workspace,
        CarbonImmutable|string|null $startDate = null,
        ?BookingService $service = null,
        ?BookingBranch $branch = null,
        ?BookingProvider $provider = null,
        ?int $partySize = null,
        ?int $excludeBookingId = null,
        int $limit = 6,
        int $searchDays = 14,
    ): array {
        $setting = $this->settingsService->getOrCreate($workspace);
        if (! $setting->enabled) {
            return [];
        }

        $timezone = $setting->timezone ?: 'Asia/Riyadh';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $startDate !== null
            ? ($startDate instanceof CarbonImmutable ? $startDate->setTimezone($timezone)->startOfDay() : CarbonImmutable::parse((string) $startDate, $timezone)->startOfDay())
            : $today;

        if ($from->lessThan($today)) {
            $from = $today;
        }

        $maxDays = min($searchDays, max(1, (int) $setting->max_advance_days));
        $collected = [];

        for ($offset = 0; $offset <= $maxDays && count($collected) < $limit; $offset++) {
            $day = $from->addDays($offset);
            $daySlots = $this->availableSlotsForDate(
                workspace: $workspace,
                date: $day,
                service: $service,
                branch: $branch,
                provider: $provider,
                partySize: $partySize,
                excludeBookingId: $excludeBookingId,
                limit: $limit - count($collected),
            );
            array_push($collected, ...$daySlots);
        }

        return array_slice($collected, 0, $limit);
    }

    /**
     * @return array{
     *   available:bool,
     *   reason_code:?string,
     *   reason:?string,
     *   starts_at_utc:CarbonImmutable,
     *   ends_at_utc:CarbonImmutable,
     *   duration_minutes:int,
     *   end_time:string,
     *   remaining_capacity:int,
     *   resolved_provider_id:?int
     * }
     */
    public function checkSlot(
        Workspace $workspace,
        string $date,
        string $startTime,
        ?BookingService $service = null,
        ?BookingBranch $branch = null,
        ?BookingProvider $provider = null,
        ?int $partySize = null,
        ?int $excludeBookingId = null,
        bool $ignoreAdvanceRules = false,
        bool $ignoreDisabledSetting = false,
    ): array {
        $setting = $this->settingsService->getOrCreate($workspace);
        $timezone = $setting->timezone ?: 'Asia/Riyadh';
        $slotStartLocal = CarbonImmutable::parse(trim($date).' '.trim($startTime), $timezone);
        $duration = $service ? $service->effectiveDurationMinutes($setting) : max(5, (int) $setting->default_duration_minutes);
        $slotEndLocal = $slotStartLocal->addMinutes($duration);
        $startsAtUtc = $slotStartLocal->utc();
        $endsAtUtc = $slotEndLocal->utc();

        $fail = static fn (string $code, string $message): array => [
            'available' => false,
            'reason_code' => $code,
            'reason' => $message,
            'starts_at_utc' => $startsAtUtc,
            'ends_at_utc' => $endsAtUtc,
            'duration_minutes' => $duration,
            'end_time' => $slotEndLocal->format('H:i'),
            'remaining_capacity' => 0,
            'resolved_provider_id' => null,
        ];

        if (! $ignoreDisabledSetting && ! $setting->enabled) {
            return $fail('booking_disabled', 'نظام الحجوزات معطّل حالياً لدى المنشأة.');
        }

        if ($partySize !== null && $partySize < 1) {
            return $fail('invalid_party_size', 'عدد الأشخاص يجب أن يكون 1 على الأقل.');
        }

        if ($partySize !== null && $service?->max_party_size !== null && $partySize > (int) $service->max_party_size) {
            return $fail('party_size_exceeded', 'الحد الأقصى لعدد الأشخاص لهذه الخدمة هو '.$service->max_party_size.'.');
        }

        $dayEval = $this->evaluateDayOpen($setting, $slotStartLocal->startOfDay(), $ignoreAdvanceRules);
        if (! $dayEval['open']) {
            return $fail((string) $dayEval['code'], (string) $dayEval['reason']);
        }

        $nowLocal = CarbonImmutable::now($timezone);
        if (! $ignoreAdvanceRules && $slotStartLocal->lessThan($nowLocal->addMinutes(max(0, (int) $setting->min_advance_minutes)))) {
            return $fail('too_soon', 'الوقت المختار أقرب من الحد الأدنى المسموح للحجز المسبق.');
        }

        if ($provider !== null && ! $provider->supportsService($service?->id)) {
            return $fail('provider_service_mismatch', 'مقدم الخدمة المحدد لا يقدم هذه الخدمة.');
        }

        if ($provider !== null && $branch !== null && $provider->branch_id !== null && (int) $provider->branch_id !== (int) $branch->id) {
            return $fail('provider_branch_mismatch', 'مقدم الخدمة غير متاح في هذا الفرع.');
        }

        $effectiveWindow = $this->resolveWorkingWindow($setting, $slotStartLocal->startOfDay(), $branch, $provider);
        if ($effectiveWindow === null) {
            return $fail('day_closed', 'هذا اليوم مغلق ولا توجد ساعات عمل متاحة فيه.');
        }

        $openLocal = $slotStartLocal->startOfDay()->setTimeFromTimeString($effectiveWindow['start']);
        $closeLocal = $slotStartLocal->startOfDay()->setTimeFromTimeString($effectiveWindow['end']);
        if ($slotStartLocal->lessThan($openLocal) || $slotEndLocal->greaterThan($closeLocal)) {
            return $fail('outside_working_hours', 'الوقت المطلوب خارج ساعات العمل الرسمية ('.$effectiveWindow['start'].' - '.$effectiveWindow['end'].').');
        }

        $providers = $this->compatibleProviders($workspace, $service, $branch, $provider);
        $buffer = max(0, (int) $setting->buffer_minutes);
        $activeBookings = $this->activeBookingsInWindow(
            $workspace,
            $startsAtUtc->subMinutes($buffer),
            $endsAtUtc->addMinutes($buffer),
            $branch,
            $excludeBookingId,
        );
        $blockedSlots = $this->blockedSlotsInWindow(
            $workspace,
            $startsAtUtc->subMinutes($buffer),
            $endsAtUtc->addMinutes($buffer),
            $branch,
            $service,
        );

        $eval = $this->evaluateCandidateSlot(
            setting: $setting,
            slotStartLocal: $slotStartLocal,
            slotEndLocal: $slotEndLocal,
            service: $service,
            branch: $branch,
            specificProvider: $provider,
            compatibleProviders: $providers,
            breaks: $effectiveWindow['breaks'],
            activeBookings: $activeBookings,
            blockedSlots: $blockedSlots,
        );

        if (! $eval['available']) {
            return $fail((string) ($eval['reason_code'] ?? 'slot_unavailable'), (string) ($eval['reason'] ?? 'الموعد المطلوب غير متاح.'));
        }

        return [
            'available' => true,
            'reason_code' => null,
            'reason' => null,
            'starts_at_utc' => $startsAtUtc,
            'ends_at_utc' => $endsAtUtc,
            'duration_minutes' => $duration,
            'end_time' => $slotEndLocal->format('H:i'),
            'remaining_capacity' => $eval['remaining_capacity'],
            'resolved_provider_id' => $eval['resolved_provider_id'],
        ];
    }

    /**
     * @return array{
     *   available:true,
     *   starts_at_utc:CarbonImmutable,
     *   ends_at_utc:CarbonImmutable,
     *   duration_minutes:int,
     *   end_time:string,
     *   remaining_capacity:int,
     *   resolved_provider_id:?int
     * }
     */
    public function assertSlotAvailable(
        Workspace $workspace,
        string $date,
        string $startTime,
        ?BookingService $service = null,
        ?BookingBranch $branch = null,
        ?BookingProvider $provider = null,
        ?int $partySize = null,
        ?int $excludeBookingId = null,
        bool $ignoreAdvanceRules = false,
        bool $ignoreDisabledSetting = false,
    ): array {
        $result = $this->checkSlot(
            workspace: $workspace,
            date: $date,
            startTime: $startTime,
            service: $service,
            branch: $branch,
            provider: $provider,
            partySize: $partySize,
            excludeBookingId: $excludeBookingId,
            ignoreAdvanceRules: $ignoreAdvanceRules,
            ignoreDisabledSetting: $ignoreDisabledSetting,
        );

        if (! $result['available']) {
            $alternatives = $this->findNearestAvailableSlots(
                workspace: $workspace,
                startDate: $date,
                service: $service,
                branch: $branch,
                provider: $provider,
                partySize: $partySize,
                excludeBookingId: $excludeBookingId,
                limit: 5,
            );

            throw new BookingUnavailableException(
                (string) ($result['reason'] ?? 'الموعد المطلوب غير متاح.'),
                (string) ($result['reason_code'] ?? 'slot_unavailable'),
                $alternatives,
            );
        }

        return $result;
    }

    /**
     * @return array{open:bool,code:?string,reason:?string}
     */
    private function evaluateDayOpen(BookingSetting $setting, CarbonImmutable $localDay, bool $ignoreAdvanceRules): array
    {
        $timezone = $setting->timezone ?: 'Asia/Riyadh';
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if (! $ignoreAdvanceRules && $localDay->lessThan($today)) {
            return ['open' => false, 'code' => 'past_date', 'reason' => 'لا يمكن الحجز في تاريخ سابق.'];
        }

        if (! $ignoreAdvanceRules && $localDay->greaterThan($today->addDays(max(1, (int) $setting->max_advance_days)))) {
            return ['open' => false, 'code' => 'too_far_ahead', 'reason' => 'التاريخ المطلوب يتجاوز الحد الأقصى المسموح للحجز المسبق.'];
        }

        $dateStr = $localDay->format('Y-m-d');
        $monthDay = $localDay->format('m-d');
        foreach (is_array($setting->holidays) ? $setting->holidays : [] as $holiday) {
            if (! is_array($holiday)) {
                continue;
            }
            $hDate = (string) ($holiday['date'] ?? '');
            $recurring = (bool) ($holiday['recurring_yearly'] ?? false);
            if ($hDate === $dateStr || ($recurring && strlen($hDate) === 10 && substr($hDate, 5) === $monthDay)) {
                $title = trim((string) ($holiday['title'] ?? 'إجازة'));

                return ['open' => false, 'code' => 'holiday', 'reason' => 'اليوم المختار يوم إجازة ('.$title.').'];
            }
        }

        return ['open' => true, 'code' => null, 'reason' => null];
    }

    /**
     * @return array{start:string,end:string,breaks:list<array{start:string,end:string,label?:string}>}|null
     */
    private function resolveWorkingWindow(
        BookingSetting $setting,
        CarbonImmutable $localDay,
        ?BookingBranch $branch,
        ?BookingProvider $provider,
    ): ?array {
        $dayKey = strtolower($localDay->format('l'));
        $base = is_array($setting->working_hours) && $setting->working_hours !== []
            ? $setting->working_hours
            : BookingSetting::defaultWorkingHours();

        $dayConfig = $base[$dayKey] ?? null;
        if (is_array($branch?->working_hours_override) && isset($branch->working_hours_override[$dayKey])) {
            $dayConfig = $branch->working_hours_override[$dayKey];
        }
        if (is_array($provider?->working_hours_override) && isset($provider->working_hours_override[$dayKey])) {
            $dayConfig = $provider->working_hours_override[$dayKey];
        }

        if (! is_array($dayConfig) || empty($dayConfig['enabled'])) {
            return null;
        }

        $start = trim((string) ($dayConfig['start'] ?? ''));
        $end = trim((string) ($dayConfig['end'] ?? ''));
        if ($start === '' || $end === '' || $end <= $start) {
            return null;
        }

        return [
            'start' => $start,
            'end' => $end,
            'breaks' => is_array($dayConfig['breaks'] ?? null) ? $dayConfig['breaks'] : [],
        ];
    }

    /**
     * @return Collection<int, BookingProvider>
     */
    private function compatibleProviders(
        Workspace $workspace,
        ?BookingService $service,
        ?BookingBranch $branch,
        ?BookingProvider $specificProvider,
    ): Collection {
        if ($specificProvider !== null) {
            if (! $specificProvider->is_active || ! $specificProvider->supportsService($service?->id)) {
                return collect();
            }
            if ($branch !== null && $specificProvider->branch_id !== null && (int) $specificProvider->branch_id !== (int) $branch->id) {
                return collect();
            }

            return collect([$specificProvider]);
        }

        return BookingProvider::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->when($branch !== null, function ($q) use ($branch): void {
                $q->where(function ($inner) use ($branch): void {
                    $inner->whereNull('branch_id')->orWhere('branch_id', $branch->id);
                });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(static fn (BookingProvider $p): bool => $p->supportsService($service?->id))
            ->values();
    }

    /**
     * @return Collection<int, Booking>
     */
    private function activeBookingsInWindow(
        Workspace $workspace,
        CarbonImmutable $windowStartUtc,
        CarbonImmutable $windowEndUtc,
        ?BookingBranch $branch,
        ?int $excludeBookingId,
    ): Collection {
        return Booking::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', BookingStatus::activeValues())
            ->where('starts_at', '<', $windowEndUtc)
            ->where('ends_at', '>', $windowStartUtc)
            ->when($excludeBookingId !== null, fn ($q) => $q->where('id', '!=', $excludeBookingId))
            ->when($branch !== null, function ($q) use ($branch): void {
                $q->where(function ($inner) use ($branch): void {
                    $inner->whereNull('branch_id')->orWhere('branch_id', $branch->id);
                });
            })
            ->get(['id', 'service_id', 'branch_id', 'provider_id', 'starts_at', 'ends_at']);
    }

    /**
     * @return Collection<int, BookingBlockedSlot>
     */
    private function blockedSlotsInWindow(
        Workspace $workspace,
        CarbonImmutable $windowStartUtc,
        CarbonImmutable $windowEndUtc,
        ?BookingBranch $branch,
        ?BookingService $service,
    ): Collection {
        return BookingBlockedSlot::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('starts_at', '<', $windowEndUtc)
            ->where('ends_at', '>', $windowStartUtc)
            ->when($branch !== null, function ($q) use ($branch): void {
                $q->where(function ($inner) use ($branch): void {
                    $inner->whereNull('branch_id')->orWhere('branch_id', $branch->id);
                });
            })
            ->when($service !== null, function ($q) use ($service): void {
                $q->where(function ($inner) use ($service): void {
                    $inner->whereNull('service_id')->orWhere('service_id', $service->id);
                });
            })
            ->get(['id', 'branch_id', 'provider_id', 'service_id', 'starts_at', 'ends_at', 'reason']);
    }

    /**
     * @param  list<array{start:string,end:string,label?:string}>  $breaks
     * @param  Collection<int, BookingProvider>  $compatibleProviders
     * @param  Collection<int, Booking>  $activeBookings
     * @param  Collection<int, BookingBlockedSlot>  $blockedSlots
     * @return array{available:bool,reason_code:?string,reason:?string,remaining_capacity:int,resolved_provider_id:?int,resolved_provider_name:?string}
     */
    private function evaluateCandidateSlot(
        BookingSetting $setting,
        CarbonImmutable $slotStartLocal,
        CarbonImmutable $slotEndLocal,
        ?BookingService $service,
        ?BookingBranch $branch,
        ?BookingProvider $specificProvider,
        Collection $compatibleProviders,
        array $breaks,
        Collection $activeBookings,
        Collection $blockedSlots,
    ): array {
        $localDay = $slotStartLocal->startOfDay();
        foreach ($breaks as $break) {
            if (! is_array($break) || empty($break['start']) || empty($break['end'])) {
                continue;
            }
            $bStart = $localDay->setTimeFromTimeString((string) $break['start']);
            $bEnd = $localDay->setTimeFromTimeString((string) $break['end']);
            if ($slotStartLocal->lessThan($bEnd) && $slotEndLocal->greaterThan($bStart)) {
                return [
                    'available' => false,
                    'reason_code' => 'break_time',
                    'reason' => 'الوقت المطلوب يتعارض مع فترة الاستراحة.',
                    'remaining_capacity' => 0,
                    'resolved_provider_id' => null,
                    'resolved_provider_name' => null,
                ];
            }
        }

        $buffer = max(0, (int) $setting->buffer_minutes);
        $slotStartUtc = $slotStartLocal->utc();
        $slotEndUtc = $slotEndLocal->utc();
        $bufferedStartUtc = $slotStartUtc->subMinutes($buffer);
        $bufferedEndUtc = $slotEndUtc->addMinutes($buffer);

        $overlappingBookings = $activeBookings->filter(
            static fn (Booking $b): bool => $b->starts_at !== null
                && $b->ends_at !== null
                && $b->starts_at->lt($bufferedEndUtc)
                && $b->ends_at->gt($bufferedStartUtc),
        );

        $overlappingBlocks = $blockedSlots->filter(
            static fn (BookingBlockedSlot $block): bool => $block->starts_at !== null
                && $block->ends_at !== null
                && $block->starts_at->lt($slotEndUtc)
                && $block->ends_at->gt($slotStartUtc),
        );

        // Provider-specific evaluation when a provider is requested or configured
        if ($specificProvider !== null) {
            $providerBlocked = $overlappingBlocks->first(
                static fn (BookingBlockedSlot $bl): bool => $bl->provider_id === null || (int) $bl->provider_id === (int) $specificProvider->id,
            );
            if ($providerBlocked !== null) {
                return [
                    'available' => false,
                    'reason_code' => 'blocked_slot',
                    'reason' => $providerBlocked->reason ?: 'هذا الوقت مغلق يدوياً لمقدم الخدمة.',
                    'remaining_capacity' => 0,
                    'resolved_provider_id' => null,
                    'resolved_provider_name' => null,
                ];
            }

            $providerCount = $overlappingBookings
                ->filter(static fn (Booking $b): bool => (int) $b->provider_id === (int) $specificProvider->id)
                ->count();
            $remaining = max(0, (int) $specificProvider->capacity_per_slot - $providerCount);

            if ($remaining <= 0) {
                return [
                    'available' => false,
                    'reason_code' => 'capacity_full',
                    'reason' => 'الموعد ممتلئ بالكامل لمقدم الخدمة المحدد.',
                    'remaining_capacity' => 0,
                    'resolved_provider_id' => null,
                    'resolved_provider_name' => null,
                ];
            }

            return [
                'available' => true,
                'reason_code' => null,
                'reason' => null,
                'remaining_capacity' => $remaining,
                'resolved_provider_id' => (int) $specificProvider->id,
                'resolved_provider_name' => $specificProvider->name,
            ];
        }

        if ($compatibleProviders->isNotEmpty()) {
            $totalRemaining = 0;
            $firstAvailable = null;

            // Bookings without a specific provider consume capacity from the general pool.
            $unassignedCount = $overlappingBookings->filter(static fn (Booking $b): bool => $b->provider_id === null)->count();

            foreach ($compatibleProviders as $cand) {
                $isBlocked = $overlappingBlocks->contains(
                    static fn (BookingBlockedSlot $bl): bool => $bl->provider_id === null || (int) $bl->provider_id === (int) $cand->id,
                );
                if ($isBlocked) {
                    continue;
                }

                $candCount = $overlappingBookings->filter(static fn (Booking $b): bool => (int) $b->provider_id === (int) $cand->id)->count();
                $candRemaining = max(0, (int) $cand->capacity_per_slot - $candCount);
                if ($candRemaining > 0) {
                    $totalRemaining += $candRemaining;
                    $firstAvailable ??= $cand;
                }
            }

            $totalRemaining = max(0, $totalRemaining - $unassignedCount);
            if ($totalRemaining <= 0 || $firstAvailable === null) {
                return [
                    'available' => false,
                    'reason_code' => 'capacity_full',
                    'reason' => 'جميع الفترات والمختصين ممتلئون أو مغلقون في هذا الوقت.',
                    'remaining_capacity' => 0,
                    'resolved_provider_id' => null,
                    'resolved_provider_name' => null,
                ];
            }

            return [
                'available' => true,
                'reason_code' => null,
                'reason' => null,
                'remaining_capacity' => $totalRemaining,
                'resolved_provider_id' => (int) $firstAvailable->id,
                'resolved_provider_name' => $firstAvailable->name,
            ];
        }

        // General capacity mode (no providers configured)
        $globalBlock = $overlappingBlocks->first(static fn (BookingBlockedSlot $bl): bool => $bl->provider_id === null);
        if ($globalBlock !== null) {
            return [
                'available' => false,
                'reason_code' => 'blocked_slot',
                'reason' => $globalBlock->reason ?: 'هذا الوقت مغلق يدوياً.',
                'remaining_capacity' => 0,
                'resolved_provider_id' => null,
                'resolved_provider_name' => null,
            ];
        }

        $capacity = $service ? $service->effectiveCapacity($setting) : max(1, (int) $setting->default_capacity_per_slot);
        $usedCount = $overlappingBookings
            ->filter(function (Booking $b) use ($service): bool {
                if ($service === null || $b->service_id === null) {
                    return true;
                }

                return (int) $b->service_id === (int) $service->id;
            })
            ->count();

        $remaining = max(0, $capacity - $usedCount);
        if ($remaining <= 0) {
            return [
                'available' => false,
                'reason_code' => 'capacity_full',
                'reason' => 'تم الوصول للحد الأقصى للحجوزات في هذه الفترة.',
                'remaining_capacity' => 0,
                'resolved_provider_id' => null,
                'resolved_provider_name' => null,
            ];
        }

        return [
            'available' => true,
            'reason_code' => null,
            'reason' => null,
            'remaining_capacity' => $remaining,
            'resolved_provider_id' => null,
            'resolved_provider_name' => null,
        ];
    }
}
