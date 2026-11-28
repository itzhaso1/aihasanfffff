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

final class UpdateBookingTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly BookingManagementService $bookings,
        private readonly BookingSettingsService $settingsService,
    ) {}

    public function name(): string
    {
        return 'update_booking';
    }

    public function description(): string
    {
        return 'Update details (service, party size, branch, provider, customer name, phone, notes, or custom fields) on an active booking for the current customer. IMPORTANT: Ask the customer to confirm before setting customer_confirmed=true.';
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
            'service_id' => ['type' => 'integer', 'required' => false, 'description' => 'Updated service ID.'],
            'service_name' => ['type' => 'string', 'required' => false, 'description' => 'Updated service name.'],
            'party_size' => ['type' => 'integer', 'required' => false, 'description' => 'Updated number of guests/people.'],
            'branch_id' => ['type' => 'integer', 'required' => false, 'description' => 'Updated branch ID.'],
            'branch_name' => ['type' => 'string', 'required' => false, 'description' => 'Updated branch name.'],
            'provider_id' => ['type' => 'integer', 'required' => false, 'description' => 'Updated provider ID.'],
            'provider_name' => ['type' => 'string', 'required' => false, 'description' => 'Updated provider name.'],
            'customer_name' => ['type' => 'string', 'required' => false, 'description' => 'Updated customer name.'],
            'phone' => ['type' => 'string', 'required' => false, 'description' => 'Updated customer phone.'],
            'notes' => ['type' => 'string', 'required' => false, 'description' => 'Updated notes.'],
            'custom_fields' => ['type' => 'object', 'required' => false, 'description' => 'Updated custom business fields.'],
            'customer_confirmed' => ['type' => 'boolean', 'required' => true, 'description' => 'Must be true ONLY after customer explicitly confirms the modification.'],
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
                    'update_booking',
                    'booking_not_found',
                    'لم يتم العثور على حجز نشط لهذا العميل بالمرجع المحدد.',
                );
            }

            if (! (bool) ($arguments['customer_confirmed'] ?? false)) {
                return InboxAgentToolExecutionResult::success(
                    $this->name(),
                    'update_booking',
                    [
                        'updated' => false,
                        'confirmation_required' => true,
                        'booking_reference' => $booking->booking_reference,
                        'current_booking' => $booking->toSummaryArray($timezone),
                        'message' => 'يرجى طلب تأكيد العميل على التعديل قبل حفظ التغييرات.',
                    ],
                );
            }

            $updated = $this->bookings->updateBookingDetails(
                workspace: $context->workspace,
                booking: $booking,
                data: $arguments,
                actor: null,
                allowOverrideRules: false,
                notifyCustomerViaMessage: false,
            );

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'update_booking',
                [
                    'updated' => true,
                    'booking_reference' => $updated->booking_reference,
                    'booking' => $updated->toSummaryArray($timezone),
                ],
            );
        } catch (BookingUnavailableException $unavailable) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'update_booking',
                [
                    'updated' => false,
                    'unavailable' => true,
                    'reason_code' => $unavailable->reasonCode,
                    'message' => $unavailable->getMessage(),
                    'nearest_alternatives' => $unavailable->alternatives,
                ],
            );
        } catch (Throwable $exception) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'update_booking',
                'execution_failed',
                $exception->getMessage(),
            );
        }
    }
}
