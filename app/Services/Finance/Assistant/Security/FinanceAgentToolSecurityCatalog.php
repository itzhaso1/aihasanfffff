<?php

namespace App\Services\Finance\Assistant\Security;

use App\Enums\Finance\FinanceAgentToolAccessMode;
use App\Enums\Finance\FinanceAgentToolRiskLevel;
use App\Services\Finance\Assistant\Contracts\FinanceAssistantTool;
use InvalidArgumentException;

/**
 * Authoritative security metadata for every registered Finance assistant tool.
 */
final class FinanceAgentToolSecurityCatalog
{
    /**
     * @return array<string, array{permission: string, audit: string, profile: string}>
     */
    public function definitions(): array
    {
        return [
            'sales.today' => $this->row('reports.view', 'sales', 'scalar'),
            'sales.period' => $this->row('reports.view', 'sales', 'scalar'),
            'expenses.period' => $this->row('finance.view', 'expenses', 'scalar'),
            'expenses.by_category' => $this->row('finance.view', 'expenses', 'list'),
            'profit.net' => $this->row('finance.view', 'profit', 'scalar'),
            'profit.gross' => $this->row('finance.view', 'profit', 'scalar'),
            'profit.cogs' => $this->row('finance.view', 'profit', 'scalar'),
            'payroll.period' => $this->row('payroll.view', 'payroll', 'scalar'),
            'payroll.total' => $this->row('payroll.view', 'payroll', 'scalar'),
            'payroll.employees_count' => $this->row('payroll.view', 'payroll', 'scalar'),
            'suppliers.payable' => $this->row('finance.view', 'payables', 'summary'),
            'inventory.value' => $this->row('finance.view', 'inventory', 'scalar'),
            'customers.top' => $this->row('reports.view', 'customers', 'list'),
            'products.top' => $this->row('reports.view', 'products', 'list'),
            'compare.periods' => $this->row('finance.view', 'compare', 'comparison'),
            'finance.summary' => $this->row('finance.view', 'summary', 'summary'),
            'finance.alerts' => $this->row('finance.view', 'alerts', 'list'),
            'invoices.lookup' => $this->row('invoices.view', 'invoices', 'entity'),
            'invoices.details' => $this->row('invoices.view', 'invoices', 'entity'),
            'invoices.overdue' => $this->row('invoices.view', 'invoices', 'list'),
            'invoices.by_customer' => $this->row('invoices.view', 'invoices', 'list'),
            'customers.invoices' => $this->row('invoices.view', 'invoices', 'list'),
            'invoices.summary' => $this->row('invoices.view', 'invoices', 'summary'),
            'invoices.count' => $this->row('invoices.view', 'invoices', 'scalar'),
            'customers.lookup' => $this->row('finance.view', 'customers', 'entity'),
            'customers.balance' => $this->row('finance.view', 'customers', 'entity'),
            'customers.overdue' => $this->row('finance.view', 'customers', 'list'),
            'customers.statement' => $this->row('invoices.view', 'customers', 'statement'),
            'contracts.lookup' => $this->row('contracts.view', 'contracts', 'entity'),
            'contracts.details' => $this->row('contracts.view', 'contracts', 'entity'),
            'contracts.balance' => $this->row('contracts.view', 'contracts', 'entity'),
            'contracts.invoices' => $this->row('contracts.view', 'contracts', 'list'),
            'contracts.unbilled' => $this->row('contracts.view', 'contracts', 'entity'),
            'contracts.schedules' => $this->row('contracts.view', 'contracts', 'list'),
            'billing_schedules.upcoming' => $this->row('contracts.view', 'billing', 'list'),
        ];
    }

    public function profileFor(FinanceAssistantTool $tool): FinanceAgentToolSecurityProfile
    {
        $name = $tool->name();
        $definition = $this->definitions()[$name] ?? null;
        if ($definition === null) {
            throw new InvalidArgumentException("Finance tool [{$name}] has no security metadata.");
        }

        if ($definition['permission'] !== $tool->permission()) {
            throw new InvalidArgumentException("Finance tool [{$name}] permission drifted from the security catalog.");
        }

        return new FinanceAgentToolSecurityProfile(
            name: $name,
            permission: $definition['permission'],
            riskLevel: FinanceAgentToolRiskLevel::Read,
            accessMode: FinanceAgentToolAccessMode::Read,
            maxCallsPerRun: max(1, (int) config('finance_assistant.security.max_calls_per_tool', 3)),
            auditCategory: $definition['audit'],
            resultProfile: $definition['profile'],
        );
    }

    public function assertRegistryCovered(array $toolNames): void
    {
        $expected = array_keys($this->definitions());
        sort($expected);
        $actual = array_values($toolNames);
        sort($actual);

        if ($expected !== $actual) {
            throw new InvalidArgumentException('Finance tool security catalog does not match the registry.');
        }
    }

    /**
     * @return array{permission: string, audit: string, profile: string}
     */
    private function row(string $permission, string $audit, string $profile): array
    {
        return [
            'permission' => $permission,
            'audit' => $audit,
            'profile' => $profile,
        ];
    }
}
