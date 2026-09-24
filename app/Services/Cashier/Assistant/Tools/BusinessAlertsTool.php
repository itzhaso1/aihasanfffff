<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Cashier\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Cashier\Assistant\Contracts\CashierAssistantTool;
use App\Services\Finance\BusinessAlertService;
use Illuminate\Support\Facades\Validator;

final class BusinessAlertsTool implements CashierAssistantTool
{
    use BuildsAssistantFacts;

    public function __construct(
        private readonly BusinessAlertService $businessAlertService,
    ) {}

    public function name(): string
    {
        return 'business.alerts';
    }

    public function description(): string
    {
        return 'Operational and finance alerts that need attention.';
    }

    public function permission(): string
    {
        return 'reports.view';
    }

    public function parameterSchema(): array
    {
        return [];
    }

    public function validateArguments(array $arguments): array
    {
        Validator::validate($arguments, []);

        return [];
    }

    public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult
    {
        $today = now()->toDateString();
        $alerts = $this->businessAlertService->alerts((int) $workspace->id);

        return CashierAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'business_alerts',
            $today,
            $today,
            [
                'available' => true,
                'items' => $alerts,
            ],
        ));
    }
}
