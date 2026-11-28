<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\Booking;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Booking\BookingManagementService;
use App\Services\Booking\BookingSettingsService;
use App\Services\Booking\Exceptions\BookingUnavailableException;
use Throwable;

final class RescheduleBookingTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly BookingManagementService $bookings,
        private readonly BookingSettingsService $settingsService,
    ) {}

    public function name(): string
    {
        return 'reschedule_booking';
    }

    public function description(): string
    {
        return 'Reschedule an existing active booking belonging strictly to the current customer or conversation to a new verified date and time. IMPORTANT: Ask the customer to confirm before setting customer_confirmed=true.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Low,
            accessMode: InboxAgentToolAccessMode::Write,
            requiresConfirmation: false,
            maxCallsPerRun: 2,
        );
    }

    public function inputSchema(): array
    {
        return [
            'booking_reference' => ['type' => 'string', 'required' => false, 'description' => 'Booking reference (e.g. BK-000001). Optional when customer has only one active booking.'],
            'new_date' => ['type' => 'string', 'required' => true, 'description' => 'New local booking date (YYYY-MM-DD).'],
            'new_start_time' => ['type' => 'string', 'required' => true, 'description' => 'New local booking start time (HH:MM, 24h).'],
            'branch_id' => ['type' => 'integer', 'required' => false, 'description' => 'Optional new branch ID.'],
            'branch_name' => ['type' => 'string', 'required' => false, 'description' => 'Optional new branch name.'],
            'provider_id' => ['type' => 'integer', 'required' => false, 'description' => 'Optional new provider ID.'],
            'provider_name' => ['type' => 'string', 'required' => false, 'description' => 'Optional new provider name.'],
            'reason' => ['type' => 'string', 'required' => false, 'description' => 'Reason for rescheduling.'],
            'customer_confirmed' => ['type' => 'boolean', 'required' => true, 'description' => 'Must be true ONLY after the customer explicitly confirmed the new date/time. Set false to get confirmation details without modifying yet.'],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $timezone = $this->settingsService->getOrCreate($context->workspace)->timezone ?: 'Asia/Riyadh';

        try {
            $booking = $this->bookings->findCustomerBookingByReferenceOrSingleActive(
                $context->workspace,
                $context->conversation,
                isset($arguments['booking_reference']) ? (string) $arguments['booking_reference'] : null,
            );

            if (! $booking instanceof Booking) {
                return InboxAgentToolExecutionResult::failure(
                    $this->name(),
                    'reschedule_booking',
                    'booking_not_found',
                    'لم يتم العثور على حجز نشط لهذا العميل بالمرجع المحدد.',
                );
            }

            $newDate = trim((string) ($arguments['new_date'] ?? ''));
            $newTime = trim((string) ($arguments['new_start_time'] ?? ''));
            $confirmed = (bool) ($arguments['customer_confirmed'] ?? false);

            if (! $confirmed) {
                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'reschedule_booking',
                    [
                        'rescheduled' => false,
                        'confirmation_required' => true,
                        'booking_reference' => $booking->booking_reference,
                        'current_booking' => $booking->toSummaryArray($timezone),
                        'proposed_date' => $newDate,
                        'proposed_start_time' => $newTime,
                        'message' => 'يرجى طلب تأكيد العميل أولاً قبل تنفيذ إعادة الجدولة.',
                    ],
                );
            }

            $updated = $this->bookings->rescheduleBooking(
                workspace: $context->workspace,
                booking: $booking,
                newDate: $newDate,
                newStartTime: $newTime,
                data: $arguments,
                actor: null,
                allowOverrideRules: false,
                notifyCustomerViaMessage: false,
            );

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'reschedule_booking',
                [
                    'rescheduled' => true,
                    'booking_reference' => $updated->booking_reference,
                    'booking' => $updated->toSummaryArray($timezone),
                ],
            );
        } catch (BookingUnavailableException $unavailable) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'reschedule_booking',
                [
                    'rescheduled' => false,
                    'unavailable' => true,
                    'reason_code' => $unavailable->reasonCode,
                    'message' => $unavailable->getMessage(),
                    'nearest_alternatives' => $unavailable->alternatives,
                ],
            );
        } catch (Throwable $exception) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'reschedule_booking',
                'execution_failed',
                $exception->getMessage(),
            );
        }
    }
}
