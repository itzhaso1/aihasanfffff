<?php

namespace App\Support\Authorization;

/**
 * Deterministic Spatie permission lists for global foundation roles.
 * Owner/admin still receive every permission row (see FoundationSeeder).
 */
final class FoundationRolePermissions
{
    /**
     * Operational manager: visibility + sales operations. Not finance admin.
     *
     * @return list<string>
     */
    public static function manager(): array
    {
        return [
            'workspace.view',
            'products.manage',
            'inventory.manage',
            'customers.manage',
            'orders.manage',
            'pos.manage',
            'tables.manage',
            'menu.manage',
            'conversations.manage',
            'chat.catalog.view',
            'chat.catalog.manage',
            'commerce.catalog.view',
            'commerce.catalog.manage',
            'commerce.catalog.settings.manage',
            'commerce.catalog.items.manage',
            'commerce.catalog.categories.manage',
            'commerce.catalog.inventory.view',
            'commerce.catalog.inventory.manage',
            'commerce.catalog.digital_inventory.view',
            'commerce.catalog.digital_inventory.manage',
            'commerce.cart.view',
            'commerce.cart.manage',
            'commerce.orders.view',
            'commerce.orders.manage',
            'commerce.orders.cancel',
            'commerce.intelligence.view',
            'commerce.intelligence.manage',
            'commerce.intelligence.evaluate',
            'payments.manage',
            'finance.view',
            'finance.sales.view',
            'finance.price_lists.view',
            'finance.fiscal_years.view',
            'invoices.view',
            'invoices.create',
            'invoices.edit',
            'invoices.issue',
            'invoices.send',
            'invoices.remind',
            'quotes.view',
            'quotes.create',
            'quotes.edit',
            'quotes.issue',
            'quotes.send',
            'quotes.accept',
            'quotes.reject',
            'quotes.convert',
            'payments.view',
            'receipts.view',
            'receipts.send',
            'expenses.view',
            'expenses.create',
            'expenses.edit',
            'reports.view',
            'contracts.view',
        ];
    }

    /**
     * Accountant: books, periods, payroll, settings. Not workspace/permission admin.
     *
     * @return list<string>
     */
    public static function accountant(): array
    {
        return [
            'workspace.view',
            'finance.view',
            'finance.manage',
            'finance.settings',
            'invoices.view',
            'invoices.create',
            'invoices.edit',
            'invoices.issue',
            'invoices.send',
            'invoices.credit',
            'invoices.cancel',
            'invoices.reverse_payment',
            'invoices.remind',
            'quotes.view',
            'quotes.create',
            'quotes.edit',
            'quotes.issue',
            'quotes.send',
            'quotes.accept',
            'quotes.reject',
            'quotes.convert',
            'quotes.cancel',
            'payments.view',
            'payments.manage',
            'receipts.view',
            'receipts.send',
            'expenses.view',
            'expenses.create',
            'expenses.edit',
            'accounting.view',
            'accounting.manage',
            'reports.view',
            'contracts.view',
            'contracts.manage',
            'finance.fiscal_years.view',
            'finance.fiscal_years.manage',
            'journal.view',
            'journal.create',
            'journal.reverse',
            'payroll.view',
            'payroll.manage',
            'finance.adjustments.view',
            'finance.adjustments.manage',
            'finance.salary_advances.view',
            'finance.salary_advances.manage',
        ];
    }

    public static function allowsManager(string $permission): bool
    {
        return in_array($permission, self::manager(), true);
    }

    /**
     * @return list<string>
     */
    public static function dangerousFinancePermissions(): array
    {
        return [
            'accounting.manage',
            'finance.settings',
            'finance.manage',
            'finance.fiscal_years.manage',
            'finance.price_lists.manage',
            'journal.create',
            'journal.reverse',
            'payroll.manage',
            'finance.adjustments.manage',
            'finance.salary_advances.manage',
            'invoices.credit',
            'invoices.reverse_payment',
            'invoices.cancel',
            'invoices.delete',
            'workspace.manage',
            'employees.manage',
            'subscriptions.manage',
        ];
    }
}
