<?php

namespace App\Services\Finance\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use App\Services\Finance\Assistant\FinanceAssistantToolResult;
use App\Services\Finance\BusinessAlertService;
use Illuminate\Support\Facades\Validator;

final class FinanceAlertsTool implements FinanceAssistantTool
{
    use BuildsAssistantFacts;

    public function __construct(
        private readonly BusinessAlertService $businessAlertService,
    ) {}

    public function name(): string
    {
        return 'finance.alerts';
    }

    public function description(): string
    {
        return 'Business and finance alerts that need attention.';
    }

    public function permission(): string
    {
        return 'finance.view';
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

    public function execute(Workspace $workspace, User $user, array $arguments): FinanceAssistantToolResult
    {
        $today = now()->toDateString();
        $alerts = $this->businessAlertService->alerts((int) $workspace->id);

        return FinanceAssistantToolResult::available($this->name(), $this->factEnvelope(
            $workspace,
            'business_alerts',
            $today,
            $today,
            [
                'available' => true,
                'count' => count($alerts),
                'items' => $alerts,
            ],
        ));
    }
}
