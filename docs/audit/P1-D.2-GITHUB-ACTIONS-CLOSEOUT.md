# P1-D.2 FINAL

**Date:** 2026-09-13  
**P0-1 … P0-8 / P1-A / P1-B / P1-C:** CLOSED — not reopened.  
**P1-D.1:** CONDITIONAL (local gates only).  
**P1-E:** not started.

## Status

**BLOCKED**

GitHub Actions was **not** executed. This working tree is **not a git repository**. There is no remote, no commit SHA, and no Actions run URL.

`CLOSED` is forbidden without a real run. Local pre-gates passed; that is not GitHub CI.

## Git / remote evidence

```
git rev-parse --is-inside-work-tree
fatal: not a git repository (or any of the parent directories): .git
```

- `git remote -v` — same fatal (no `.git`)
- `git branch --show-current` — same fatal
- No remote was invented. No credentials were guessed. No `git init` was performed.

## Workflow validation (`.github/workflows/ci.yml`)

Present and correctly named:

| Job | Mandatory? | Notes |
| --- | --- | --- |
| `pint` | Yes | PHP 8.3, `php tests/bin/pint-enforced.php --test` |
| `phpunit-sqlite` | Yes | Isolated file SQLite, `assert-not-production-database.php`, critical suite |
| `phpunit-mariadb` | Yes | `mariadb:10.11`, `DB_DATABASE=hasem_p0_tenancy_test` (not `hasem`) |
| `pint-full` | No | Full-tree Pint — expected RED |
| `phpunit-sqlite-full` | No | Full PHPUnit — expected RED |

Also confirmed:

- Triggers: `pull_request`, `push` to `main`/`master`, `workflow_dispatch`
- PHP **8.3** on all jobs
- No Windows-only paths
- No developer `.env` loaded in CI
- No production secrets
- Artifacts on failure: `storage/logs/ci/` JUnit + testdox only
- Production DB name `hasem` is refused by `tests/bin/assert-not-production-database.php`

`pint-full` and `phpunit-sqlite-full` are **not** treated as green.

## Local pre-gate (this machine)

| Check | Result |
| --- | --- |
| `assert-not-production-database.php` (unset DB) | exit 0 |
| same with `DB_DATABASE=hasem` | exit 1 (refused) |
| `php tests/bin/pint-enforced.php --test` | **PASS** |
| `phpunit.critical.xml` (SQLite) | **PASS 259/259** |
| `php tests/bin/mysql-p0-probe.php` | READY, `hasem_p0_tenancy_test`, MariaDB 10.4.32 |
| `phpunit.critical.mysql.xml` | **PASS 259/259** |

Production `hasem` was not used.

## GitHub Actions

| Field | Value |
| --- | --- |
| RUN URL | **none** — no repository |
| COMMIT SHA | **none** |
| BRANCH | **none** |
| `pint` | **not run on GitHub** |
| `phpunit-sqlite` | **not run on GitHub** |
| `phpunit-mariadb` | **not run on GitHub** |

## Branch protection

**OPERATOR ACTION REQUIRED** — cannot enable checks without a GitHub repository and admin access.

## Remaining operator actions

Do these on the operator machine (do **not** invent a remote URL):

```bat
cd D:\projects\aihasan
git init
git add .
git status
```

Then create an empty GitHub repository (you choose the org/name). Add **only** the URL you created:

```bat
git remote add origin https://github.com/<ORG>/<REPO>.git
git branch -M main
git add .
git commit -m "Add HASEM CI quality gates (P1-D)."
git push -u origin main
```

After push:

1. Open the Actions tab and wait for workflow `ci` on that commit.
2. Confirm three jobs are green: `pint`, `phpunit-sqlite`, `phpunit-mariadb`.
3. Treat `pint-full` and `phpunit-sqlite-full` as **non-blocking RED**.
4. Settings → Branches → protect `main` → required status checks:
   - `pint`
   - `phpunit-sqlite`
   - `phpunit-mariadb`
5. Do **not** require `pint-full` or `phpunit-sqlite-full`.

Send back: repo URL, run URL, commit SHA, and a screenshot or copy of the three check names. Then P1-D.2 can be closed.

## Full-tree Pint / full PHPUnit

Not claimed green. They remain informational RED jobs.
