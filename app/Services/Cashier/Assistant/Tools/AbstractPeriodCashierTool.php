<?php

namespace App\Services\Cashier\Assistant\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Cashier\Assistant\CashierAssistantPeriodResolver;
use App\Services\Cashier\Assistant\CashierAssistantToolResult;
use App\Services\Cashier\Assistant\Concerns\BuildsAssistantFacts;
use App\Services\Cashier\Assistant\Contracts\CashierAssistantTool;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class AbstractPeriodCashierTool implements CashierAssistantTool
{
    use BuildsAssistantFacts;

    public function __construct(
        protected readonly CashierAssistantPeriodResolver $periodResolver,
    ) {}

    public function parameterSchema(): array
    {
        return [
            'preset' => 'optional: today|week|month|last_month',
            'from' => 'optional ISO date YYYY-MM-DD',
            'to' => 'optional ISO date YYYY-MM-DD',
            'limit' => 'optional integer 1-50 for ranked lists',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function validateArguments(array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'preset' => ['nullable', 'string', 'max:32'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $period = $this->periodResolver->resolve(
            $validated['preset'] ?? null,
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        return [
            ...$validated,
            'period' => $period,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function periodFromArguments(array $arguments): array
    {
        if (! isset($arguments['period']) || ! is_array($arguments['period'])) {
            throw ValidationException::withMessages(['period' => ['Period is required.']]);
        }

        return $arguments['period'];
    }

    abstract public function execute(Workspace $workspace, User $user, array $arguments): CashierAssistantToolResult;
}
