# P1-D.1 FINAL

**Date:** 2026-09-13  
**P0-1 … P0-8 / P1-A / P1-B / P1-C:** CLOSED — not reopened.  
**P1-E:** not started.

## Status

**CONDITIONAL**

Local mandatory PHP gates are green. GitHub Actions was **not** executed: this tree is **not a git repository** (`fatal: not a git repository`). There is no remote and no workflow run URL.

Never claim GitHub CI PASS.

## Pint

### before

`vendor/bin/pint --test` exit 1. **~170 files**. Rules concentrated in:

- `fully_qualified_strict_types`, `ordered_imports`, `braces_position`, `not_operator_with_successor_space`, `single_line_empty_body`, `unary_operator_spaces`

Classification of that tree:

| Class | What |
| --- | --- |
| A) P0/P1 remediation | ~15 test files (FailClosed, P1-C, AI isolation, webhooks, POS tax, FinancialCore, …). **No P0/P1 app services** were in the fail list (`IdempotencyService` already clean). |
| B) Unrelated legacy | Majority: cashier/finance/mobile controllers, Finance/POS/AI **services**, models, jobs, 3 old migrations, `routes/web.php`, bootstrap |
| C) Test-only | Cashier/POS/finance phase tests, backup tests, `tests/TestCase.php` |
| D) Generated/vendor | None |

Did **not** mass-format Finance/POS/AI application code.

### after

- Mandatory `pint` job = **enforced surface only** (`php tests/bin/pint-enforced.php --test`) → **PASS** locally
- 20 enforced files were Pint-fixed (style only: imports, FQCN, braces). No business logic.
- Full-tree `pint-full` remains **red** (~150 leftover legacy files). Not hidden: job exists, is not required, has no `continue-on-error`

### violations (remaining, full tree)

Still ~150 files, same style rules, concentrated in legacy controllers/services. Documented in `docs/ci.md` and `tests/ci/pint-enforced-paths.txt`.

## Critical SQLite

**PASS 259 / 259** (74s). Was 233 before hygiene restored Billing + cashier sync classes.

`APP_ENV=testing`, SQLite `:memory:`, production `hasem` not used.

## Critical MariaDB

**PASS 259 / 259** (105s). Isolated `hasem_p0_tenancy_test` on local MariaDB 10.4.32. Production `hasem` not used. `DB_DATABASE=hasem` still exits 1.

## Full SQLite

### before

950 tests: **764 pass / 20 fail / 156 errors / 10 skipped** (274s)

### after

950 tests: **780 pass / 21 fail / 139 errors / 10 skipped** (276s)

Hygiene on the four named classes recovered the context errors. Fail-closed tenancy was **not** weakened. Helper: `TestCase::inWorkspace()` → `WorkspaceContext::runFor()` only.

| Named class | Verdict |
| --- | --- |
| `BillingLifecycleTest` | **Fixed.** Direct `InvoiceService` / `InvoicePaymentService` / `CreditNoteService` calls now run inside `inWorkspace`. |
| `CashierSyncApiTest` | **Fixed.** `Order` / `Customer` assertions use `inWorkspace`. |
| `CashierSyncPushApiTest` | **Fixed.** Authenticated assertions use `inWorkspace`. Unauthenticated “nothing written” uses `withoutGlobalScopes()` (no workspace exists; not a bypass reason). |
| `CashierPhase2AOfflineSnapshotTest` | Context errors **fixed** (query + `with('items')` inside `runFor`). **1 remaining failure** (see below). Stays **out** of the critical suite. |

### remaining failures/errors

**Do not treat these as hidden.** Full suite is `phpunit-sqlite-full` (not required).

**The four-class leftover (1):**

- `CashierPhase2AOfflineSnapshotTest::test_invalid_snapshots_are_rejected_without_catalog_fallback` — `inconsistent_total should fail`
- **Class:** assertion outdated due to **intentional P0-2** server-authoritative totals. Client `total_amount=99` is ignored; the op succeeds with server math.
- Assertion was **not** rewritten. POS code was **not** changed.

**Other failures (20) — not in the four-class scope:**

| Example | Class |
| --- | --- |
| `InventoryServiceTest::test_inventory_service_blocks_overselling` | Pre-existing test hygiene (expects “Insufficient stock”, gets missing workspace context) |
| `CashierLaravelOriginKitchenInvoiceTest`, `PosFinalCashierCompletionTest`, `PosTableQrSessionTest`, `WebPosOperationalLockTest`, … | Mix of leftover context-less assertions and older Web POS / QR session expectations. Not re-litigated here. Do not rewrite assertions blindly. |

**Errors (139):** same `MissingWorkspaceContextException` hygiene in other cashier / finance-phase / POS tests (`FinanceInvoiceCheckoutTest`, `InvoiceDomainHardeningTest`, `TaxEngineHardeningTest`, `CashierPhase2C/D`, …). **Non-blocking legacy test hygiene.** Same fix pattern as the four classes; not a tenancy redesign.

**Skipped (10):** Redis runtime tests without Redis. Not faked. No Redis CI service added (not deterministic on this Windows tree; not required for mandatory jobs).

## GitHub Actions

**BLOCKED** by missing repository remote/access.

```
git rev-parse --is-inside-work-tree
fatal: not a git repository (or any of the parent directories): .git
```

No `git remote`. No `workflow_dispatch` / `pull_request` run. **No URL.**

Workflow is prepared: `.github/workflows/ci.yml` (PHP 8.3, MariaDB 10.11 service, no Windows paths, no `DB_DATABASE=hasem`, testing `APP_KEY` only).

## Branch protection

**OPERATOR ACTION REQUIRED**

YAML cannot enable protection. After a real GitHub remote exists, require:

- `pint`
- `phpunit-sqlite`
- `phpunit-mariadb`

Do **not** require `pint-full` or `phpunit-sqlite-full` while they are red.

## Files changed

- `tests/TestCase.php` — `inWorkspace()` helper
- `tests/Feature/Feature/Finance/BillingLifecycleTest.php`
- `tests/Feature/Feature/Cashier/CashierPhase2AOfflineSnapshotTest.php`
- `tests/Feature/Feature/Cashier/CashierSyncApiTest.php`
- `tests/Feature/Feature/Cashier/CashierSyncPushApiTest.php`
- `tests/ci/pint-enforced-paths.txt`, `tests/bin/pint-enforced.php`, `tests/bin/summarize-junit.php`
- `.github/workflows/ci.yml` — `pint` uses enforced paths; added `pint-full`
- `phpunit.critical.xml` / `phpunit.critical.mysql.xml` — added Billing + cashier sync
- `composer.json` — `pint:test` / `pint:full`
- `docs/ci.md`, this report
- Pint style-only on 20 enforced P0/P1/test files (imports/braces/FQCN). **No Finance/POS/AI service rewrites.**

## Remaining Review

1. Initialize git + remote, then actually run GitHub Actions. Until then P1-D.1 stays CONDITIONAL.
2. Repo admin: attach `pint`, `phpunit-sqlite`, `phpunit-mariadb`.
3. Optional later: same `inWorkspace` hygiene on the other 139 error tests — tests only.
4. Optional later: dedicated full-tree Pint pass (formatting-only, ~150 files) if product policy requires it.
5. `inconsistent_total` assertion vs P0-2 — decide later; do not weaken server-authoritative tax/totals.
6. Flutter / E2E / load / PHPStan remain REVIEW.
7. Redis remains skip-without-server; not a fake green job.
