<?php

namespace App\Services\Chat;

use App\Enums\ChatCatalog\ChatCatalogAvailabilityMode;
use App\Enums\ChatCatalog\ChatCatalogOperatingStatus;
use App\Models\ChatCatalog;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class CatalogAvailabilityService
{
    public function isCatalogOpenForCustomers(ChatCatalog $catalog, ?CarbonInterface $at = null): bool
    {
        $at = CarbonImmutable::instance($at ?? now())->timezone($catalog->timezone ?: 'UTC');

        if ($catalog->status === ChatCatalogOperatingStatus::Archived) {
            return false;
        }

        if ($catalog->status === ChatCatalogOperatingStatus::Draft) {
            return false;
        }

        if ($catalog->status === ChatCatalogOperatingStatus::Paused) {
            return false;
        }

        return match ($catalog->availability_mode) {
            ChatCatalogAvailabilityMode::AlwaysOpen => true,
            ChatCatalogAvailabilityMode::TemporarilyUnavailable,
            ChatCatalogAvailabilityMode::Maintenance => false,
            ChatCatalogAvailabilityMode::Scheduled => $this->matchesSchedule($catalog, $at),
        };
    }

    public function customerDiscoveryAllowed(ChatCatalog $catalog, ?CarbonInterface $at = null): bool
    {
        if ($catalog->status === ChatCatalogOperatingStatus::Paused) {
            return $catalog->pausedDiscoveryBehavior() === 'show_unavailable';
        }

        if (! $catalog->allowsCustomerDiscovery()) {
            return false;
        }

        // A published catalog remains browsable while closed so the public
        // page and Inbox can show its configured unavailable/out-of-hours
        // customer message. Ordering remains unavailable in this phase.
        return true;
    }

    private function matchesSchedule(ChatCatalog $catalog, CarbonImmutable $at): bool
    {
        $schedule = is_array($catalog->availability_schedule) ? $catalog->availability_schedule : [];
        if (($schedule['enabled'] ?? true) === false) {
            return true;
        }
        $blackouts = $schedule['blackout_dates'] ?? [];
        $dateKey = $at->format('Y-m-d');
        if (in_array($dateKey, $blackouts, true)) {
            return false;
        }

        $holidays = $schedule['holidays'] ?? [];
        if (in_array($dateKey, $holidays, true)) {
            return false;
        }

        $weekly = $schedule['weekly'] ?? [];
        if ($weekly === []) {
            return true;
        }

        $today = collect($weekly)->firstWhere('day', strtolower($at->englishDayOfWeek));
        if (is_array($today) && $this->matchesRule($today, $at->format('H:i'), false)) {
            return true;
        }

        $yesterday = collect($weekly)->firstWhere('day', strtolower($at->subDay()->englishDayOfWeek));

        return is_array($yesterday) && $this->matchesRule($yesterday, $at->format('H:i'), true);
    }

    /**
     * A time range crossing midnight belongs to its starting weekday.
     *
     * @param  array<string, mixed>  $rule
     */
    private function matchesRule(array $rule, string $time, bool $fromPreviousDay): bool
    {
        if (($rule['closed'] ?? false) === true) {
            return false;
        }

        $start = (string) ($rule['start'] ?? '00:00');
        $end = (string) ($rule['end'] ?? '23:59');
        if ($start <= $end) {
            return ! $fromPreviousDay && $time >= $start && $time <= $end;
        }

        return $fromPreviousDay ? $time <= $end : $time >= $start;
    }
}
