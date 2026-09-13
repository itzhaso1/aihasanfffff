# Backup and restore runbook

First-party HASEM backups. An isolated MariaDB restore drill passed on this development host on 2026-09-13 (`php artisan backup:drill` → `hasem_p0_restore_drill`, production `hasem` untouched). That is **not** a claim of full disaster recovery: cold/off-site copies and host cron are still operator-owned. Re-run the drill on each new host.

## Scope

| Item | In DB dump | Separate |
| --- | --- | --- |
| Laravel MySQL/MariaDB | Yes | — |
| `storage/app/private` (KYC, mobile/private attachments) | No | `php artisan backup:files` |
| Issued e-invoice snapshots | Usually in DB | — |
| `storage/app/public` logos / public attachments | No | host snapshot |
| Flutter Cashier local SQLite | No | out of this P0 |

## How to create a backup

Requires cron/supervisor already running `* * * * * php /path/to/artisan schedule:run`.

Manual:

```bash
php artisan backup:database
php artisan backup:files
```

Scheduler hook (already registered): `backup:database` every six hours, `withoutOverlapping`.

## Where backups are stored

Private disk `backups`:

- Root: `storage/app/private/backups`
- Database files: `database/hasem-db-YYYYMMDD-HHMMSS.sql.gz`
- Checksums: same name + `.sha256`
- File archives: `files/hasem-files-YYYYMMDD-HHMMSS.zip`

Never put backups under `public/` or `storage/app/public/`.

## Retention

Documented hot policy: **14 days**. The last remaining successful database backup is never deleted.

```bash
php artisan backup:retain
php artisan backup:retain --days=14
```

Cold / off-site copies (90 days) are an operator process. This repo does not upload to object storage.

## Encryption

Dumps contain financial data and PII. There is no first-party crypto library in this tree.

- Store only on the private disk (or an encrypted volume / OS disk encryption).
- Do not copy plaintext dumps to shared/public locations.
- If copies leave the host, encrypt them with the platform’s existing ops tool (for example host-managed GPG). Do **not** commit keys.

## How to verify a backup

```bash
php artisan backup:verify database/hasem-db-YYYYMMDD-HHMMSS.sql.gz
```

Checks: file exists, size > 0, gzip readable, checksum match when the sidecar exists.

## How to restore (isolated)

Never restore onto production (`hasem`).

```bash
php artisan backup:restore database/hasem-db-YYYYMMDD-HHMMSS.sql.gz --database=hasem_p0_restore_drill --force
```

## Emergency restore

1. Create a **new** database name (not `hasem`).
2. Restore the chosen artifact into that name (`backup:restore --force`).
3. Restore the matching `backup:files` zip onto a staging `storage/app/private` (except `backups/`).
4. Point a staging app `DB_DATABASE` at the new name.
5. Validate login, workspace membership, invoices, ledger counts, POS sync tables.
6. Only then change production DNS/config. Do not overwrite `hasem` in place from these commands.

## Required config

From environment (no real secrets in this file):

- `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` (or `BACKUP_DB_*`)
- `BACKUP_DISK=backups`
- `BACKUP_PATH=database`
- `BACKUP_RETAIN_DAYS=14`
- `BACKUP_DUMP_BINARY` / `BACKUP_MYSQL_BINARY` if clients are not on `PATH`

Host cron:

```
* * * * * php /var/www/hasem/artisan schedule:run
```

## What must never be restored over

- `hasem` (production name)
- `mysql`, `sys`, `information_schema`, `performance_schema`

## Validate after restore

```bash
php artisan backup:drill
```

Or, on the restored connection, confirm non-empty `migrations` and presence of `workspaces`, `users`, `workspace_users`, `products`, `customers`, `orders`, `finance_invoices`, `finance_journal_entries`, `payments`, `pos_sync_operations`.

## Scheduler is not proof

The schedule hook only *starts* backups. Proof is: artifact created, verified, and an isolated restore drill that passed.
