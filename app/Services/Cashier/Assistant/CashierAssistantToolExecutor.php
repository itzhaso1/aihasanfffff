<?php

namespace App\Services\Cashier\Assistant;

use App\Models\User;
use App\Models\Workspace;
use InvalidArgumentException;

final class CashierAssistantToolExecutor
{
    public function __construct(
        private readonly CashierAssistantToolRegistry $registry,
    ) {}

    /**
     * @param  array{name: string, arguments?: array<string, mixed>}  $plan
     */
    public function execute(Workspace $workspace, User $user, array $plan): CashierAssistantToolResult
    {
        $name = trim((string) ($plan['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Tool name is required.');
        }

        $tool = $this->registry->get($name);
        if (! $this->userCanRunTool($user, $workspace, $tool->permission())) {
            return CashierAssistantToolResult::denied($name);
        }

        $arguments = is_array($plan['arguments'] ?? null) ? $plan['arguments'] : [];

        try {
            $validated = $tool->validateArguments($arguments);
        } catch (PeriodNotSupportedException $exception) {
            return CashierAssistantToolResult::unavailable($name, 'period_not_supported', [
                'requested_period' => $exception->requestedPeriod,
            ]);
        }

        return $tool->execute($workspace, $user, $validated);
    }

    private function userCanRunTool(User $user, Workspace $workspace, string $permission): bool
    {
        if ($user->can($permission) || $user->can('workspace.manage')) {
            return true;
        }

        return $workspace->users()
            ->where('users.id', $user->id)
            ->wherePivot('status', 'active')
            ->wherePivotIn('membership_role', ['owner', 'admin', 'manager'])
            ->exists();
    }
}
