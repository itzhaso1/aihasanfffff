# Change Impact Map — HASEM Discovery Audit

High-impact shared components and what breaks if they change. Based on **this checkout** + migrations/docs.

---

## 1. `WorkspaceScopedModel` + `WorkspaceContext`

| Depends on it | ~84 models in tree; all tenant queries |
| Consumers | Web, mobile API, jobs using `runFor` |
| Tests | `CrossWorkspaceIdorTest`, many feature tests |
| Break risk | Removing scope → **critical IDOR** |
| Safe boundary | Add new tenant models extending base; never disable scope in controllers |

**Evidence:** `app/Models/WorkspaceScopedModel.php`

---

## 2. `ResolveWorkspaceContext` middleware

| Depends on it | All `/api/mobile/v1` workspace routes, legacy API subsets |
| Break risk | Forgetting middleware on new API group → 403 or wrong tenant |
| Tests | Mobile workspace header tests |

---

## 3. `FeatureAccessService` / plans / `workspace_feature_flags`

| Depends on it | AI, email, commerce, channel features |
| Break risk | Plan key rename → silent feature denial |
| Docs | `docs/plans.md`, billing docs |

---

## 4. Chat catalog stack

**Files/services:** `ChatCatalogItem*`, `ChatCatalogRetrievalService`, web/mobile catalog controllers, template seeders

| Consumer | Impact if catalog schema changes |
| --- | --- |
| Inbox AI tools | Prompt grounding breaks |
| Commerce carts/orders | FK + snapshot columns |
| Commerce intelligence | Attribute/rule linkage |
| Worker engagement products | `worker_engagement_products.chat_catalog_item_id` |
| Flutter Hasim | Catalog screens, cart |

**Tests:** `ArabicCatalogSearchTest`, `ChatCatalogProductTemplateTest`, domain separation tests

**Safe boundary:** Additive columns on `chat_catalog_items`; avoid renaming without migration + API version bump.

---

## 5. Commerce order engine

**Core:** `CommerceOrderService`, `CommerceCartValidationService`, `CommerceInventoryReservationService`, `SellerAllocationService`

| Consumer | Impact |
| --- | --- |
| Mobile commerce API | Order create/validate |
| PaymentService (future) | Must handle `source=commerce` |
| Audit logs | `AuditLogService` calls |
| `orders` / `order_items` tables | Shared with other order sources |

**Do not** merge with finance `OrderService` without explicit migration — docblock forbids conflation.

---

## 6. `PaymentService` + gateways + webhooks

| Consumer | Impact |
| --- | --- |
| Merchant checkout | Payment links |
| Subscription billing | Separate webhook job |
| Commerce | **Currently blocked** — enabling touches eligibility + webhooks + order status |

**Tests:** Payment webhook tests (exist in suite)

---

## 7. Inbox + channel pipeline

**Components:** Conversation/Message models, webhook controllers, `ProcessIncoming*Message` jobs, `ChannelOutboundService`, `ProcessAIResponse`

| Consumer | Impact |
| --- | --- |
| Web inbox UI | Realtime + blades |
| Worker inbox | Same view, restricted actions |
| Mobile conversations API | Flutter primary UX |
| AI employees / handoff | Assignment + policies |

**Tests:** Large Worker/Inbox test folders

---

## 8. `PlatformAiConfiguration`

| Consumer | Impact |
| --- | --- |
| All AI replies | Model/provider selection |
| AI employee presets | `EmployeeModelPolicy` defaults |
| Platform admin UI | `/platform/ai` |

**Operational note:** Allow-list drift breaks preset loading and AI runtime.

---

## 9. Email subsystem

**Components:** `EmailAccount`, sync/send jobs, SMTP transport factory, mobile email API

| Distinct from | Laravel mailer for system emails |
| Impact if changed | Workspace mailbox feature breaks; campaigns/contacts cascade |

---

## 10. Finance stack (documented — PHP missing here)

If restored from full repo, expected blast radius:

- Tables: all `finance_*`, `products`, GL, payroll
- Apps: `hasim_finance`
- Routes: `/api/finance/v1`, finance web sidebar
- POS integration reads POS invoices — **read-only integration**, not catalog merge

**Change impact:** Invoice status enum, tax calculation, posted journal immutability — **high** (see master spec non-negotiables).

---

## 11. POS / cashier (documented — PHP missing here)

- Local SQLite in cashier app (Drift) — docs
- Sync operations table — server-side idempotency
- **Impact:** Menu changes, tax display rules, sync protocol version

---

## 12. Migrations (global)

183 migration files — **additive-only policy** per project rules.

**Risk:** Deploying migrations without matching PHP → schema/code drift.

---

## 13. Flutter `apps/hasim`

| Backend contract | `/api/mobile/v1` |
| Break if | Mobile breaking JSON shape, auth header requirements, feature gates |
| Independent | UI can ship faster than API — verify with contract tests |

---

## 14. Prior audit remediation matrix

Cross-reference: `docs/audit/AUDIT_REMEDIATION_MATRIX.md` for P0/P1 item ownership — not duplicated here.

---

## Safe modification boundaries (summary)

1. **Chat catalog + commerce** — isolated from finance products if separation preserved.
2. **Inbox/messaging** — keep webhook + job idempotency when adding channels.
3. **Tenancy** — never bypass `WorkspaceScopedModel` for convenience.
4. **Finance/POS** — do not change without full PHP tree + finance/cashier test suites green.

**No files were modified for this map.**
