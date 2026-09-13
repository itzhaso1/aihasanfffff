<?php

namespace App\Services\Projects;

use App\Models\Customer;
use App\Models\Finance\FinanceExpense;
use App\Models\Finance\FinanceInvoice;
use App\Models\Projects\FinanceProject;
use App\Models\Workspace;
use App\Support\Money\Money;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProjectService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(Workspace $workspace, array $payload): FinanceProject
    {
        $customerId = isset($payload['customer_id']) ? (int) $payload['customer_id'] : null;
        if ($customerId) {
            $exists = Customer::withoutGlobalScopes()
                ->where('workspace_id', $workspace->id)
                ->whereKey($customerId)
                ->exists();
            if (! $exists) {
                throw new RuntimeException('Customer is invalid for this workspace.');
            }
        }

        return FinanceProject::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customerId,
            'name' => trim((string) $payload['name']),
            'status' => $payload['status'] ?? 'active',
            'budget' => $payload['budget'] ?? 0,
            'starts_on' => $payload['starts_on'] ?? null,
            'ends_on' => $payload['ends_on'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function profitability(FinanceProject $project): array
    {
        $revenue = '0.00';
        $costs = '0.00';

        if (Schema::hasColumn('finance_invoices', 'project_id')) {
            $revenue = Money::of(FinanceInvoice::withoutGlobalScopes()
                ->where('workspace_id', $project->workspace_id)
                ->where('project_id', $project->id)
                ->where('type', 'sales')
                ->whereIssued()
                ->sum('taxable_amount'));
        }

        if (Schema::hasColumn('finance_expenses', 'project_id')) {
            $costs = Money::of(FinanceExpense::withoutGlobalScopes()
                ->where('workspace_id', $project->workspace_id)
                ->where('project_id', $project->id)
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->sum('total'));
        }

        return [
            'revenue' => $revenue,
            'costs' => $costs,
            'profit' => Money::sub($revenue, $costs),
        ];
    }
}
