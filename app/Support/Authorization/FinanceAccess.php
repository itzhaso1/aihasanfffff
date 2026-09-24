<?php

namespace App\Support\Authorization;

use App\Models\User;
use App\Models\Workspace;

class FinanceAccess
{
    public function __construct(
        private readonly WorkspaceAccess $workspaceAccess,
    ) {}

    /**
     * Owner/admin only. Manager is operational and must use Spatie keys.
     */
    public function isFinanceAdministrator(User $user, Workspace $workspace): bool
    {
        if ($this->workspaceAccess->isWorkspaceOwner($user, $workspace)) {
            return true;
        }

        return $this->workspaceAccess->hasAnyMembershipRole($user, $workspace, [
            WorkspaceAccess::ROLE_OWNER,
            WorkspaceAccess::ROLE_ADMIN,
        ]);
    }

    public function can(User $user, Workspace $workspace, string $permission): bool
    {
        if (! $this->workspaceAccess->isActiveMember($user, $workspace)
            && ! $this->workspaceAccess->isWorkspaceOwner($user, $workspace)) {
            return false;
        }

        if ($this->isFinanceAdministrator($user, $workspace)) {
            return true;
        }

        if ($user->can($permission)) {
            return true;
        }

        // Existing managers often have membership_role=manager without a useful
        // team-scoped Spatie grant. They keep the operational catalog only.
        return $this->workspaceAccess->hasAnyMembershipRole($user, $workspace, [
            WorkspaceAccess::ROLE_MANAGER,
        ]) && FoundationRolePermissions::allowsManager($permission);
    }

    /**
     * @return array<string, bool>
     */
    public function permissionMap(User $user, Workspace $workspace): array
    {
        $can = fn (string $permission): bool => $this->can($user, $workspace, $permission);

        return [
            'finance.view' => $can('finance.view'),
            'finance.settings' => $can('finance.settings'),
            'customers.view' => $can('finance.view') || $can('customers.manage') || $can('invoices.view'),
            'customers.create' => $can('customers.manage'),
            'customers.edit' => $can('customers.manage') || $can('invoices.create') || $can('finance.manage'),
            'customers.delete' => $can('customers.manage'),
            'quotes.view' => $can('quotes.view'),
            'quotes.create' => $can('quotes.create'),
            'quotes.edit' => $can('quotes.edit'),
            'quotes.issue' => $can('quotes.issue'),
            'quotes.send' => $can('quotes.send'),
            'quotes.accept' => $can('quotes.accept'),
            'quotes.reject' => $can('quotes.reject'),
            'quotes.convert' => $can('quotes.convert'),
            'quotes.cancel' => $can('quotes.cancel'),
            'quotes.delete' => $can('quotes.delete'),
            'invoices.view' => $can('invoices.view'),
            'invoices.create' => $can('invoices.create'),
            'invoices.edit' => $can('invoices.edit'),
            'invoices.issue' => $can('invoices.issue'),
            'invoices.send' => $can('invoices.send'),
            'invoices.remind' => $can('invoices.remind'),
            'invoices.cancel' => $can('invoices.cancel'),
            'invoices.delete' => $can('invoices.delete'),
            'invoices.credit' => $can('invoices.credit'),
            'invoices.reverse_payment' => $can('invoices.reverse_payment'),
            'payments.view' => $can('payments.view'),
            'payments.manage' => $can('payments.manage'),
            'receipts.view' => $can('receipts.view'),
            'receipts.send' => $can('receipts.send'),
            'statements.view' => $can('invoices.view'),
            'notes.view' => $can('invoices.view'),
            'notes.create' => $can('invoices.credit'),
            'notes.issue' => $can('invoices.credit'),
            'notes.cancel' => $can('invoices.cancel'),
            'contracts.view' => $can('contracts.view'),
            'contracts.create' => $can('contracts.manage') || $can('contracts.create'),
            'contracts.edit' => $can('contracts.manage') || $can('contracts.update') || $can('contracts.edit'),
            'contracts.update' => $can('contracts.manage') || $can('contracts.update') || $can('contracts.edit'),
            'contracts.activate' => $can('contracts.manage') || $can('contracts.activate'),
            'contracts.cancel' => $can('contracts.manage') || $can('contracts.cancel'),
            'contracts.renew' => $can('contracts.manage') || $can('contracts.renew'),
            'contracts.delete' => $can('contracts.manage') || $can('contracts.delete'),
            'contracts.attachments' => $can('contracts.manage') || $can('contracts.attachments'),
            'contracts.invoice' => $can('contracts.manage') || $can('contracts.invoice') || $can('invoices.create'),
            'contracts.payments' => $can('contracts.view') || $can('payments.view') || $can('contracts.payments'),
            'expenses.view' => $can('expenses.view'),
            'expenses.create' => $can('expenses.create'),
            'expenses.edit' => $can('expenses.edit'),
            'expenses.delete' => $can('expenses.edit'),
            'purchases.view' => $can('purchases.view'),
            'purchases.create' => $can('purchases.manage'),
            'purchases.edit' => $can('purchases.manage'),
            'reports.view' => $can('reports.view'),
            'exports.invoices' => $can('invoices.view'),
            'exports.payments' => $can('payments.view'),
            'exports.customers' => $can('invoices.view'),
            'exports.expenses' => $can('expenses.view'),
            'exports.quotes' => $can('quotes.view'),
            'accounting.view' => $can('accounting.view'),
            'accounting.manage' => $can('accounting.manage'),
            'finance.manage' => $can('finance.manage'),
            'finance.price_lists.view' => $can('finance.price_lists.view'),
            'finance.price_lists.manage' => $can('finance.price_lists.manage'),
            'finance.fiscal_years.view' => $can('finance.fiscal_years.view'),
            'finance.fiscal_years.manage' => $can('finance.fiscal_years.manage'),
            'payroll.view' => $can('payroll.view'),
            'payroll.manage' => $can('payroll.manage'),
            'finance.adjustments.view' => $can('finance.adjustments.view'),
            'finance.adjustments.manage' => $can('finance.adjustments.manage'),
            'finance.salary_advances.view' => $can('finance.salary_advances.view'),
            'finance.salary_advances.manage' => $can('finance.salary_advances.manage'),
        ];
    }
}
