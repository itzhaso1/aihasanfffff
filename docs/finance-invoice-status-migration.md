# Finance Invoice Status Migration (Phase 1)

## Legacy status values (existing `finance_invoices.status`)

- `draft`
- `sent`
- `unpaid`
- `partial`
- `paid`
- `overdue`
- `cancelled`

## New split model

- `invoice_status`: `draft | issued | cancelled`
- `payment_status`: `unpaid | partial | paid | overdue`

## Safe mapping strategy used

The migration **does not delete** the legacy `status` column.

Mapping to `invoice_status`:

- `draft` -> `draft`
- `cancelled` -> `cancelled`
- all other legacy states (`sent`, `unpaid`, `partial`, `paid`, `overdue`) -> `issued`

Mapping to `payment_status`:

- Derived from financial facts (`total`, `amount_paid`, `due_date`) using tolerance `0.009`
- `paid` when `amount_due <= tolerance`
- `partial` when `amount_paid > tolerance` and amount remains
- `overdue` when invoice is `issued`, has remaining due, and `due_date` is past
- otherwise `unpaid`

## Recommended pre/post migration checks

Before migration:

```bash
php artisan finance:invoices:integrity-report
php artisan finance:invoices:status-audit
```

After migration:

```bash
php artisan finance:invoices:integrity-report
php artisan finance:invoices:status-audit
php artisan finance:invoices:refresh-payment-status
```

These checks verify counts and totals remain intact and payment status is refreshed from authoritative values.
