<?php

declare(strict_types=1);

namespace App\Services\AI\Inbox\Tools;

use App\Enums\InboxAgentToolAccessMode;
use App\Enums\InboxAgentToolRiskLevel;
use App\Models\BookingBranch;
use App\Models\BookingProvider;
use App\Models\BookingService;
use App\Services\AI\Inbox\InboxAgentContext;
use App\Services\AI\Inbox\Tools\Contracts\InboxAgentSecuredTool;
use App\Services\Booking\BookingSettingsService;

final class GetBookingOptionsTool implements InboxAgentSecuredTool
{
    public function __construct(
        private readonly BookingSettingsService $settingsService,
    ) {}

    public function name(): string
    {
        return 'get_booking_options';
    }

    public function description(): string
    {
        return 'Retrieve active booking settings, services/appointment types (durations, capacities, prices), branches/locations, staff/providers, working hours, holidays, and required custom fields for this business.';
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
            'include_branches' => ['type' => 'boolean', 'required' => false, 'description' => 'Include branch/location options.'],
            'include_providers' => ['type' => 'boolean', 'required' => false, 'description' => 'Include staff/provider/resource options.'],
        ];
    }

    public function execute(InboxAgentContext $context, array $arguments): InboxAgentToolExecutionResult
    {
        $workspaceId = (int) $context->workspace->id;
        $setting = $this->settingsService->getOrCreate($context->workspace);

        $services = BookingService::withoutGlobalScopes()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(40)
            ->get()
            ->map(static fn (BookingService $s): array => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'category' => $s->category,
                'description' => $s->description,
                'duration_minutes' => $s->effectiveDurationMinutes($setting),
                'capacity_per_slot' => $s->effectiveCapacity($setting),
                'price' => $s->formattedPrice(),
                'currency' => $s->currency,
                'requires_party_size' => $s->requires_party_size ?? $setting->allow_party_size,
                'max_party_size' => $s->max_party_size,
                'custom_fields' => $s->custom_fields ?? [],
            ])
            ->values()
            ->all();

        $branches = ($arguments['include_branches'] ?? true)
            ? BookingBranch::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(30)
                ->get(['id', 'name', 'city', 'address', 'phone'])
                ->toArray()
            : [];

        $providers = ($arguments['include_providers'] ?? true)
            ? BookingProvider::withoutGlobalScopes()
                ->where('workspace_id', $workspaceId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(40)
                ->get(['id', 'name', 'role_label', 'branch_id', 'service_ids'])
                ->toArray()
            : [];

        return InboxAgentToolExecutionResult::success(
            $this->name(),
            'get_booking_options',
            [
                'booking_enabled' => (bool) $setting->enabled,
                'timezone' => $setting->timezone,
                'today_local' => now($setting->timezone ?: 'Asia/Riyadh')->format('Y-m-d'),
                'default_duration_minutes' => (int) $setting->default_duration_minutes,
                'slot_interval_minutes' => (int) $setting->slot_interval_minutes,
                'min_advance_minutes' => (int) $setting->min_advance_minutes,
                'max_advance_days' => (int) $setting->max_advance_days,
                'allow_party_size' => (bool) $setting->allow_party_size,
                'require_staff' => (bool) $setting->require_staff,
                'require_branch' => (bool) $setting->require_branch,
                'auto_confirm' => (bool) $setting->auto_confirm,
                'working_hours' => $setting->working_hours ?? [],
                'holidays' => $setting->holidays ?? [],
                'custom_fields_schema' => $setting->custom_fields_schema ?? [],
                'services' => $services,
                'branches' => $branches,
                'providers' => $providers,
                'count' => count($services),
            ],
        );
    }
}
