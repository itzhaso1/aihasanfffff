# P0-3 FINAL

**Date:** 2026-09-13  
**P0-1:** CLOSED — not reopened.  
**P0-2:** CLOSED — not reopened.  
**Scope:** backup / restore / recovery only.

## Status

**CLOSED**

Proven on this host: automated MySQL/MariaDB backup, private non-public storage, integrity verification, 14-day retention, failure cases, isolated MariaDB restore drill, restored schema/data counts, secret redaction, runbook, scheduler hook. Production database `hasem` was not a dump source and was not a restore target. It still exists after the drill. Temporary restore database `hasem_p0_restore_drill` was dropped after success.

This is **not** a claim that disaster recovery is complete. Cold/off-site copies and host volume encryption remain operator-owned.

---

## Findings Before Fix

Audit of `docs/deployment.md`, `docs/PRODUCTION.md`, `docs/operations/production-runbook.md`, `database/`, `scripts/`, `docker/`, `.github/`, scheduler, `config/database.php`, `config/filesystems.php`, `storage/app/private`, and first-party commands:

1. **No first-party backup command.** Docs wished for backups. No Artisan command, shell script, Docker job, CI workflow, or Spatie backup package created dumps.
2. **No restore drill.** Nothing created an isolated database, restored into it, and compared schema/data.
3. **No private backup disk.** `local` already pointed at `storage/app/private`; there was no dedicated `backups` disk or `BACKUP_*` config.
4. **Documented policy already existed** in `docs/operations/production-runbook.md`: every 6 hours, **14 days hot**. Cold 90-day off-site was operator-owned. That policy was respected (not replaced with a 7-day invention).
5. **Files outside the SQL dump:** `storage/app/private` (KYC / private attachments) is not in MySQL. Invoice snapshots are usually in DB. Flutter Cashier local AES backups are out of this P0.
6. **No first-party dump encryption library.** Inventing crypto would have been out of scope.
7. **Production database name** in `.env` is `hasem`. Isolated names already used on this host: `hasem_p0_tenancy_test`.

---

## Implementation

- Dedicated private disk `backups` → `storage/app/private/backups` (`visibility=private`, not under `public/` or `storage/app/public/`).
- `config/backup.php` + `BACKUP_*` in `.env.example`. Retention **14 days**. Forbidden restore names include `hasem` and system schemas.
- Connections `backup_source` / `backup_restore` for isolated drill DBs only.
- `DatabaseBackupService`: `mysqldump --single-transaction` via a temp `--defaults-extra-file` (password not on argv or stdout). gzip + sha256 sidecar. Empty/missing/corrupt artifacts fail. gzip magic `\x1f\x8b` + `gzdecode`.
- `DatabaseRestoreService`: refuses production/system names; restore only into an isolated name; table-count integrity.
- `RestoreDrillService`: create isolated source → migrate → seed markers → backup → restore into `hasem_p0_restore_drill` → compare counts → drop restore DB.
- `SecretRedactor` on command output (password, `APP_KEY`, DSN user:pass@host).
- Scheduler: `backup:database` every six hours, `withoutOverlapping(120)`.
- Optional `backup:files` zip of `storage/app/private` excluding `backups/`. Not scheduled. Not part of the CLOSED gate.

---

## Commands

```bash
php artisan backup:database
php artisan backup:verify database/hasem-db-YYYYMMDD-HHMMSS.sql.gz
php artisan backup:retain
php artisan backup:restore database/hasem-db-YYYYMMDD-HHMMSS.sql.gz --database=hasem_p0_restore_drill --force
php artisan backup:drill
php artisan backup:files
php artisan test --testdox tests/Feature/Feature/Backup
```

Dump client used on this host: `C:\xampp\mysql\bin\mysqldump.exe`  
Restore client: `C:\xampp\mysql\bin\mysql.exe`  
Credentials passed only through a temporary defaults-extra-file (mode 0600), then deleted.

---

## Backup Artifact

| Field | Value |
| --- | --- |
| Location | `storage/app/private/backups/database/hasem-db-20260912-233928.sql.gz` |
| Public HTTP path | none |
| Size | 24445 bytes |
| SHA-256 | `4328f65bb7e7c3be5d55d46e651c3518409b3b6a639ae7386aa387b546181c64` |
| Verify command | `php artisan backup:verify database/hasem-db-20260912-233928.sql.gz` → **PASS** |

States proven for this artifact: **BACKUP CREATED**, **BACKUP VERIFIED**.

---

## Restore Drill

| Field | Value |
| --- | --- |
| source DB | `hasem_p0_backup_source` (created + migrated for the drill; **not** `hasem`) |
| restore DB | `hasem_p0_restore_drill` |
| engine/version | MariaDB **10.4.32** (`10.4.32-MariaDB`) |
| host | `127.0.0.1:3306` |
| command | `php artisan backup:drill` |
| duration | 4435 ms |
| result | **PASS** |
| after drill | `hasem` still present; `hasem_p0_restore_drill` dropped; source left isolated |

Restored table counts (source == restore):

| Table | Count |
| --- | --- |
| migrations | 103 |
| workspaces | 3 |
| users | 4 |
| workspace_users | 3 |
| products | 3 |
| customers | 0 (table present) |
| orders | 0 (table present) |
| finance_invoices | 0 (table present) |
| finance_journal_entries | 0 (table present) |
| payments | 0 (table present) |
| pos_sync_operations | 0 (table present) |

State proven: **RESTORE DRILL PASSED**.

---

## Tests

Command: `php artisan test --testdox tests/Feature/Feature/Backup`

**PASS:** 13  
**FAIL:** 0  
**SKIPPED:** 0  
**ERRORS:** 0

Covered:

1. Command creates a backup artifact (via isolated drill)
2. Artifact non-zero size + sha256
3. Integrity / gzip / checksum verification
4. Invalid configuration fails (SQLite source, missing dump binary, invalid credentials)
5. Retention deletes only eligible old `hasem-db-*.sql.gz`; never `readme.txt`; never the last backup
6. Isolated restore drill against MariaDB succeeds
7. Restored DB has expected schema and matching counts
8. Backup / restore failures return exit code 1
9. No password / `APP_KEY` in command output
10. Scheduler registers `backup:database`
11. Restore refuses `hasem` and refuses restore without `--force`
12. Unwritable destination fails
13. Empty and corrupt gzip artifacts fail verify

---

## Files Changed

### New

- `config/backup.php`
- `app/Services/Backup/DatabaseBackupService.php`
- `app/Services/Backup/DatabaseRestoreService.php`
- `app/Services/Backup/RestoreDrillService.php`
- `app/Services/Backup/SecretRedactor.php`
- `app/Services/Backup/MysqlClientLocator.php`
- `app/Services/Backup/BackupArtifact.php`
- `app/Services/Backup/BackupException.php`
- `app/Console/Commands/BackupDatabaseCommand.php`
- `app/Console/Commands/BackupVerifyCommand.php`
- `app/Console/Commands/BackupRetainCommand.php`
- `app/Console/Commands/BackupRestoreCommand.php`
- `app/Console/Commands/BackupDrillCommand.php`
- `app/Console/Commands/BackupFilesCommand.php`
- `tests/Feature/Feature/Backup/DatabaseBackupCommandTest.php`
- `tests/Feature/Feature/Backup/DatabaseRestoreDrillTest.php`
- `docs/operations/backup-restore-runbook.md`
- `docs/audit/P0-3-BACKUP-RESTORE.md`
- `storage/app/private/backups/.gitignore`

### Updated

- `config/filesystems.php` — private `backups` disk
- `config/database.php` — `backup_source`, `backup_restore`
- `.env.example` — `BACKUP_*`
- `routes/console.php` — `backup:database` every six hours
- `docs/operations/production-runbook.md`
- `docs/deployment.md`
- `docs/PRODUCTION.md`

P0-1 and P0-2 code/docs were not reopened.

---

## Remaining Review

These are **not** CLOSED-gate failures. They are out of this P0 or operator-owned:

- Cold / 90-day off-site copy is not implemented in-repo.
- No application-level dump encryption (no first-party crypto). Use the private disk and/or host/volume encryption; encrypt copies that leave the host with the platform ops tool. Do not commit keys.
- `backup:files` is manual; only the database dump is scheduled.
- Host cron `* * * * * php artisan schedule:run` must be installed on the production host. **SCHEDULE CONFIGURED** in Laravel; host cron is operator-owned.
- Isolated leftover database `hasem_p0_backup_source` remains on `127.0.0.1` after the drill (safe to drop; not `hasem`).
- Marker seed for `customers` did not insert a row on this schema; restore still proved the table exists with matching zero counts.
- Flutter local backups remain out of scope.

Do not start P0-4 from this document.
