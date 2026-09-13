# HASEM CI policy

Audit close-out: `docs/audit/P1-D.1-CI-GREEN-WITHOUT-HIDING.md` (P1-D.1 = **CONDITIONAL** — GitHub Actions not executed; no git remote).

First-party GitHub Actions workflow: `.github/workflows/ci.yml`.

Triggers: `pull_request`, `push` to `main`/`master`, and `workflow_dispatch`.

This file is the honesty layer for gates that are **not** claimed green.

## Mandatory checks (must be required on the protected branch)

| Check | Job name | Blocks merge |
| --- | --- | --- |
| Laravel Pint on the enforced P0/P1 + CI surface (`php tests/bin/pint-enforced.php --test`) | `pint` | Yes |
| Critical PHPUnit on isolated SQLite | `phpunit-sqlite` | Yes |
| Critical PHPUnit on isolated MariaDB | `phpunit-mariadb` | Yes |
| Full-tree Pint (`vendor/bin/pint --test`) | `pint-full` | No — remaining legacy style debt |
| Full PHPUnit (`phpunit.xml`) | `phpunit-sqlite-full` | No — remaining classified failures |

A human with repo admin must enable these as GitHub **required status checks**. The YAML cannot do that by itself.

There is **no** `static-analysis` job. Do not add a green placeholder.

## PHP / databases

- PHP **8.3** only (`composer.json` requires `^8.3`).
- SQLite gate: isolated file for `migrate:fresh`, then PHPUnit `:memory:` via `phpunit.xml`.
- MariaDB gate: service container `mariadb:10.11`, database `hasem_p0_tenancy_test`.
- Production database name `hasem` is refused by `tests/bin/assert-not-production-database.php` and PHPUnit bootstrap.
- Test `APP_KEY` is the public Laravel testing key already in `phpunit.xml`. It is not a production secret.

## Suites

- **PR + protected branch SQLite:** blocking critical/security suite (`phpunit.critical.xml`) after `migrate:fresh` on a file SQLite DB. `phpunit-sqlite-full` is informational.
- **PR + protected branch MariaDB:** the same critical suite (`phpunit.critical.mysql.xml`) after `migrate:fresh` on a clean isolated DB.
- **Pint:** mandatory `pint` checks `tests/ci/pint-enforced-paths.txt` only. Full-tree Pint is `pint-full` and stays red on ~150 legacy files. That is not a silent skip.
- Billing / cashier sync tests were hygiene-fixed with `TestCase::inWorkspace()` (`WorkspaceContext::runFor`). Fail-closed tenancy was not weakened.
- `CashierPhase2AOfflineSnapshotTest::test_invalid_snapshots_are_rejected_without_catalog_fallback` still fails on `inconsistent_total`: P0-2 server-authoritative totals ignore a client `total_amount` of 99 instead of rejecting the op. Assertion not rewritten. Class stays out of the critical suite.
- Redis runtime tests skip without Redis and are **not** mandatory in CI.
- Restore-drill tests are **not** in the MariaDB CI job (they use a separate backup source name and dump binaries).

## Static analysis = REVIEW

PHPStan / Larastan is **not** in `require-dev` and has no project config. Introducing it at any useful level would not be a safe one-PR cleanup of this monolith. Do not pretend the CI gate runs static analysis.

## Flutter = REVIEW / BLOCKED

Apps live under `apps/hasim`, `apps/hasim_finance`, `apps/hasim_cashier`. There is no first-party Flutter workflow. Windows Flutter toolchains in this environment have not been a reliable CI target. Linux `flutter test` is not wired as a required check. Do not treat a missing Flutter job as a green Flutter gate.

## E2E = REVIEW

No first-party Laravel Dusk or Playwright suite.

## Load = REVIEW

No first-party k6 / locust / load suite. Do not treat PHPUnit as load testing.

## Secrets / artifacts

- CI does not load a developer `.env`.
- CI must not cache `.env`, database dumps, or backup artifacts.
- Failed PHP jobs upload JUnit XML and testdox under `storage/logs/ci/` only.
- Do not upload `.env`, secrets, or SQL dumps.

## Local equivalents (Windows or Linux)

```bash
php tests/bin/assert-not-production-database.php
php tests/bin/pint-enforced.php --test
# Full-tree Pint (not required): vendor/bin/pint --test
# SQLite migrate proof (file DB, not :memory:)
# set APP_ENV=testing, APP_KEY=testing key, DB_CONNECTION=sqlite, DB_DATABASE=database/ci-migrate.sqlite
php tests/bin/ci-migrate-fresh.php
php vendor/bin/phpunit --configuration phpunit.critical.xml
php vendor/bin/phpunit --configuration phpunit.xml
# MariaDB (isolated hasem_p0_tenancy_test only)
php tests/bin/mysql-p0-probe.php
# set DB_CONNECTION=mysql DB_DATABASE=hasem_p0_tenancy_test (never hasem)
php tests/bin/ci-migrate-fresh.php
php vendor/bin/phpunit --configuration phpunit.critical.mysql.xml
```
