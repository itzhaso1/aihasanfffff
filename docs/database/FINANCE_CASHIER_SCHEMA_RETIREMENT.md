# Finance & cashier schema retirement (operations)

Hasim Finance and Hasim Cashier application code, APIs, and Flutter apps were removed from this repository. **Database tables created by historical migrations are not dropped automatically.**

## Why migrations remain in git

- Laravel migration order depends on existing files. Several commerce and appointment migrations reference `finance_invoices` or `pos_cashier_invoices` foreign keys.
- CI runs `migrate:fresh` against SQLite and MariaDB; removing migration files without replacing FK definitions would break fresh installs.
- Production databases may already contain data in legacy tables.

## Legacy table families (non-exhaustive)

- `finance_*` (invoices, expenses, ledger, payroll, AI conversation tables, etc.)
- `pos_cashier_invoices`, `pos_cashier_invoice_items`
- Nullable FK columns on `orders` (`finance_invoice_id`, `pos_cashier_invoice_id`) and related indexes

## Recommended production cleanup (separate change)

1. Confirm no runtime code reads or writes these tables (this repo no longer does).
2. Export/archive data if required for compliance.
3. Ship an **additive** retirement migration in a controlled release:
   - Drop foreign keys from active domain tables first (`orders`, appointments, etc.).
   - Drop or rename legacy tables in dependency-safe order.
4. Update backup/restore allowlists if table names change.

Do **not** run `migrate:fresh` or destructive drops against production from local development.

## Active code that still mentions legacy names

- `Order` model fillable includes legacy column names (documented as unused by chat commerce).
- `Payment::isFinanceInvoiceBillable()` naming reflects historical billable types; commerce payments use order/checkout paths.
- `DatabaseRestoreService` may list legacy table names for restore drills.

These are compatibility shims, not restored product features.
