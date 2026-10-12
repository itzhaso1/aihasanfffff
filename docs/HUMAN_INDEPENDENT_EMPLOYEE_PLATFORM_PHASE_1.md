# Human Independent Employee Platform — Phase 1 (Domain & Architecture)

**Status:** Design only — no migrations, APIs, UI, or runtime changes in this phase.  
**Date:** 2026-09-27  
**Scope:** Independent human workers, platform-mediated company relationships, coexistence with existing AI Employees.

---

## 1. Executive Summary

HASEM is a workspace-scoped modular monolith (`workspace_id` + `WorkspaceScopedModel` + membership + Spatie teams). AI Employees already live **inside** a workspace (`ai_employees` and related tables) and execute through the **AI Runtime** (`HasimInboxRuntime` → `AiRuntime` → tools/model/guard).

The Human Independent Employee platform introduces a **platform-level worker identity** (one account, many possible company relationships) mediated by explicit **engagements** (company ↔ worker agreements). Workers are **not** employees of the platform and **must not** be modeled as `worker.workspace_id` ownership.

This design:

- Separates **worker profile**, **private identity**, **verification documents**, **engagement (agreement)**, **work sessions**, **activity events**, **earnings**, and **company workspace data**.
- Reuses patterns from **merchant verification** (status lifecycle, private document storage, platform admin review) without conflating workers with merchants.
- Keeps **AI Employee tables and runtime untouched**; future unification is conceptual (shared capability vocabulary), not a merged execution path.
- Extends inbox attribution using existing primitives (`assigned_user_id`, `messages.user_id`, `ai_employee_id` on conversations/logs) with new engagement-scoped authorization.

Initial countries in scope for product design: **Jordan**, **Saudi Arabia**. Country-specific document mandates are **not** asserted here.

---

## 2. Domain Concepts

| Concept | Meaning | Scope |
| --- | --- | --- |
| **Platform** | HASEM product operator; reviews workers; holds sensitive docs | Global |
| **Company / Workspace** | Tenant (`workspaces`); owns customers, inbox, catalog, orders | `workspace_id` |
| **Worker User** | Authenticated `users` row for the human; registration via web | Global (not tenant-owned) |
| **Worker Profile** | Long-lived professional + registration data owned by the worker | Platform / worker-owned |
| **Private Identity** | Sensitive PII + government/identity fields | Platform; restricted |
| **Verification** | Platform review of worker eligibility | Platform |
| **Discovery** | Company search over **published** professional profiles | Platform read, company actor |
| **Engagement** | Contractual working relationship between one workspace and one worker | `workspace_id` + worker |
| **Engagement Permissions** | Company-granted capabilities for that engagement | Per engagement |
| **Work Session** | Recorded work interval under an engagement | Per engagement |
| **Activity Event** | Immutable business/inbox/commerce signal | `workspace_id` + engagement + worker |
| **Earnings** | Compensation ledger for an engagement period | Per engagement |
| **Workspace Member** | Existing `workspace_users` staff (owner, agent, etc.) | Per workspace |
| **AI Employee** | Existing `ai_employees` config + runtime | Per workspace |
| **Finance Employee** | Existing `finance_employees` internal payroll record | Per workspace; **not** this platform |

**Naming note:** The existing `Contract` model is a **customer B2B contract** (`app/Models/Contract/Contract.php`). Do **not** reuse that table for worker engagements. Use **`worker_engagements`** (or equivalent) for company ↔ independent worker relationships.

---

## 3. Entity Relationship Overview

```text
users (Worker User)
  │
  ├──1:1── worker_profiles (professional + registration summary)
  │           ├── worker_profile_skills, languages, experience, availability prefs
  │           └── worker_discovery_settings (discoverable flag, compensation prefs)
  │
  ├──1:1── worker_identity_private (encrypted / restricted PII)
  │
  └──1:N── worker_verification_documents (private files, platform review)

worker_profiles
  └──1:N── worker_engagements ──N:1── workspaces (company)
              ├── worker_engagement_terms (role, schedule, compensation snapshot)
              ├── worker_engagement_permissions (capability grants)
              ├── worker_engagement_acceptances (offer/accept audit)
              ├── worker_work_sessions
              ├── worker_activity_events
              └── worker_earning_periods ── worker_earning_lines

workspaces
  ├── workspace_users (company staff — unchanged)
  ├── ai_employees (+ personas, skills, tool_permissions, model_settings)
  ├── conversations (assigned_user_id, ai_employee_id)
  └── messages (user_id, ai_generated)

Future (not Phase 2 in this doc): optional polymorphic "workforce participant" view —
  AI Employee | Human Engagement — for inbox routing UI only.
```

---

## 4. Worker Account Model

### Authentication

- **Reuse** `users` for credentials, email/phone verification (`MustVerifyEmail`), Sanctum tokens for mobile later.
- **Do not** add workers only as `workspace_users` rows; that models **company staff**, not independent contractors.
- Introduce a discriminating flag or side table, e.g. `worker_profiles.user_id` (unique), to mark platform worker accounts vs pure company members (a user may theoretically be both — **LEGAL / POLICY DECISION REQUIRED** for conflicts of interest and dual access UX).

### Registration domain (worker-supplied)

Stored primarily on **`worker_profiles`** (+ normalized child tables where needed):

| Field group | Examples | Storage tier |
| --- | --- | --- |
| Contact | full name, email, phone, profile photo | Profile (photo public in discovery if allowed) |
| Demographics | country (JO/SA initial), nationality, date of birth | Country/nationality: profile for filters; DOB: **private identity** |
| Professional | skills, sales experience, work history, languages, bio | Profile (discovery) |
| Preferences | availability windows, desired compensation range | Profile + discovery settings |
| Identity (sensitive) | legal name variants, ID numbers, addresses | **worker_identity_private** only |

### Lifecycle (worker account)

Distinct from engagement lifecycle:

| State | Meaning |
| --- | --- |
| `registered` | User created; minimal data |
| `profile_incomplete` | Missing required profile fields for submission |
| `verification_pending` | Submitted for platform review |
| `verified` | Platform approved; may be discoverable if other gates pass |
| `verification_rejected` | Review failed; may re-submit |
| `suspended` | Platform enforcement; all engagements should block work |
| `availability_*` | Separate dimension: `available`, `limited`, `unavailable` (worker-controlled where not suspended) |

---

## 5. Professional Profile vs Private Identity Data

### Professional profile (company-visible when discoverable / engaged)

- Display name, photo, country, languages, skills, sales specialization tags, experience summaries, professional description, availability summary, compensation **preference ranges** (not payroll history).
- Verification **badge** (e.g. "platform verified") — boolean/level, not document contents.

### Private identity

- Full legal identity fields, government ID numbers, full DOB, document images, biometric references if ever collected — **`worker_identity_private`**.
- Encryption at rest **recommended**; access via platform admin guard only.

### Verification documents

- **`worker_verification_documents`**: mirror **`merchant_documents`** pattern (`disk`, `path` on private storage, `document_type_code`, review status, `reviewed_by` → `platform_admins`).
- **Never** expose file paths or downloads to company APIs.
- **LEGAL / POLICY DECISION REQUIRED:** document type catalog per country (JO/SA); do not seed mandatory legal doc types without counsel.

### What must never go to companies (default deny)

- Raw identity document files and document numbers.
- Full date of birth (age band may suffice — **POLICY DECISION REQUIRED**).
- Home address (unless explicitly offered for shipping roles — default deny).
- Platform internal verification notes, rejection reasons (worker sees appropriate subset).
- Other companies' engagement history (only aggregate stats if worker opts in — **POLICY DECISION REQUIRED**).

---

## 6. Verification Model

Parallel conceptual model to **`merchant_profiles`** / **`MerchantVerificationService`**:

| Component | Purpose |
| --- | --- |
| `worker_verification_cases` (or status on profile) | Tracks review queue state |
| Statuses | Align with business: `not_submitted`, `documents_required`, `pending_review`, `approved`, `rejected`, `suspended` |
| Transitions | Audit-logged (`AuditLogService` pattern) |
| Reviewers | `platform_admin` guard only |
| Outcome | Sets `worker_profiles.verification_status`; gates discovery and new engagements |

Verification **does not** automatically create a workspace membership or engagement.

---

## 7. Company ↔ Worker Agreement Model

**Entity: `worker_engagements`** (preferred name; avoids collision with customer `contracts` and finance payroll).

| Concept | Location |
| --- | --- |
| Company | `workspace_id` |
| Worker | `worker_profile_id` (+ denormalized `user_id` for auth joins) |
| Role / title | Engagement-specific job title, department tag |
| Schedule | Expected hours, timezone, working days JSON |
| Compensation terms | Base rate, commission rules **snapshot** (formula IDs — definitions **POLICY DECISION REQUIRED**) |
| Permissions | Child table `worker_engagement_permissions` |
| Status | See lifecycle §8 |
| Termination | Reason codes, `terminated_at`, `terminated_by` (user + actor type) |

**Uniqueness:** At most one **`active`** engagement per (`workspace_id`, `worker_profile_id`) pair; historical rows retained.

**No** `worker_profiles.workspace_id` foreign key.

---

## 8. Agreement Lifecycle

Recommended states (adjust labels in implementation to match existing enums style):

| State | Description |
| --- | --- |
| `draft` | Company composing offer (not visible to worker) |
| `offered` | Sent to worker |
| `accepted` | Worker accepted; pending company activation or auto-activate — **BUSINESS DECISION REQUIRED** |
| `rejected` | Worker declined |
| `active` | Work authorized; permissions enforced |
| `paused` | Temporary hold (worker or company initiated — **POLICY DECISION REQUIRED**) |
| `completed` | Natural end (end date reached) |
| `terminated` | Early end |

### How an agreement becomes **active**

1. Worker `verification_status = approved` (or equivalent).
2. Engagement reaches `accepted` + company confirmation (if required).
3. Optional: company subscription/feature gate `workforce` — **BUSINESS DECISION REQUIRED**.
4. System sets `active_at`, snapshots permission template, opens work session eligibility.

### Pause / terminate

- **Paused:** block new work sessions; optional grace for in-flight conversations — **POLICY DECISION REQUIRED**.
- **Terminated:** revoke permissions immediately; retain read-only earnings/history for both parties per retention policy.

Record **`worker_engagement_acceptances`** (who, when, IP/user agent optional, version of terms).

---

## 9. Permission Model

### Requirements

- Permissions are **engagement-scoped**, not global user permissions.
- Must **not** require AI Runtime or `ai_employee_tool_permissions` rows for humans.
- Should **reuse conceptual vocabulary** where possible (inbox read/send, catalog read, order create) without coupling to `CanonicalTools` names.

### Recommended structure

1. **`workforce_capability_definitions`** (platform catalog, seeded): stable keys e.g. `inbox.messages.read`, `inbox.messages.send`, `customers.view`, `customers.phone.view`, `customers.email.view`, `catalog.products.view`, `commerce.orders.create`, `commerce.payment_links.create`, `reports.view`.
2. **`worker_engagement_permissions`**: `engagement_id`, `capability_key`, `allowed` (bool), optional constraints JSON (e.g. max order value — future).
3. **Authorization service** (`WorkerEngagementAuthorization` or similar): given `(user, workspace, capability)` → resolve active engagement → check grant.

### Relation to AI Employee permissions

| AI (`ai_employee_tool_permissions`) | Human (proposed) |
| --- | --- |
| Tied to `tool_name` + runtime budgets | Tied to **capabilities** + HTTP/service policies |
| Enforced in `HasimToolRunner` | Enforced in controllers/services/policies |
| Workspace-scoped via employee row | Workspace-scoped via engagement row |

**Future unification:** map AI tools → capability keys for **reporting and admin UI only**; do not merge enforcement paths.

### Workspace members (existing)

- **`workspace_users` + Spatie roles** remain for company staff.
- Human workers **should not** inherit manager permissions via Spatie unless explicitly invited as staff (**separate** from engagement — dual path is a risk; default: engagement capabilities only).

---

## 10. Work Session / Attendance Model

Separate concepts:

| Concept | Entity | Notes |
| --- | --- | --- |
| **Scheduled shift** | `worker_scheduled_shifts` (optional Phase) | Planned time; not proof of work |
| **Work session** | `worker_work_sessions` | Worker clocks in/out under `engagement_id` |
| **Session segments** | `worker_work_session_segments` | `active`, `break`, `idle` |
| **Activity signals** | `worker_work_activity_signals` | Heartbeats, focus events, message handling markers — **implementation-specific** |

Rules:

- Session **requires** `active` engagement.
- Opening a browser tab **does not** auto-start a session (explicit clock-in or approved auto-start policy — **POLICY DECISION REQUIRED**).
- Attendance totals derived from segments; raw signals kept for audit/disputes.

---

## 11. Activity Event Model

**Append-only** `worker_activity_events`:

| Field concept | Purpose |
| --- | --- |
| `workspace_id` | Tenant isolation |
| `worker_engagement_id` | Agreement attribution |
| `worker_profile_id` | Worker attribution |
| `actor_user_id` | Who performed action |
| `event_type` | e.g. `message.received`, `message.sent`, `conversation.assigned`, `order.created`, `payment_link.created`, `payment.succeeded` |
| `subject_type` / `subject_id` | Polymorphic reference (conversation, order, payment) |
| `occurred_at` | Business time |
| `payload` | JSON metadata (IDs, amounts in minor units, no PII leakage) |

**Do not** store derived KPIs here; compute in analytics layer.

### Attribution rules (inbox/commerce)

- **Messages:** existing `messages.user_id` + `workspace_id`; emit event on send/receive with engagement resolved from worker context.
- **Conversations:** extend usage of `assigned_user_id` for human workers; optional future `worker_engagement_id` on conversation for explicit binding when multiple engagements could exist — **only one active engagement per workspace**, so resolution is `(workspace_id, user_id) → engagement`.
- **Orders/payments:** today no worker attribution on orders — add nullable `attributed_worker_engagement_id` (or event-only attribution first) — **implementation choice in Phase 2**; events minimum viable.

---

## 12. Performance / Analytics Model

| Layer | Content |
| --- | --- |
| **Raw** | `worker_activity_events`, session segments |
| **Derived** | Materialized views or nightly jobs: counts, median response time, sales totals — **no invented scoring formulas in Phase 1** |
| **Scope** | Always filter by `workspace_id` + `engagement_id`; company A never sees company B metrics for same worker |

---

## 13. Earnings Model

Separate from **`finance_employees`** payroll (internal HR):

| Entity | Purpose |
| --- | --- |
| `worker_earning_periods` | e.g. weekly/monthly per engagement: `start`, `end`, `status` (`draft`, `pending_approval`, `approved`, `disputed`, `paid`) |
| `worker_earning_lines` | `type`: `base`, `commission`, `bonus`, `adjustment`, `deduction`; amount in **minor units** (`Money`); reference to activity/order optional |
| `worker_earning_approvals` | Company approver user, timestamp |
| `worker_payout_records` | Payout intent/history (provider-agnostic) |

Principles:

- Every line links to **`worker_engagement_id`** (and `workspace_id`).
- Adjustments require reason + audit.
- Commissions reference source events/orders by ID, not recomputed from float math.

---

## 14. Payment Boundary

- **No payment provider integration** in early phases.
- Platform records **what is owed and paid**; actual disbursement is **LEGAL / POLICY DECISION REQUIRED** (marketplace facilitator, agency, direct company-to-worker, etc.).
- Do **not** reuse customer **`payments`** checkout rows for worker payouts without a dedicated `payment_context` design (existing column on payments is for commerce/subscription contexts).
- Worker earnings **must not** post to company GL automatically without finance module integration — **BUSINESS DECISION REQUIRED**.

---

## 15. Multi-Tenant Security Model

| Risk | Mitigation |
| --- | --- |
| Cross-workspace data leak | All company data queries under `WorkspaceScopedModel` + `WorkspaceContext`; engagement must match workspace |
| Worker multi-company | Separate engagements; permissions evaluated per request workspace |
| IDOR on engagement ID | Policies verify `engagement.worker.user_id = auth id` OR company membership for company-side APIs |
| Private docs exposure | Separate storage; download routes on `platform_admin` guard only |
| Worker sees other company customers | Deny unless `active` engagement for that workspace |
| Spatie team bleed | Set `setPermissionsTeamId($workspaceId)` only after engagement/membership validated |
| AI runtime bypass | Human actions never invoke `AiRuntime` for authority |

**Enforcement points (required):**

1. Route middleware: authenticate + resolve workspace + resolve engagement context.
2. Policies on models (Conversation, Customer, Order, …).
3. Service layer: `WorkerEngagementAuthorization` before mutations.
4. Query scopes: never rely on UI hiding alone.
5. Platform admin separation (`platform_admin` guard vs `web`).

---

## 16. Human Employee vs AI Employee Architecture

```text
                    ┌─────────────────────────────────────┐
                    │     Future: Workforce (conceptual)   │
                    │  participant type: human | ai        │
                    └─────────────────────────────────────┘
                           │                    │
              Human path   │                    │  AI path (unchanged)
                           ▼                    ▼
              User + Engagement          ai_employees row
              + capabilities             + personas/skills/tools/model
                           │                    │
                           ▼                    ▼
              Hasim app / Web workspace   HasimInboxRuntime
              services & policies         AiRuntime → ModelGateway
                           │                    │
                           └────────┬───────────┘
                                    ▼
                           Inbox (conversations/messages)
                           assigned_user_id | ai_employee_id
```

- **Do not** extend `DatabaseEmployeeResolver` for humans.
- **Do not** rename `ai_employees`.
- Coexistence in inbox: AI can run when `ai_enabled` and bound `ai_employee_id`; human handles when handed off / assigned (`assigned_user_id`, `handoff_*` columns already exist).

---

## 17. Existing System Reuse Opportunities

| Existing artifact | Reuse |
| --- | --- |
| `users` + Sanctum + email verification | Worker authentication |
| `merchant_profiles` / `merchant_documents` / private disk | Verification document handling pattern |
| `platform_admins` + platform routes | Verification queue |
| `AuditLogService` | Status transitions |
| `WorkspaceContext` + `WorkspaceScopedModel` | Company data isolation |
| `Conversation.assigned_user_id`, `Message.user_id` | Human inbox attribution |
| `Conversation` / `ai_logs` `ai_employee_id` | AI attribution (unchanged) |
| `App\Support\Money\Money` | Earnings amounts |
| `COMMERCE_MARKETPLACE_FOUNDATION` seller separation lesson | Keep worker identity separate from workspace tenant row |
| Flutter Hasim AI employee settings | **Pattern only**; separate worker UX later |

---

## 18. Existing System Conflicts / Risks

| Conflict | Impact | Mitigation |
| --- | --- | --- |
| **`FinanceEmployee`** name | Confusion with human independent workers | Clear product naming; no reuse of `finance_employees` table |
| **`Contract` model** | Name collision with "contract" language | Use `worker_engagements` table name |
| **`workspace_users`** | Inviting worker as full member grants Spatie powers | Engagement-scoped capabilities; avoid auto-member |
| **AI tool permissions ≠ human capabilities** | Forced reuse would couple to runtime | Parallel capability catalog |
| **User in two companies** | Session/workspace header ambiguity | Require `X-Workspace-Id` + prove active engagement |
| **Same user staff + worker** | Privilege escalation | **POLICY DECISION REQUIRED** |
| **`docs/ai.md` legacy note** | Doc drift vs new AI runtime | Do not change AI runtime in this initiative |
| **No order attribution columns yet** | Commission proof weak | Activity events + later FK on orders |
| **GDPR / Saudi PDPL / Jordan privacy** | Retention and lawful basis | Mark legal validation |

---

## 19. Recommended Database Entities

*(Conceptual only — no migration SQL.)*

### Platform-global (not `WorkspaceScopedModel`)

**`worker_profiles`**

- **Purpose:** Long-lived worker professional identity.
- **Key concepts:** `user_id` (unique), `display_name`, `profile_photo_path`, `country_code` (JO/SA), `verification_status`, `lifecycle_status`, `availability_status`, `professional_summary`, `sales_experience_level`, `created_at`.
- **Relationships:** belongs to `users`; has many engagements, documents, skills.
- **Ownership:** Worker / platform; **no** `workspace_id`.

**`worker_identity_private`**

- **Purpose:** Sensitive PII separated from discovery DTOs.
- **Key concepts:** `worker_profile_id` (unique), encrypted fields for legal name, DOB, ID numbers, address; `last_updated_at`.
- **Ownership:** Platform; accessor role platform admin + limited worker self-service.

**`worker_verification_documents`**

- **Purpose:** Private uploads for verification.
- **Key concepts:** Same storage pattern as `merchant_documents`; `document_type_code`, `status`, `path`, `disk`, reviewer FK.
- **Ownership:** Platform.

**`worker_profile_skills` / `worker_profile_languages` / `worker_work_experiences`**

- **Purpose:** Normalized discovery filters.
- **Ownership:** Worker.

**`worker_discovery_settings`**

- **Purpose:** Opt-in discoverability, compensation preference ranges, public slug optional.
- **Key concepts:** `is_discoverable`, `compensation_min_minor`, `compensation_max_minor`, `currency`, `compensation_type` (hourly/fixed/commission-only — **POLICY DECISION REQUIRED**).

### Platform-global linking tenant

**`worker_engagements`**

- **Purpose:** Company ↔ worker agreement.
- **Key concepts:** `workspace_id`, `worker_profile_id`, `status`, `job_title`, `starts_at`, `ends_at`, `compensation_terms_json`, `permission_template_version`, `active_at`, `paused_at`, `terminated_at`, `termination_reason`.
- **Relationships:** belongs to workspace + worker_profile; has permissions, sessions, events, earnings.
- **Ownership:** Relationship record co-managed; row keyed by both parties.

**`worker_engagement_permissions`**

- **Purpose:** Capability grants for one engagement.
- **Key concepts:** `engagement_id`, `capability_key`, `allowed`.

**`worker_engagement_acceptances`**

- **Purpose:** Legal/audit trail of offer and acceptance.
- **Key concepts:** `engagement_id`, `party` (`company`/`worker`), `terms_version`, `accepted_at`, `user_id`.

### Workspace-scoped (activity under tenant)

**`worker_work_sessions`**, **`worker_work_session_segments`**, **`worker_activity_events`**, **`worker_earning_periods`**, **`worker_earning_lines`**, **`worker_payout_records`**

- **Purpose:** As defined in §10–§13.
- **Ownership:** `workspace_id` on each row for scope; always FK to engagement.

### Reference / catalog

**`workforce_capability_definitions`**

- **Purpose:** Stable permission keys and descriptions.
- **Ownership:** Platform seed data.

---

## 20. Recommended Relationships

| From | To | Cardinality | Notes |
| --- | --- | --- | --- |
| `users` | `worker_profiles` | 1:1 | Worker account |
| `worker_profiles` | `worker_engagements` | 1:N | Multi-company |
| `workspaces` | `worker_engagements` | 1:N | Many workers per company |
| `worker_engagements` | `worker_engagement_permissions` | 1:N | |
| `worker_engagements` | `worker_work_sessions` | 1:N | |
| `worker_engagements` | `worker_activity_events` | 1:N | |
| `worker_engagements` | `worker_earning_periods` | 1:N | |
| `workspaces` | `ai_employees` | 1:N | Unchanged |
| `conversations` | `users` | N:1 | `assigned_user_id` |
| `conversations` | `ai_employees` | N:1 | `ai_employee_id` (existing migration) |

---

## 21. Authorization Boundaries

| Actor | May access |
| --- | --- |
| **Worker** | Own profile, private identity (self), own documents (self), own engagements, earnings for own engagements, workspace data **only where capability granted** |
| **Company member** | Discover published profiles; manage engagements for own workspace; never worker private docs |
| **Platform admin** | Verification queue, document download, suspend worker |
| **AI Runtime** | No worker PII; no engagement permissions |

Request context resolution order:

1. Authenticate user.
2. Resolve target `workspace_id` (header/route).
3. If worker route: require active `worker_engagement` for (`user`, `workspace`).
4. Authorize capability.
5. Set `WorkspaceContext` + Spatie team id.
6. Execute scoped query.

---

## 22. Open Business Decisions

1. Can one worker have **multiple simultaneous active engagements** with **different** companies? (Recommended: **yes**; per-company isolation.)
2. Can worker and company negotiate commission purely on platform or offline annex?
3. Is company discovery global or invite-only marketplace?
4. Does worker pay platform fee/subscription? (**BUSINESS DECISION REQUIRED**)
5. Pause initiated by worker, company, or both?
6. Auto-activate engagement on worker accept vs company final click?
7. Dual role: same `user` as workspace member **and** independent worker?
8. Handoff: when AI active, can worker reply without disabling AI?
9. Minimum profile fields before verification submit?
10. Dispute workflow for earnings.

---

## 23. Legal / Policy Decisions Requiring Validation

- Independent contractor vs employee classification (JO/SA) — **LEGAL / POLICY DECISION REQUIRED**
- Required identity documents per country — **LEGAL / POLICY DECISION REQUIRED**
- Cross-border work (worker in JO for SA company) — **LEGAL / POLICY DECISION REQUIRED**
- Tax withholding, VAT, invoicing for platform fees and worker payouts — **LEGAL / POLICY DECISION REQUIRED**
- Data retention for verification docs and activity logs — **LEGAL / POLICY DECISION REQUIRED**
- Age minimum for workers — **LEGAL / POLICY DECISION REQUIRED**
- Background checks scope — **LEGAL / POLICY DECISION REQUIRED**
- PDPL / privacy notices for sharing professional profile with companies — **LEGAL / POLICY DECISION REQUIRED**

---

## 24. Recommended Implementation Phases

| Phase | Deliverable |
| --- | --- |
| **2A — Foundation** | Migrations + models for `worker_profiles`, private identity, verification docs; worker registration web flow; platform review queue |
| **2B — Engagements** | `worker_engagements` lifecycle, acceptance audit, company offer APIs |
| **2C — Authorization** | Capability catalog + middleware + policies; read-only inbox/customer slices |
| **2D — Work & activity** | Sessions, activity events, basic dashboards (raw metrics) |
| **2E — Earnings** | Periods/lines/approval; no provider |
| **2F — Discovery** | Verified worker search for companies |
| **2G — Coexistence UX** | Inbox UI: human vs AI assignment clarity (Flutter/web) |
| **Future — Unification layer** | Optional `workforce_participants` read model; still dual runtime |

**Build first after Phase 1:** **2A + 2B** (identity, verification, engagement lifecycle without commerce write).

---

## Critical Architectural Questions (Answers)

1. **Can one human worker work with multiple companies?**  
   **Yes.** One `worker_profile` / user; many `worker_engagements` over time; at most one active engagement per company.

2. **How is the worker/company relationship represented?**  
   **`worker_engagements`** linking `workspace_id` + `worker_profile_id` — not `company_id` on the worker.

3. **Which data belongs to the worker permanently?**  
   Profile, private identity, verification documents, skills, preferences, platform account.

4. **Which data belongs to a specific agreement?**  
   Role, schedule, compensation terms, permissions, acceptance records, sessions, events, earnings for that engagement.

5. **How are company-specific permissions represented?**  
   **`worker_engagement_permissions`** keyed by stable **`workforce_capability_definitions`**.

6. **How are private identity documents protected?**  
   Separate table + private disk + platform-admin-only download; excluded from discovery DTOs and company APIs.

7. **How does a worker become eligible for discovery?**  
   `verification_status = approved` + profile completeness + `worker_discovery_settings.is_discoverable` + not suspended + availability not `unavailable` (exact rules **POLICY DECISION REQUIRED**).

8. **How does an agreement become active?**  
   Offer → accept → (optional company confirm) → set `active` + `active_at` with verified worker and permission snapshot.

9. **How can an agreement be paused or terminated?**  
   Status transition to `paused` or `terminated` with timestamps/reason; authorization checks block work; audit log.

10. **How are work sessions associated with an agreement?**  
    **`worker_work_sessions.worker_engagement_id`** required.

11. **How are sales/activity attributed?**  
    **`worker_activity_events`** with workspace + engagement + user; complement with `messages.user_id` / optional future order FKs.

12. **How are earnings associated with an agreement?**  
    **`worker_earning_periods` / lines** always FK to `worker_engagement_id`.

13. **How can the same worker later work with another company?**  
    New `worker_engagement` row; prior rows historical; profile reused.

14. **How can Human and AI Employees coexist?**  
    Parallel paths: engagement+capabilities vs `ai_employees`+runtime; shared inbox via assignment columns.

15. **Which AI Employee structures can safely be reused?**  
    Patterns only: workspace scoping, skill keys as tags, conversation binding ideas — **not** tool permission rows or resolver.

16. **Which structures must remain AI-specific?**  
    `ai_employee_model_settings`, `ai_employee_tool_permissions`, `DatabaseEmployeeResolver`, `HasimInboxRuntime`, guard/tool pipeline.

17. **Where are authorization boundaries enforced?**  
    Middleware, engagement authorization service, Laravel policies, query scopes — not UI alone.

18. **What must never be exposed to companies from private identity?**  
    Documents, ID numbers, full DOB, raw address, internal review notes — default deny.

19. **What decisions require legal/business validation before implementation?**  
    See §22–§23 (classification, documents, tax, payouts, retention, cross-border).

20. **What should be built first after this design phase?**  
    Worker profile + verification + engagement lifecycle (**Phase 2A–2B**).

---

## Validation Checklist (Phase 1)

| Check | Result |
| --- | --- |
| One worker, multiple company agreements | Supported via `worker_engagements` |
| Company data cannot leak between agreements | Workspace scope + engagement FK on activity |
| Permissions agreement-scoped | `worker_engagement_permissions` |
| Private identity separated | `worker_identity_private` + document tables |
| Earnings traceable to agreement | Earning periods FK engagement |
| Activity attributed worker + company + agreement | Event model fields |
| Human/AI coexist without merged runtime | Separate execution paths documented |
| No AI Runtime changes required | Yes |

---

## Document Location

**Path:** `docs/HUMAN_INDEPENDENT_EMPLOYEE_PLATFORM_PHASE_1.md`  
(Consistent with existing architecture foundations such as `docs/COMMERCE_MARKETPLACE_FOUNDATION.md` and `docs/INBOX_AI_TRUST_BOUNDARY.md`.)
