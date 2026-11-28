<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Booking\BookingManagementService;
use App\Services\Booking\BookingSettingsService;
use App\Services\Booking\Exceptions\BookingUnavailableException;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CreateBookingTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly BookingManagementService $bookings,
        private readonly BookingSettingsService $settingsService,
    ) {}

    public function name(): string
    {
        return 'create_booking';
    }

    public function description(): string
    {
        return 'Create a real appointment/booking after collecting required customer details and verifying slot availability. Rejects unavailable slots and returns real nearest alternatives if a conflict occurs.';
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
            'customer_name' => ['type' => 'string', 'required' => true, 'description' => 'Customer full name.'],
            'phone' => ['type' => 'string', 'required' => false, 'description' => 'Customer mobile phone number.'],
            'service_id' => ['type' => 'integer', 'required' => false, 'description' => 'Booking service ID from get_booking_options.'],
            'service_name' => ['type' => 'string', 'required' => true, 'description' => 'Service or booking type name.'],
            'date' => ['type' => 'string', 'required' => true, 'description' => 'Local booking date (YYYY-MM-DD).'],
            'start_time' => ['type' => 'string', 'required' => true, 'description' => 'Local booking start time (HH:MM, 24h).'],
            'party_size' => ['type' => 'integer', 'required' => false, 'description' => 'Number of guests/people when applicable.'],
            'branch_id' => ['type' => 'integer', 'required' => false, 'description' => 'Branch/location ID if applicable.'],
            'branch_name' => ['type' => 'string', 'required' => false, 'description' => 'Branch/location name if applicable.'],
            'provider_id' => ['type' => 'integer', 'required' => false, 'description' => 'Staff/provider/resource ID if applicable.'],
            'provider_name' => ['type' => 'string', 'required' => false, 'description' => 'Staff/provider/resource name if applicable.'],
            'notes' => ['type' => 'string', 'required' => false, 'description' => 'Customer notes or special requests.'],
            'custom_fields' => ['type' => 'object', 'required' => false, 'description' => 'Business-specific extra booking details as key-value pairs.'],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $setting = $this->settingsService->getOrCreate($context->workspace);
        if (! $setting->enabled) {
            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'create_booking',
                'booking_disabled',
                'نظام الحجوزات معطّل حالياً لدى المنشأة.',
            );
        }

        $date = trim((string) ($arguments['date'] ?? ''));
        $startTime = trim((string) ($arguments['start_time'] ?? ''));
        $serviceName = trim((string) ($arguments['service_name'] ?? ''));
        $msgId = $context->message->exists ? (int) $context->message->id : null;
        $idempotencyKey = $msgId !== null
            ? 'ai-booking:conv:'.$context->conversation->id.':msg:'.$msgId.':'.md5($date.'|'.$startTime.'|'.$serviceName)
            : null;

        try {
            $booking = $this->bookings->createBooking(
                workspace: $context->workspace,
                data: [
                    'customer_name' => $arguments['customer_name'] ?? '',
                    'phone' => $arguments['phone'] ?? '',
                    'service_id' => $arguments['service_id'] ?? null,
                    'service_name' => $serviceName,
                    'date' => $date,
                    'start_time' => $startTime,
                    'party_size' => $arguments['party_size'] ?? null,
                    'branch_id' => $arguments['branch_id'] ?? null,
                    'branch_name' => $arguments['branch_name'] ?? null,
                    'provider_id' => $arguments['provider_id'] ?? null,
                    'provider_name' => $arguments['provider_name'] ?? null,
                    'customer_notes' => $arguments['notes'] ?? null,
                    'custom_fields' => $arguments['custom_fields'] ?? null,
                    'source' => 'ai',
                    'channel' => $context->conversation->channel,
                    'source_message_id' => $msgId,
                    'idempotency_key' => $idempotencyKey,
                ],
                conversation: $context->conversation,
                actor: null,
                allowOverrideRules: false,
                notifyCustomerViaMessage: false,
            );

            $timezone = $setting->timezone ?: 'Asia/Riyadh';

            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'create_booking',
                [
                    'created' => true,
                    'booking_reference' => $booking->booking_reference,
                    'booking' => $booking->toSummaryArray($timezone),
                ],
            );
        } catch (BookingUnavailableException $unavailable) {
            return InboxAgentToolExecutionResult::success(
                $this->name(),
                'create_booking',
                [
                    'created' => false,
                    'unavailable' => true,
                    'reason_code' => $unavailable->reasonCode,
                    'message' => $unavailable->getMessage(),
                    'nearest_alternatives' => $unavailable->alternatives,
                ],
            );
        } catch (Throwable $exception) {
            Log::warning('inbox.agent.tool.create_booking_failed', [
                'workspace_id' => $context->workspace->id,
                'conversation_id' => $context->conversation->id,
                'error' => $exception->getMessage(),
            ]);

            return InboxAgentToolExecutionResult::failure(
                $this->name(),
                'create_booking',
                'execution_failed',
                $exception->getMessage(),
            );
        }
    }
}
