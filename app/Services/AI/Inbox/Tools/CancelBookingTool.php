<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\BookingStatus;
use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\Booking;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Booking\BookingManagementService;
use App\Services\Booking\BookingSettingsService;
use Throwable;

final class CancelBookingTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly BookingManagementService $bookings,
        private readonly BookingSettingsService $settingsService,
    ) {}

    public function name(): string
    {
        return 'cancel_booking';
    }

    public function description(): string
    {
        return 'Cancel an active booking belonging strictly to the current customer or conversation. IMPORTANT: Ask the customer to confirm before setting customer_confirmed=true.';
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
            'booking_reference' => ['type' => 'string', 'required' => false, 'description' => 'Booking reference (e.g. BK-000001).'],
            'reason' => ['type' => 'string', 'required' => false, 'description' => 'Customer reason for cancellation.'],
            'customer_confirmed' => ['type' => 'boolean', 'required' => true, 'description' => 'Must be true ONLY after customer explicitly confirms cancelling the booking. Set false first to review details without cancelling yet.'],
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
                    'cancel_booking',
                    'booking_not_found',
                    'لم يتم العثور على حجز نشط لهذا العميل بالمرجع المحدد.',
                );
            }

            if (! (bool) ($arguments['customer_confirmed'] ?? false)) {
                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'cancel_booking',
                    [
                        'cancelled' => false,
                        'confirmation_required' => true,
                        'booking_reference' => $booking->booking_reference,
                        'current_booking' => $booking->toSummaryArray($timezone),
                        'message' => 'يرجى تأكيد رغبة العميل في الإلغاء قبل تنفيذ إلغاء الحجز نهائياً.',
                    ],
                );
            }

            $updated = $this->bookings->transitionStatus(
                workspace: $context->workspace,
                booking: $booking,
                targetStatus: BookingStatus::Cancelled,
                reason: isset($arguments['reason']) ? (string) $arguments['reason'] : null,
                actor: null,
                notifyCustomerViaMessage: false,
            );

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'cancel_booking',
                [
                    'cancelled' => true,
                    'booking_reference' => $updated->booking_reference,
                    'booking' => $updated->toSummaryArray($timezone),
                ],
            );
        } catch (Throwable $exception) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'cancel_booking',
                'execution_failed',
                $exception->getMessage(),
            );
        }
    }
}
