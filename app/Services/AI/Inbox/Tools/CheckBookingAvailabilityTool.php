<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Booking\BookingAvailabilityService;
use App\Services\Booking\BookingManagementService;
use App\Services\Booking\BookingSettingsService;
use Carbon\CarbonImmutable;
use Throwable;

final class CheckBookingAvailabilityTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly BookingAvailabilityService $availability,
        private readonly BookingManagementService $bookings,
        private readonly BookingSettingsService $settingsService,
    ) {}

    public function name(): string
    {
        return 'check_booking_availability';
    }

    public function description(): string
    {
        return 'Check real booking availability in the database for a specific date/time or find the nearest available appointment slots. Always call this before promising or suggesting available dates or times.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Read,
            accessMode: InboxAgentToolAccessMode::Read,
            requiresConfirmation: false,
            maxCallsPerRun: 4,
        );
    }

    public function inputSchema(): array
    {
        return [
            'date' => ['type' => 'string', 'required' => false, 'description' => 'Target local date (YYYY-MM-DD). Omit or combine with nearest_only=true to find earliest upcoming slots.'],
            'start_time' => ['type' => 'string', 'required' => false, 'description' => 'Optional specific start time (HH:MM, 24h) to check exact availability.'],
            'service_id' => ['type' => 'integer', 'required' => false, 'description' => 'Booking service ID.'],
            'service_name' => ['type' => 'string', 'required' => false, 'description' => 'Booking service/type name.'],
            'branch_id' => ['type' => 'integer', 'required' => false, 'description' => 'Optional branch ID.'],
            'branch_name' => ['type' => 'string', 'required' => false, 'description' => 'Optional branch name.'],
            'provider_id' => ['type' => 'integer', 'required' => false, 'description' => 'Optional staff/provider/resource ID.'],
            'provider_name' => ['type' => 'string', 'required' => false, 'description' => 'Optional staff/provider/resource name.'],
            'party_size' => ['type' => 'integer', 'required' => false, 'description' => 'Optional number of guests/people.'],
            'nearest_only' => ['type' => 'boolean', 'required' => false, 'description' => 'Set true when the customer asks for the earliest/nearest available slot.'],
            'limit' => ['type' => 'integer', 'required' => false, 'description' => 'Maximum slots to return (default 8).'],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $setting = $this->settingsService->getOrCreate($context->workspace);
        if (! $setting->enabled) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'check_booking_availability',
                [
                    'booking_enabled' => false,
                    'available_slots' => [],
                    'count' => 0,
                    'message' => 'نظام الحجوزات غير مفعّل حالياً لدى المنشأة.',
                ],
            );
        }

        try {
            $timezone = $setting->timezone ?: 'Asia/Riyadh';
            $service = $this->bookings->resolveService($context->workspace, $arguments);
            $branch = $this->bookings->resolveBranch($context->workspace, $arguments);
            $provider = $this->bookings->resolveProvider($context->workspace, $arguments);
            $partySize = isset($arguments['party_size']) && is_numeric($arguments['party_size']) ? max(1, (int) $arguments['party_size']) : null;
            $limit = max(1, min(16, (int) ($arguments['limit'] ?? 8)));
            $date = isset($arguments['date']) && is_string($arguments['date']) && trim($arguments['date']) !== ''
                ? trim($arguments['date'])
                : null;
            $startTime = isset($arguments['start_time']) && is_string($arguments['start_time']) && trim($arguments['start_time']) !== ''
                ? trim($arguments['start_time'])
                : null;
            $nearestOnly = (bool) ($arguments['nearest_only'] ?? false);

            $exactCheck = null;
            if ($date !== null && $startTime !== null) {
                $exactCheck = $this->availability->checkSlot(
                    workspace: $context->workspace,
                    date: $date,
                    startTime: $startTime,
                    service: $service,
                    branch: $branch,
                    provider: $provider,
                    partySize: $partySize,
                );
            }

            if ($nearestOnly || $date === null) {
                $slots = $this->availability->findNearestAvailableSlots(
                    workspace: $context->workspace,
                    startDate: $date ?? CarbonImmutable::now($timezone)->format('Y-m-d'),
                    service: $service,
                    branch: $branch,
                    provider: $provider,
                    partySize: $partySize,
                    limit: $limit,
                );
            } else {
                $slots = $this->availability->availableSlotsForDate(
                    workspace: $context->workspace,
                    date: $date,
                    service: $service,
                    branch: $branch,
                    provider: $provider,
                    partySize: $partySize,
                    limit: $limit,
                );
            }

            $alternatives = [];
            if ($slots === [] || ($exactCheck !== null && ! $exactCheck['available'])) {
                $alternatives = $this->availability->findNearestAvailableSlots(
                    workspace: $context->workspace,
                    startDate: $date ?? CarbonImmutable::now($timezone)->format('Y-m-d'),
                    service: $service,
                    branch: $branch,
                    provider: $provider,
                    partySize: $partySize,
                    limit: 5,
                );
            }

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'check_booking_availability',
                [
                    'booking_enabled' => true,
                    'timezone' => $timezone,
                    'checked_date' => $date,
                    'checked_start_time' => $startTime,
                    'exact_slot_available' => $exactCheck['available'] ?? null,
                    'exact_slot_reason' => $exactCheck['reason'] ?? null,
                    'available_slots' => $slots,
                    'nearest_alternatives' => $alternatives,
                    'count' => count($slots),
                ],
            );
        } catch (Throwable $exception) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'check_booking_availability',
                'execution_failed',
                $exception->getMessage(),
            );
        }
    }
}
