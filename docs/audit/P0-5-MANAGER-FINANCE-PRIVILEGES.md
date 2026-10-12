# P0-5 FINAL

**Date:** 2026-09-13  
**P0-1 / P0-2 / P0-3 / P0-4:** CLOSED — not reopened.  
**Scope:** Manager finance privilege elevation / action-based least privilege only.

## Status

**CLOSED**

HTTP/API tests executed on SQLite and isolated MariaDB. Restricted Manager actions return **403**. Operational Manager actions still return **200/201**. Direct API calls (no UI) use the same `FinanceAccess` gate. Cashier/agent did not gain finance-admin keys. Cross-workspace Manager access remains denied. No destructive migration. Production DB `hasem` was not used.

P0-6 was not started.

---

## Before

Manager had two overlapping grants:

1. **Spatie `manager` template** (operational plus several sensitive keys):  
   `finance.view`, invoices view/create/edit/issue/send/remind, **`invoices.credit`**, **`invoices.reverse_payment`**, quotes (no cancel/delete), payments view/**manage**, receipts, expenses view/create/edit, **`accounting.view`**, reports, **`payroll.view`**, **`finance.adjustments.view`**, **`finance.salary_advances.view`**, fiscal years **view**, contracts view/**manage**, plus POS/customers/inventory.
2. **Membership elevation (the real hole):**  
   `FinanceBaseController::authorizeFinance` and `AuthorizesFinanceApi` treated active membership `owner|admin|manager` as permission to **every** finance action.  
   `financePermissionMap()` ORed `$elevated` onto **every** Flutter key.  
   `$user->can('workspace.manage')` also bypassed the requested permission (and did not require membership first).

So a Manager without `accounting.manage` could still post treasury transfers, close fiscal years, mutate payroll, change tax/settings, and issue credit notes.

Accountant was **not** in `workspace_users.membership_role` (maps to `member`) and was **not** elevated. Accountant only had the narrower Spatie list (no `accounting.manage` / `finance.settings` / period / payroll manage). Those admin actions only worked for owner/admin/manager via elevation.

Cashier/agent was never in finance elevation. `WorkspaceAccess::isElevated()` still includes manager for **inventory/POS** and was not changed.

---

## Security Finding

Manager was treated as Finance Admin at the membership layer. Spatie keys were advisory. The Flutter permission map repeated the same elevation, so hiding buttons in UI would not have contained the API.

Least privilege required:

- Finance administrator = **owner + admin** (plus `workspace.owner_user_id`)
- Manager = operational catalog only
- Every finance mutation checks authenticated user + active membership + `FinanceAccess::can()`
- No `workspace.manage` finance wildcard
- No `role === manager` grant of accounting-admin actions

---

## Permission Matrix

| Permission / action | Owner | Admin | Manager | Accountant | Staff | Cashier/agent | Platform Admin | Why |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| view finance summary / dashboard | ALLOW | ALLOW | ALLOW | ALLOW | DENY | DENY unless Spatie `finance.view` | separate guard | operational visibility |
| view / create / edit draft / issue / send invoice | ALLOW | ALLOW | ALLOW | ALLOW | DENY | DENY create; view if `invoices.view` | separate | operational sales |
| record invoice payment (`payments.manage`) | ALLOW | ALLOW | **REVIEW / ALLOW** | ALLOW | DENY | receptionist may have it | separate | operational collection; not treasury |
| operational invoice/payment/customer/quote export | ALLOW | ALLOW | ALLOW | ALLOW | DENY | DENY unless view key | separate | not GL/journal dump |
| view fiscal years | ALLOW | ALLOW | ALLOW | ALLOW | DENY | view key only | separate | calendar visibility |
| post/view journals (`accounting.view` / treasury post) | ALLOW | ALLOW | **DENY** | ALLOW | DENY | DENY | separate | posting authority |
| close/reopen fiscal year | ALLOW | ALLOW | **DENY** | ALLOW | DENY | DENY | separate | period control |
| treasury transfer / bank reconciliation | ALLOW | ALLOW | **DENY** | ALLOW | DENY | DENY | separate | treasury |
| payroll / adjustments / salary advances mutate | ALLOW | ALLOW | **DENY** | ALLOW | DENY | DENY | separate | payroll admin |
| tax / finance settings / chart-adjacent settings | ALLOW | ALLOW | **DENY** | ALLOW | DENY | DENY | separate | financial configuration |
| credit note / reverse payment | ALLOW | ALLOW | **DENY** | ALLOW | DENY | DENY | separate | refund/credit approval |
| cancel/delete invoice | ALLOW | ALLOW | **DENY** | cancel ALLOW | DENY | DENY | separate | destructive finance |
| sync financial permissions | ALLOW | ALLOW | **DENY** | DENY | DENY | DENY | separate | permission admin |
| `workspace.manage` / subscriptions / employees.manage | ALLOW | ALLOW | **DENY** | DENY | DENY | DENY | separate | not operational manager |
| inventory / POS / customers | ALLOW | ALLOW | ALLOW | DENY customers unless key | DENY | POS via cashier path | separate | unchanged operational |
| tenant finance via platform admin bypass | n/a | n/a | n/a | n/a | n/a | n/a | **not used** | platform remains isolated |

Surfaces: **Web** and **Finance API / Flutter** share `FinanceAccess`. **Mobile** finance is `/api/finance/v1`. **Cashier API** uses `AuthorizesCashier` (unchanged). **Platform Admin** stays on `platform_admin` guard.

---

## Changed

- `app/Support/Authorization/FinanceAccess.php` — membership first; owner/admin finance admin; else Spatie; else Manager membership **operational catalog only**.
- `app/Support/Authorization/FoundationRolePermissions.php` — deterministic Manager / Accountant lists.
- `FinanceBaseController` + `AuthorizesFinanceApi` — both call `FinanceAccess`. Dropped Manager elevation and `workspace.manage` finance wildcard.
- `FoundationSeeder` — `syncPermissions` from the catalog (repeatable, global roles `team_id` null).
- `permissions:sync-foundation-roles` — updates **manager + accountant only**. Does not reset owner/admin or custom-named roles.
- `WorkspaceAccess::isElevated()` **unchanged** (inventory/POS).
- No POS/tax, backup, Redis, tenancy, or UI redesign.
- No destructive migration.

Manager Spatie keys **removed** (proven accounting-admin / refund / payroll / books):  
`invoices.credit`, `invoices.reverse_payment`, `contracts.manage`, `payroll.view`, `finance.adjustments.view`, `finance.salary_advances.view`, `accounting.view`.

Accountant Spatie keys **added** so the specialist still works after elevation is gone:  
`finance.manage`, `finance.settings`, `accounting.manage`, fiscal year view/manage, journal view/create/reverse, payroll view/manage, adjustments + salary advances view/manage.

**Kept on Manager (do not collapse to Staff):**  
customers/POS/inventory, `finance.view`, invoices view/create/edit/issue/send/remind, operational quotes, `payments.manage` (REVIEW), receipts, expenses view/create/edit, `reports.view`, price lists view, fiscal years **view**, `contracts.view`.

---

## Existing Users

Roles are **global Spatie templates** (`roles.team_id` null). Users are assigned that role per workspace team pivot.

| Actor | Effect |
| --- | --- |
| Existing Manager (membership `manager`, with or without Spatie row) | Loses accounting-admin / treasury / period / payroll / credit-note / permission-admin. Keeps operational catalog via membership fallback even when the team-scoped Spatie grant is empty. |
| Re-run `FoundationSeeder` or `permissions:sync-foundation-roles` | Deterministically rewrites **global** `manager` and `accountant` templates. Custom-named roles are not touched. Per-user `syncPermissions` extras still add via `$user->can()`. |
| Owner / Admin | Unchanged: finance-admin elevation + seeder still syncs **all** permission rows onto those roles. |
| Accountant | Gains the admin-finance keys they need now that Manager elevation is gone. Still cannot manage workspace/permissions/subscriptions. |
| Custom workspace role names | Not overwritten. |
| A customized **global** `manager` role | **Will** be reset to the catalog on seeder/command. Documented. |

Audit: existing `workspace.role.assigned` / `workspace.permissions.synced` logs remain. Command logs `foundation.roles.synced` (role names + permission names, no secrets). No new audit subsystem.

---

## Tests

Command (SQLite `phpunit.xml`):

```text
php artisan test --testdox --filter="RolePermission|InventoryAuthorization|AuthenticationTest|PlatformAdminAuth|FinanceFlutterAuth|CashierPhase1AAuth|MobileAuthAndWorkspace|ManagerFinancePrivilege"
```

### SQLite

PASS: **54**  
FAIL: **0**  
SKIPPED: **0**  
ERRORS: **0**

Includes `ManagerFinancePrivilege` **19/19** (125 assertions in the isolated class run; 281 assertions in the full filter).

### MariaDB/MySQL

Probe: `php tests/bin/mysql-p0-probe.php` → `status=READY`, `test_database=hasem_p0_tenancy_test`, `version=10.4.32-MariaDB`.  
Not production `hasem`.

```text
php vendor/bin/phpunit -c phpunit.mysql.xml --testdox --filter="RolePermission|InventoryAuthorization|AuthenticationTest|PlatformAdminAuth|FinanceFlutterAuth|CashierPhase1AAuth|MobileAuthAndWorkspace|ManagerFinancePrivilege"
```

PASS: **54**  
FAIL: **0**  
SKIPPED: **0**  
ERRORS: **0**

---

## Sensitive Actions

### Manager allowed

- M1 finance summary — API `GET /api/finance/v1/dashboard` **200**, web dashboard **200**
- M2 view invoice — **200**
- M3 create operational invoice — **201**
- M4 edit draft invoice — **200**
- M14 operational invoice export — **200**
- Membership-only Manager (no Spatie assign) still gets M1 (catalog fallback)

### Manager denied (403, HTTP + web where asserted)

- M5/M6 journal / books — `GET /api/finance/v1/accounting`, web accounting dashboard
- M7 settings / chart-adjacent — `GET/PUT /api/finance/v1/settings`
- M8 close/reopen fiscal year — API + web
- M9 treasury transfer — API + web
- M10 bank statement / reconciliation create
- M11 payroll employees + adjustments
- M12 tax rates + web company settings
- M13 `POST /api/roles-permissions/sync-permissions`
- M15 credit notes — `POST /api/finance/v1/credit-notes`

### Matrix (executed, not UI)

| Case | Result |
| --- | --- |
| A allowed Manager action | 200/201 |
| B restricted Manager action | 403 |
| C unauthenticated dashboard | 401 |
| D Manager A on Workspace B | 403 |
| E Cashier/agent finance-admin | 403 (treasury, settings, fiscal close, payroll, credit note) |
| F Owner same admin action | allowed (200/201 or not 403 when payload is incomplete) |
| G Accountant where intended | allowed (settings, tax, accounting, fiscal, payroll, credit note, treasury auth) |
| H direct API | same `FinanceAccess` as web |

Flutter login permission map for Manager: `finance.view` / `invoices.create` true; `finance.settings`, `accounting.manage`, `finance.fiscal_years.manage`, `payroll.manage`, `invoices.credit`, `notes.create` false. Owner map still has `accounting.manage`.

---

## Remaining Review

- **`payments.manage` on Manager** — kept for operational invoice collection. Not treasury.
- **Treasury index** still gated by `finance.view` (Manager can open the page, cannot transfer/reconcile). Tighten to `accounting.view` later if product wants hide-not-just-block.
- **`journal.*` Spatie keys** exist but no controller uses them yet; posting is `accounting.manage` / domain services.
- **Per-user extra grants** (`syncPermissions`) can still add a dangerous key to a Manager. That is custom, not the template. Owner/admin only may sync.
- **Global `manager` template reset** on seeder/command if someone customized that exact role name.
- Master spec still mentions old owner/admin/manager elevation in one line; behavior is now owner/admin only.

P0-6 not started.
