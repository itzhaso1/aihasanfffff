# P1-D FINAL

**Date:** 2026-09-13  
**P0-1 … P0-8 / P1-A / P1-B / P1-C:** CLOSED — not reopened.  
**Scope:** CI, PHP quality gates, isolated test databases, blocking security/finance/POS regressions, artifacts. No UI, product, finance/POS/tenancy/Redis/backup/AI/legal redesign.

## Status

**CONDITIONAL**

First-party CI now exists (`.github/workflows/ci.yml`) and the **local equivalents** of the mandatory PHP jobs were executed on this machine. That is not the same as a green GitHub Actions run on `pull_request` / `main`.

P1-D is not CLOSED because:

1. GitHub Actions was **not** executed on GitHub (this tree has no usable git remote / `git rev-parse` failed). A YAML file is not “CI ready.”
2. The `pint` job is blocking and **fails on the current tree** (`vendor/bin/pint --test` exit 1, 150+ existing style files). No mass reformat of Finance/POS/AI was done.
3. The **full** SQLite PHPUnit suite is **not** green (950 tests: 764 passed, 156 errors, 20 failures, 10 skipped). It is a non-required job (`phpunit-sqlite-full`), not silently replaced by ten tests.
4. PHPStan/Larastan, Flutter, E2E, and load testing are documented as **REVIEW** / **BLOCKED**, not faked green.
5. Required GitHub status checks must be enabled by a repo admin. YAML cannot do that.

Mandatory **PHP** gates that **did** run locally and passed:

- Isolated SQLite `migrate:fresh` + `migrate:status`
- Isolated MariaDB `hasem_p0_tenancy_test` `migrate:fresh` + `migrate:status` (10.4.32-MariaDB)
- Critical PHPUnit SQLite: **233 passed**
- Critical PHPUnit MariaDB: **233 passed**
- `DB_DATABASE=hasem` refused (exit 1)
- Intentional failing PHPUnit probe exited 1, then the probe file was deleted

---

## CI Providers / Workflows

| Provider | Present? |
| --- | --- |
| GitHub Actions | **Yes** — `.github/workflows/ci.yml` (added this phase) |
| GitLab CI | No |
| Other first-party CI | No (vendor/node_modules workflows ignored) |

Triggers: `pull_request`, `push` to `main`/`master`, `workflow_dispatch`.

Linux `ubuntu-latest` only. No `C:\xampp\`, `C:\projects\`, or `D:\projects\` paths.

## Jobs

| Job name | Mandatory? | What it does | Local evidence |
| --- | --- | --- | --- |
| `pint` | Yes (will be **red** today) | `composer install --no-scripts` + `vendor/bin/pint --test` | Exit 1 on full tree; new CI helpers pass Pint |
| `phpunit-sqlite` | Yes | Refuse `hasem` → `php artisan --version` → file-SQLite `migrate:fresh` → `phpunit.critical.xml` | **PASS** 233 / 0 fail / 0 skip, 53s |
| `phpunit-mariadb` | Yes | MariaDB 10.11 service, DB `hasem_p0_tenancy_test`, `migrate:fresh`, `phpunit.critical.mysql.xml` | **PASS** 233 / 0 fail / 0 skip, 94s (local MariaDB 10.4.32) |
| `phpunit-sqlite-full` | No | Full `phpunit.xml` | **FAIL** 764 pass / 156 errors / 20 fail / 10 skip, 274s |

No `continue-on-error` on any of these jobs.  
No `static-analysis` job (would be a fake gate).

## PHP Versions

- Project: `composer.json` `"php": "^8.3"`
- CI matrix: **8.3 only**
- Local runner: PHP 8.3.33

A second PHP version was not added.

## Databases

### SQLite

- PHPUnit: isolated `:memory:` via `phpunit.xml` / `phpunit.critical.xml` with `force="true"` so a workstation `DB_DATABASE=hasem` cannot leak in
- Migration proof: file `database/ci-migrate.sqlite` (gitignored `*.sqlite*`), `APP_ENV=testing`, testing `APP_KEY`
- Production `.env` not used by PHPUnit (forced env)

### MariaDB

- Isolated name: **`hasem_p0_tenancy_test`** (same as `phpunit.mysql.xml`)
- CI service: `mariadb:10.11`, `MARIADB_DATABASE=hasem_p0_tenancy_test`, root password only for the container (`ci`)
- Local proof: MariaDB **10.4.32**, `php tests/bin/mysql-p0-probe.php` → `status=READY`, `configured_database=hasem_p0_tenancy_test`
- Production **`hasem` was not used**
- Full suite is SQLite-only in CI; MariaDB runs the **critical** suite (documented, not a silent skip of DB-specific tests inside PHPUnit)

## Mandatory Gates

### PHPUnit

**Blocking critical suite** (existing classes only):

`FailClosedWorkspaceTenancyTest`, `WorkspaceIsolationTest`, `RolePermissionAuthorizationTest`, `AuthenticationTest`, `RegistrationTest`, `PlatformAdminAuthTest`, `ApiKeyAuthorizationTest`, `ApiKeyManagementTest`, `WebhookReplayProtectionTest`, `PaymentWebhookSecurityTest`, `PaymentWebhookTenancyTest`, `WebhookFailClosedSecretTest`, `SubscriptionSecurityPhase1Test`, `AIServiceWorkspaceIsolationTest`, `ManagerFinancePrivilegeTest`, `TenantEmailAttachmentPushIsolationTest`, `P1CRemainingTenantIdorTest`, `MobileAuthAndWorkspaceTest`, `FinancialCoreTest`, `FinanceKpiLedgerReconciliationTest`, `ExpenseAccountingIntegrityTest`, `PlatformCoreTest`, `TaxEngineTest`, `PosServerAuthoritativeTaxAndIdempotencyTest`, `AiSafetyAndUsageTest`, `AiSecurityPrivacyCostTest`, `AssistantChatTest`, `LegalPagesTest`, `DatabaseBackupCommandTest`, `ProductionRuntimeTest`

Not in the critical suite (stay in full suite; isolated-run errors — app tenancy was **not** changed):

- `BillingLifecycleTest` (2 methods)
- `CashierPhase2AOfflineSnapshotTest`
- `CashierSyncApiTest`
- `CashierSyncPushApiTest`

Those fail with `MissingWorkspaceContextException` when the test queries tenant models without workspace context.

### Pint

Configured and **blocking**. `vendor/bin/pint --test` on the whole tree **fails**.  
No auto-fix in CI. No committed mass format. New files `tests/bin/assert-not-production-database.php`, `tests/bin/ci-migrate-fresh.php`, `tests/bootstrap.php` pass `pint --test`.

### Static analysis

**REVIEW.** PHPStan/Larastan is not in `require-dev` and has no project config. Introducing it at a useful level is not a safe P1 cleanup. CI does **not** claim a static-analysis check.

Policy: `docs/ci.md`.

## Security Regression

Blocking via `phpunit.critical.xml` / `phpunit.critical.mysql.xml`:

| Contract | Covered by |
| --- | --- |
| No-context tenancy fails closed | `FailClosedWorkspaceTenancyTest` |
| Cross-tenant access denied | `WorkspaceIsolationTest`, `P1CRemainingTenantIdorTest` |
| Manager finance elevation denied | `ManagerFinancePrivilegeTest` |
| Webhook missing secret denied | `WebhookFailClosedSecretTest` |
| Invalid webhook signature denied | `PaymentWebhookSecurityTest`, `WebhookReplayProtectionTest` |
| Duplicate POS sync / client tax cannot override | `PosServerAuthoritativeTaxAndIdempotencyTest` |
| Idempotency response cannot cross workspace | `P1CRemainingTenantIdorTest` |
| Attachment download cannot cross workspace | `TenantEmailAttachmentPushIsolationTest` |
| AI cannot access another workspace | `AIServiceWorkspaceIsolationTest` |
| Public AI cost / rate limit | `AiSafetyAndUsageTest`, `AiSecurityPrivacyCostTest` |
| Legal pages: no forbidden claims/placeholders | `LegalPagesTest` |

Cashier sync/offline snapshot classes are **not** in this blocking list (see errors above). POS tax/idempotency remains covered by `PosServerAuthoritativeTaxAndIdempotencyTest`.

## Migration Verification

| Engine | Command | Result |
| --- | --- | --- |
| SQLite file | `php tests/bin/ci-migrate-fresh.php` | Exit 0; includes `2026_09_13_170000_p1c_idempotency_workspace_bound` |
| MariaDB isolated | same, `DB_DATABASE=hasem_p0_tenancy_test` | Exit 0; `migrate:status` all Ran |

Migration history was not rewritten.

## Secrets / Safety

- `tests/bootstrap.php` + `tests/bin/assert-not-production-database.php` refuse `hasem` / MySQL system schemas
- Probe: unset DB → exit 0; `DB_DATABASE=hasem` → exit 1
- PHPUnit `force="true"` on `APP_ENV`, `APP_KEY`, `DB_CONNECTION`, `DB_DATABASE`
- Workflow `APP_KEY` is the **public Laravel testing key** already in `phpunit.xml` — not a production secret
- No webhook, payment, or AI provider secrets in the workflow
- MariaDB CI password `ci` is the **container-only** test password, not production
- Composer cache only (`~/.composer/cache`). No `.env`, dumps, or backup artifacts cached
- `composer.lock` is used (`--prefer-dist`)

## Artifacts

On **failed** CI jobs, GitHub uploads `storage/logs/ci/` (JUnit XML + testdox). Never `.env`, secrets, or SQL dumps.

Local artifacts written this run (gitignored under `storage/logs/ci/`):

- `phpunit-sqlite.xml` / `phpunit-sqlite-testdox.txt`
- `phpunit-mariadb.xml` / `phpunit-mariadb-testdox.txt`
- `phpunit-sqlite-full.xml` / `phpunit-sqlite-full-testdox.txt`

## Flutter

**REVIEW / BLOCKED**

Apps: `apps/hasim`, `apps/hasim_finance`, `apps/hasim_cashier`. No first-party Flutter workflow. No Linux Flutter CI was made mandatory. No fake green Flutter job.

## E2E / Load

**E2E = REVIEW** — no first-party Dusk or Playwright.  
**Load = REVIEW** — no k6/locust/load suite. PHPUnit is not load testing.

## Test Results

### Critical SQLite (`phpunit.critical.xml`) — mandatory

| | |
| --- | --- |
| PASS | 233 |
| FAIL | 0 |
| SKIPPED | 0 |
| ERRORS | 0 |
| Runtime | 53s |

### Critical MariaDB (`phpunit.critical.mysql.xml`) — mandatory

| | |
| --- | --- |
| PASS | 233 |
| FAIL | 0 |
| SKIPPED | 0 |
| ERRORS | 0 |
| Runtime | 94s |
| DB | `hasem_p0_tenancy_test` (not `hasem`) |

### Full SQLite (`phpunit.xml`) — not required

| | |
| --- | --- |
| PASS | 764 |
| FAIL | 20 |
| SKIPPED | 10 |
| ERRORS | 156 |
| Tests | 950 |
| Runtime | 274s |

Redis runtime tests skip without Redis (included in the 10 skipped on the full suite). They are not in the mandatory critical list.

### Acceptance probe

`tests/Unit/CiGateProbeFailTest.php` was added, run, failed (`exit 1`, message `P1-D CI probe: mandatory PHPUnit failure must exit non-zero`), then **deleted**. It is not in the tree.

### Pint

Full tree: **FAIL** (exit 1). CI helpers: **PASS**.

## Remaining Review

1. Push this tree to GitHub (or another host) and actually run Actions. Until then, do not call the product “CI ready.”
2. Enable required status checks: `pint`, `phpunit-sqlite`, `phpunit-mariadb`. Do **not** require `phpunit-sqlite-full` while it is red. Do **not** require a `static-analysis` name that does not exist.
3. Dedicated Pint pass (or an agreed subset) so `pint` can go green without rewriting product logic in this P1.
4. Full-suite isolation errors (`MissingWorkspaceContextException` in older cashier/billing tests) — test hygiene, **not** a tenancy redesign. Out of P1-D.
5. PHPStan/Larastan introduction + baseline — later, not faked.
6. Flutter CI when a reproducible Linux (or documented Windows) runner exists.
7. E2E and load programs — later. Do not treat PHPUnit as either.
8. Human must attach the workflow to branch protection after a real git remote exists.

---

**Files added/updated this phase:** `.github/workflows/ci.yml`, `phpunit.critical.xml`, `phpunit.critical.mysql.xml`, `tests/bootstrap.php`, `tests/bin/assert-not-production-database.php`, `tests/bin/ci-migrate-fresh.php`, `phpunit.xml` / `phpunit.mysql.xml` (bootstrap + `force="true"`), `composer.json` scripts, `docs/ci.md`, `storage/logs/ci/.gitignore`, this report.
