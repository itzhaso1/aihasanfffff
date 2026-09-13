# DATABASE-AUDIT

Audit date: 2026-09-13  
Source: `database/migrations/*.php` (110 files), models, docs. No live DB was queried.

---

## 1. Inventory

| Item | Count / note |
| --- | --- |
| Migrations | 110 PHP files |
| Eloquent models (approx) | ~120 including Finance/EInvoicing/CRM/Contract |
| Workspace-scoped models | Vast majority of tenant tables |
| Repositories | 0 |
| Duplicate timestamps | `2026_09_01_120000_create_pos_customer_sessions_table.php` and `2026_09_01_120000_hasim_mobile_api_foundation.php` (`docs/PRODUCTION.md`) |
| Retired domains | Website builder + appointment tables: `2026_09_12_180000_retire_website_builder_and_appointment_tables.php` |

Exact live table list: UNKNOWN — Requires `php artisan db:show` or MySQL `SHOW TABLES`.

---

## 2. Core table families (from models + migrations)

| Family | Tables (representative) | Tenant key |
| --- | --- | --- |
| Identity | `users`, `auth_identities`, `platform_admins` | user is global |
| Tenancy | `workspaces`, `workspace_users`, `workspace_feature_flags`, `workspace_addons`, `workspace_usage_meters` | workspace |
| Plans | `plans`, `plan_addons`, `subscriptions`, checkout/billing attempts | mix |
| CRM | `customers`, tags, notes, groups, `crm_leads` | workspace |
| Inbox | `conversations`, `messages`, `message_attachments` | workspace |
| WhatsApp | accounts, phone numbers, templates, outbound | workspace |
| Email | accounts, messages, campaigns, contacts, **logs/attachments unscoped** | mixed |
| Catalog | `categories`, `products`, `product_variants`, `inventory_movements` | workspace |
| POS | `orders`, `order_items`, dining tables, sessions, menu items, cashier invoices, returns, devices, sync | workspace |
| Finance | invoices/items/payments/attachments, quotes, receipts, credit notes, expenses, accounts, journals, treasury, payroll, PO, projects, tax rates, settings | workspace |
| E-invoice | egs_units, documents, certificates, stamps, security records, issued snapshots | workspace |
| Merchant | profiles, documents, document types (types global) | mixed |
| AI | `ai_settings`, `ai_logs` | workspace |
| Audit | `audit_logs` (workspace nullable for system) | mixed |
| API | `api_keys`, `api_idempotency_keys` | mixed |

---

## 3. Integrity observations (schema-level)

### Good

- Billing occurrence unique `(billing_schedule_id, billing_occurrence_key)` — `2026_09_02_210000_*`
- Treasury transfer unique `(workspace_id, reference)` — spec
- Money columns typically `decimal(14,2)`; invoice qty `decimal(12,3)`
- Soft deletes on several finance/CRM entities
- Additive migrations only (project rule)

### Problems / risks

| Issue | Evidence | Risk |
| --- | --- | --- |
| Dual invoice status columns | `invoice_status` + `payment_status` + legacy `status` (`InvoiceStateService`, docs) | Stale/conflicting status if any writer skips sync |
| Float in ops commands | `routes/console.php` `finance:invoices:integrity-report` casts SQL `sum` to `(float)` | Misleading integrity, not a writer — still a smell |
| Integer stock vs decimal qty | Implementation status honesty | Rounding at inventory boundary |
| Dangerous cascades | UNKNOWN without reading every FK `onDelete` | Could wipe tenant finance on workspace delete |
| Missing indexes | Partial indexes added for journal lines/products | Report N+1 / full scans under load |
| Orphan records | Cannot prove without live DB | UNKNOWN |
| Duplicate customers | `client_reference` added `2026_09_02_000100_*`; POS can create customers independently | Duplicate CRM rows |
| Enum narrower than product | `workspace_users.membership_role` | Role mapping |

---

## 4. Findings

### AUD-DB-001
- Category: Database
- Severity: Medium
- Title: Duplicate migration timestamps
- Location: `database/migrations/2026_09_01_120000_*` (two files)
- Evidence: Documented; Laravel records filename so both can run; rename is a history rewrite
- Impact: Operator confusion; broken deploys if someone “fixes” names
- Recommendation: Do not rename on existing envs; document in runbooks
- Effort: S
- Priority: P2

### AUD-DB-002
- Category: Database
- Severity: High
- Title: No proven backup/restore for the Laravel database in this repo
- Location: `docs/deployment.md` mentions file backups; architecture doc wishes daily backups; no backup job/script in first-party tree
- Impact: Total data loss
- Recommendation: Automated MySQL dump + restore drill
- Effort: M
- Priority: P0

### AUD-DB-003
- Category: Database
- Severity: Medium
- Title: Legacy `finance_invoices.status` retained
- Location: finance invoice domain docs + `finance:invoices:status-audit`
- Impact: Writers using legacy column can desync payment/invoice state
- Recommendation: Keep until compatibility window; block new writes to legacy-only
- Effort: M
- Priority: P2

UNKNOWN — Requires runtime verification: FK cascade map, unused tables after website-builder retirement, actual index usage, orphan payments.
