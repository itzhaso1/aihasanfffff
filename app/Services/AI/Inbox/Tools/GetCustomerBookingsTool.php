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

final class GetCustomerBookingsTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly BookingManagementService $bookings,
        private readonly BookingSettingsService $settingsService,
    ) {}

    public function name(): string
    {
        return 'get_customer_bookings';
    }

    public function description(): string
    {
        return 'Look up upcoming and previous bookings strictly belonging to the current customer or conversation.';
    }

    public function securityProfile(): InboxAgentToolSecurityProfile
    {
        return new InboxAgentToolSecurityProfile(
            riskLevel: InboxAgentToolRiskLevel::Read,
            accessMode: InboxAgentToolAccessMode::Read,
            requiresConfirmation: false,
            maxCallsPerRun: 2,
        );
    }

    public function inputSchema(): array
    {
        return [
            'include_past' => ['type' => 'boolean', 'required' => false, 'description' => 'Include completed/cancelled/past bookings as well as active ones (default true).'],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $includePast = (bool) ($arguments['include_past'] ?? true);
        $timezone = $this->settingsService->getOrCreate($context->workspace)->timezone ?: 'Asia/Riyadh';

        $rows = $this->bookings->customerBookings(
            workspace: $context->workspace,
            conversation: $context->conversation,
            includePast: $includePast,
            limit: 10,
        );

        $serialized = $rows->map(static fn (Booking $b): array => $b->toSummaryArray($timezone))->values()->all();

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_customer_bookings',
            [
                'bookings' => $serialized,
                'count' => count($serialized),
            ],
        );
    }
}
