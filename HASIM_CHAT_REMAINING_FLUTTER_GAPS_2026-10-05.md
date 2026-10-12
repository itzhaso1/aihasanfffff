# Hasim Chat — Remaining Flutter Gaps (Triage)

**Date:** 2026-10-05  
**Type:** Audit + triage only. No application code was changed.  
**Scope:** Hasim Chat (`routes/mobile.php` + Chat-owned web vs `apps/hasim`)  
**Out of scope:** Hasim Business, Finance, POS, Cashier, database/migrations, new Flutter features  
**Prior report:** `HASIM_CHAT_LARAVEL_FLUTTER_PARITY_AUDIT_2026-10-05.md` (stale after later phases)  
**Checkpoint:** Independent Worker is not a workspace member. Flutter is UX only. One Inbox + Worker Authorization. Backend remains the security source.

Classification used in this file:

| Code | Meaning |
| --- | --- |
| A | ✅ Completed since the previous parity audit |
| B | 🟢 Flutter-worthy: real Chat Flutter UX + existing mobile contract |
| C | 🟡 Contract/API limitation: useful, but mobile API is missing or too weak |
| D | ⚪ Backend-only / Web-only by current design |
| E | 🔴 Not needed in Hasim Chat Flutter |

An unused mobile endpoint is **not** automatically a Flutter gap. This triage asks whether the feature belongs in Hasim Chat Flutter at all.

Hasim Chat Flutter is the operator/worker loop: auth, inbox, chat, email, stories, merchant catalog, AI employees, company workers, Independent Worker portal. It is **not** a storefront, cart, checkout, marketplace, or shopping app.

---

## 1. Executive Summary

The previous parity audit listed commerce, catalog delete, templates, global search, inbox writes, and story delete/viewers as the main holes. **Catalog, Search, Inbox Actions, and Stories are now wired.** Commerce Flutter was **rolled back on purpose** and must not be rebuilt as shopping UI.

What remains is small. The daily Chat loop is covered. Remaining work is push, a few contract-limited surfaces (channel connect, email files), and optional catalog-authoring extras. Several Laravel mobile routes stay unused because they are duplicates, web-only, or not part of this app.

| Metric | Count |
| --- | ---: |
| Closed since previous audit (A) | 8 |
| Remaining Flutter-worthy (B) | 3 |
| Remaining contract/API blockers (C) | 3 |
| Backend-only / Web-only (D) | 14 |
| Not needed in Hasim Chat Flutter (E) | 6 |
| Flutter REST paths missing from `routes/mobile.php` | **0** |

**P0 for basic daily use: none.** Inbox, chat, search, stories, catalog CRUD/digital, and inbox writes already exist.

---

## 2. Closed since previous parity audit

| # | Feature | Laravel Contract | Current Flutter | Classification | Priority | Reason |
| ---: | --- | --- | --- | --- | --- | --- |
| A1 | Catalog product delete | `DELETE /catalog/{id}` | `catalog_form_screen` + `ChatCatalogRepository.delete` | A | — | Wired with confirmation; no client-side auth invention |
| A2 | Digital reserve / release / consume | `POST .../reserve` `.../release` `.../consume` | `catalog_digital_screen` | A | — | Merchant digital inventory actions now call the live routes |
| A3 | Global search | `GET /search` | `/search` `GlobalSearchScreen` `SearchRepository` | A | — | Conversations + customers + emails; company header only |
| A4 | Classification write | `PATCH .../classification` | chat menu + sheet; company staff | A | — | Independent Worker does not get the write UI; Laravel `workspace.member` still enforces |
| A5 | Handoff request | `POST .../handoff` | `conversation_handoff_sheets` | A | — | Supervisor request flow |
| A6 | Handoff assign | `POST .../assign` | assign sheet + `user_id` | A | — | Matches mobile assign contract |
| A7 | Story delete | `DELETE /stories/{id}` | story viewer; author/`is_mine` only | A | — | Laravel author-only; UI gated on `is_mine` |
| A8 | Story viewers | `GET /stories/{id}/viewers` | `story_viewers_sheet` | A | — | Name / avatar / `viewed_at` only |

Related, not a new product feature:

- Catalog `update` no longer force-sends `worker_max_price: null` (previous mismatch).
- Commerce Flutter UI was implemented then **rolled back**. That is **E**, not A. Router, settings, and catalog have no cart/checkout callers.

---

## 3. Remaining Flutter-worthy gaps

Only items with a **live mobile contract** and a **clear Chat Flutter job**.

| # | Feature | Laravel Contract | Current Flutter | Classification | Priority | Reason |
| ---: | --- | --- | --- | --- | --- | --- |
| B1 | Push device registration (company staff) | `POST /devices` `DELETE /devices/{device}` (`workspace.member`) | `DeviceRepository` exists; `NoopPushService` never registers; in-app `/notifications` already works | B | P1 | Missed messages while backgrounded. Contract is enough for members. Needs FCM/APNs credentials + real `PushService`, not a new Laravel route. |
| B2 | Product templates | `GET/POST /catalog/product-templates*` clone/assign | no UI, no repository caller | B | P2 | Merchant catalog is already in Flutter. Templates would speed create/edit. Not required for selling or for basic catalog CRUD. |
| B3 | AI employee skills catalog | `GET /ai/skills` | unused; employees created from presets; skills keys come on the employee payload | B | P3 | Optional picker for custom employees. Presets + employee test already cover the main AI settings loop. |

Do **not** treat B2/B3 as P0. Catalog create and AI employee presets already work.

---

## 4. Contract/API blockers

Useful for Chat Flutter, but **do not add Flutter first**.

| # | Feature | Laravel Contract | Current Flutter | Classification | Priority | Reason |
| ---: | --- | --- | --- | --- | --- | --- |
| C1 | Native channel connect (WhatsApp / Telegram / TikTok / Meta) | Mobile: `GET /channels` only. Connect/OAuth/disconnect live on **web** (`routes/web.php`) | `channels_screen` lists status and opens `manageUrl`. Parses `can_connect_in_app` but never uses it | C | P1 | Flutter can show channels and jump to web. In-app connect needs a mobile connect contract (token/OAuth/deep link). `can_connect_in_app` is a flag without a mobile action. |
| C2 | Email attachment download | `GET /emails/attachments/{id}/download` streams a file. `EmailMessageResource` detailed attachments are only `id`, `file_type`, `file_size` — **no** `download_url` / original name | `EmailMessageModel` ignores `attachments`. Email body may contain raw URLs only | C | P1 | Email inbox is already in Flutter. Chat attachments work via signed `download_url`. Email needs the same kind of mobile payload before a real attachment UI. |
| C3 | Push for Independent Worker | `POST /devices` sits in `workspace.member`. Workers are not workspace members | Worker uses canonical inbox; Noop push | C | P2 | Company push can ship on B1. Worker push needs a worker-safe device route or an explicit “in-app notifications only” decision. Do not grant workspace membership to workers. |

---

## 5. Backend-only / Web-only

| # | Feature | Laravel Contract | Current Flutter | Classification | Priority | Reason |
| ---: | --- | --- | --- | --- | --- | --- |
| D1 | Channel webhooks | `routes/web.php` Meta/WhatsApp/Telegram/TikTok | none | D | P3 | Inbound servers, not a Flutter screen |
| D2 | Signed chat attachment download | `GET /attachments/{id}/download` | loads `download_url` on the message | D | P3 | Route is consumed; no extra Flutter client call |
| D3 | Dedicated worker inbox | `GET/POST /worker/workspaces/{id}/inbox*` | unused; canonical `/conversations*` + `inbox.actor` | D | P3 | One inbox by design |
| D4 | Subscription checkout / cancel | web `SubscriptionController` only | `plans_screen` is read-only `GET /plan` `/plans` | D | P3 | No mobile billing routes |
| D5 | Unread snapshot | `GET /unread` | unused; badges from `GET /home` | D | P3 | Duplicate of home |
| D6 | Catalog dedicated search | `GET /catalog/search` | list uses index `q` | D | P3 | Duplicate of catalog index |
| D7 | Workspace index / current | `GET /workspaces` `/workspaces/current` | picker uses `/auth/me`; only `switch` is called | D | P3 | Covered |
| D8 | Catalog GET one / dedicated activate / dedicated inventory | `GET /catalog/{id}` `POST activate/deactivate` `PATCH .../inventory` | editor uses list row + `is_active` / policy on `PATCH /catalog/{id}` | D | P3 | Dedicated routes unused; behavior exists |
| D9 | Story GET one | `GET /stories/{id}` | list + viewer | D | P3 | Unused method, not a product hole |
| D10 | Privacy lock / chat themes | none | local stores | D | P3 | Flutter-local by design |
| D11 | Worker commerce capability chips | no worker commerce mobile actions | labels only | D | P3 | Display only; no Chat Flutter storefront |
| D12 | Commerce tax + intelligence **editors** | web CRUD; mobile has GET profile/attributes/requirements/item + POST evaluate | none | D | P3 | Staff web authoring. Not the Chat operator app |
| D13 | Catalog POST aliases for PATCH multipart | POST `/catalog/{id}` and category POST alias | uses PATCH/upload helpers | D | P3 | PHP compatibility aliases |
| D14 | `GET /ai/employees/{id}` | show one employee | list/update from collection | D | P3 | Unused extra read |

---

## 6. Not needed

| # | Feature | Laravel Contract | Current Flutter | Classification | Priority | Reason |
| ---: | --- | --- | --- | --- | --- | --- |
| E1 | Commerce cart / checkout / orders | full `/commerce/carts*` `/commerce/orders*` | no live UI, no router, no DI, no catalog add-to-cart | E | P3 | Not Hasim Chat Flutter. Rollback was intentional. Do not rebuild shopping UI |
| E2 | Commerce intelligence in Flutter | `/commerce/intelligence*` | none | E | P3 | Recommendation engine for commerce, not inbox/catalog operator UX |
| E3 | Catalog sellers | `GET /catalog/sellers` (Commerce `Seller` id/name) | none in catalog settings | E | P3 | Seller directory is commerce-side. Chat catalog settings already work without it |
| E4 | Workspace branding GET | `GET /branding` (platform name/color/logo + workspace name) | unused repo; app uses Hasim tokens/assets | E | P3 | This app is Hasim-branded, not a white-label storefront |
| E5 | AI probe | `POST /ai/probe` | unused; `POST /ai/employees/{id}/test` is used | E | P3 | Redundant diagnostic |
| E6 | Appointments | no Chat mobile appointments API | `features/appointments` empty directory | E | P3 | No product surface |

---

## 7. Orphan Flutter code

No orphan **HTTP path** (Flutter calling a missing Laravel route) was found.

### Repository / DI methods with no `lib` UI caller

| Artifact | Path / method | Notes |
| --- | --- | --- |
| `BrandingRepository.fetch` | `GET /branding` | Provider registered; no screen |
| `HomeRepository.unread` | `GET /unread` | Home uses `.home()` |
| `WorkspaceRepository.list` / `current` | `GET /workspaces` `/workspaces/current` | Only `switchTo` is used |
| `AiRepository.probe` | `POST /ai/probe` | Employee test is used instead |
| `DeviceRepository.register` / `revoke` | `/devices*` | Injected into `NoopPushService` on purpose |
| `ChatCatalogRepository.show` | `GET /catalog/{id}` | Form receives the list row |
| `ChatCatalogRepository.updateInventory` | `PATCH .../inventory` | Policy saved on item update |
| `ChatCatalogRepository.setActive` | `POST activate/deactivate` | `is_active` on save |
| `StoryRepository.show` | `GET /stories/{id}` | Viewer uses list payload |
| `ChannelModel.canConnectInApp` | parsed from `GET /channels` | Never read in UI |

### Folders / leftover surfaces

| Artifact | Notes |
| --- | --- |
| `apps/hasim/lib/features/appointments` | Empty directory; no routes |
| Commerce Flutter (`features/commerce`, commerce tests) | Not imported by router, providers, catalog, or settings. Latest full suite did not execute commerce tests. Treat as rollback leftovers if those files still exist on disk; they must **not** be reconnected |

Worker capability presentation still maps `commerce.orders.*` / `payment_links.create` as **labels only**. That is D11, not a hidden storefront.

---

## 8. Recommended implementation order

Do not start Channels/Templates/Sellers/Intelligence as shopping work. If anything is scheduled next, keep it Chat-sized.

### P0 — none

Basic Chat use (login, inbox, chat, search, stories, catalog, inbox writes) is already shipped.

### Next three phases only

1. **Push for company staff (B1, P1)**  
   Replace `NoopPushService` with FCM/APNs and `POST /devices`. Keep Independent Worker out until C3 is decided. In-app notification center stays as-is.

2. **Email attachments (C2, P1) — Laravel contract first, then Flutter**  
   Add `download_url` + display name (same idea as chat `MessageAttachmentResource`). Then parse attachments in `EmailMessageModel` and open them from email detail. Do not invent a client download path.

3. **Channel connect (C1, P1) — Laravel mobile contract first, then Flutter**  
   Keep `manageUrl` until a real mobile connect/disconnect (or documented deep-link) exists. Do not fake native connect in the client.

**Defer:** product templates (B2 P2), AI skills picker (B3 P3), worker push (C3), branding, sellers, commerce, intelligence.

**Cleanup (optional, not a product phase):** delete or stop exporting unused repo methods listed in §7 so “supported” is not implied.

---

## Evidence (this pass)

| Check | Result |
| --- | --- |
| Laravel → Flutter | `routes/mobile.php` vs repository callers vs `app_router.dart` |
| Flutter → Laravel | Live `ApiClient` paths map to mobile routes; **0 missing backend routes** |
| Catalog / Search / Inbox / Stories | UI + repository callers present |
| Commerce | No router/DI/catalog caller; classified E |
| Worker | Canonical inbox; dedicated worker inbox unused (D) |
| Independent Worker | Still not a workspace member; classification/handoff writes stay company-only |

---

*End of triage. The only file created for this phase is this report. No application code was modified.*
