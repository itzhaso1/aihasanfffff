# ROADMAP

Audit date: 2026-09-13  
Order is risk-first. Do not start polish before isolation and money.

---

## PHASE 0 — Emergency Security

**Goal:** Stop plausible cross-tenant and unauthenticated cost/abuse holes.  
**Tasks:**

- Fail-closed `WorkspaceScopedModel` when a user/request exists without context
- Scope `EmailLog` / attachments / recipients / push tokens or lock queries
- Webhook fail-closed if signing secret empty in production
- Hide or rate-budget public assistant + menu AI
- Remove default platform admin password path
- Restore CI that runs `php artisan test`

**Dependencies:** none  
**Risk:** Breaking jobs that relied on fail-open scope — add explicit `forWorkspace`  
**Outcome:** No silent unscoped tenant queries on HTTP

---

## PHASE 1 — Data Integrity

**Goal:** One number for a sale.  
**Tasks:**

- Server-authoritative tax on POS accept/sync
- Unique `client_reference` + sync replay tests on MySQL
- Align cashier invoice / order / finance invoice links
- Stop float integrity commands
- Backup + restore drill for MySQL and `storage/app/private`

**Dependencies:** Phase 0 context rules  
**Risk:** Offline POS needs a migration window  
**Outcome:** No double sale; restore tested

---

## PHASE 2 — Auth / Authorization

**Goal:** Roles mean what the UI says.  
**Tasks:**

- Split `financePermissionMap` so `$elevated` is not all-powerful
- Password-reset revokes Sanctum tokens
- 2FA for owner + platform admin
- Membership enum vs accountant mapping decision (additive migration only if needed)
- Session cookie flags verified in staging

**Dependencies:** Phase 0  
**Risk:** Owners locked out if elevation removed carelessly  
**Outcome:** Least privilege for money mutations

---

## PHASE 3 — Finance Integrity

**Goal:** Dashboard = ledger; VAT path is one engine.  
**Tasks:**

- KPI cards from `LedgerReportService`
- Unify POS tax into `TaxCalculationService`
- MySQL concurrent payment/stock tests
- Document ZATCA as foundation only until official integration
- Keep AI read-only

**Dependencies:** Phase 1  
**Risk:** Dashboard test strings (`كم بعنا؟`) must stay honest  
**Outcome:** Close the books from one P&L

---

## PHASE 4 — Cross-App Integration

**Goal:** One customer, one product, honest channels.  
**Tasks:**

- Customer `client_reference` enforced across cashier/finance/chat
- Menu item always points at `product_id`
- Hide Instagram/Messenger until backends exist
- Define invoice vs POS order vs receipt in UX copy
- Chat outbox idempotency E2E

**Dependencies:** Phase 1  
**Risk:** Data backfill  
**Outcome:** No duplicate customers/products as the happy path

---

## PHASE 5 — Stability

**Goal:** Workers and schedulers are production facts.  
**Tasks:**

- Redis queue/cache/session
- Supervisor for `queue:work` + `schedule:run`
- Failed-job alerts
- WhatsApp/email health badges
- Remove leftover website/appointment tests

**Dependencies:** Phase 0–1  
**Risk:** Local DX if Redis forced in `.env.example`  
**Outcome:** Jobs do not silently die

---

## PHASE 6 — Performance

**Goal:** Survive a busy restaurant Friday.  
**Tasks:**

- Paginate remaining indexes
- Cache ledger reports
- Bound sync pull pages
- Explain MySQL slow queries
- Stop shipping unused Blade CSS globally if measured

**Dependencies:** Phase 5 metrics  
**Risk:** Cache vs closed period  
**Outcome:** p95 known

---

## PHASE 7 — UX/UI

**Goal:** One mental model.  
**Tasks:**

- Workspace home with module status
- Honest AI/copilot labels
- Complete legal placeholders
- Blade dark mode or explicitly light-only
- Confirmations on reverse/cancel

**Dependencies:** Phase 2–3 for permission-aware nav  
**Risk:** Dual finance UI — pick a source of chrome, do not invent nav  
**Outcome:** First-hour success

---

## PHASE 8 — Testing

**Goal:** Isolation and money cannot regress.  
**Tasks:**

- CI green on PHP + selected Flutter
- MySQL integration job
- Sync replay suite
- PHPStan baseline
- Do not claim coverage % without clover

**Dependencies:** Phase 0 CI  
**Risk:** Flaky SQLite vs MySQL  
**Outcome:** Merge blocked on isolation tests

---

## PHASE 9 — Deployment

**Goal:** Repeatable staging + prod.  
**Tasks:**

- Docker or equivalent image
- Git + tags (this tree currently has no git)
- TLS, secrets manager, log ship
- Rollback runbook
- Mobile/desktop signed builds + min version

**Dependencies:** Phase 5  
**Risk:** Windows cashier packaging  
**Outcome:** Someone else can deploy from docs + image

---

## PHASE 10 — Polish

**Goal:** Only after money is safe.  
**Tasks:**

- EN parity
- Design token cleanup
- CSV exports
- Bank CSV import
- Forecasting/OCR (explicitly later in spec)

**Dependencies:** Phases 0–9  
**Risk:** Scope creep  
**Outcome:** Product feel, not survival
