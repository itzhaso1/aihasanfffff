# Hasim Chat — Master Flutter Parity Audit

**Date:** 2026-10-05  
**Type:** Audit only. No application code was changed.  
**Scope:** Hasim Chat user-facing work (`routes/mobile.php`, Chat-owned `routes/web.php`, `apps/hasim`)  
**Out of scope:** Hasim Business, Finance, POS, Cashier, E-Invoicing/ZATCA  
**Checkpoint:** Independent Worker is not a `workspace_users` member. One Inbox. Laravel is authorization and data. Flutter is UX only.  
**Historical (not current status):** `HASIM_CHAT_LARAVEL_FLUTTER_PARITY_AUDIT_2026-10-05.md`, `HASIM_CHAT_REMAINING_FLUTTER_GAPS_2026-10-05.md`

New rule used here: a user-facing Chat task that a person does daily or as simple admin belongs in Flutter. Webhooks, queues, callbacks, and internal jobs do not. A Web screen is not a reason to stay on Web. `manageUrl` is not a Flutter implementation.

## Classification

| Code | Meaning |
| --- | --- |
| A | ✅ Fully in Flutter now |
| B | 🟢 Required in Flutter; mobile contract already exists |
| C | 🟡 Required in Flutter; mobile contract missing or too weak |
| D | ⚪ Backend / infrastructure |
| E | 🔵 Web-only today and must become mobile |
| F | 🔴 Not a Hasim Chat Flutter feature |

Counts below are **capability rows in this matrix**, not raw HTTP routes. A route with no user job is not a gap. A screen with no live API call is not ✅.

---

## 1. Executive summary

Re-checked against current code, not the older reports.

Closed and still ✅: catalog product delete, digital reserve/release/consume, global search (conversations, customers, emails), classification write, handoff request/assign, story delete, story viewers, takeover/resolve/resume AI.

Corrected from the older “settings ✅” claim: `ChatCatalogSettingsScreen` is a hub (product, digital product, categories, digital stock, list). It does **not** call `chatCatalogSettingsControllerProvider`. Publish / draft / pause / archive / communication / general catalog settings exist in the repository and controller and have **no screen**.

Commerce is an **operator** workflow, not a public storefront. `CommerceCartController` creates a cart for a workspace member, optional `customer_id`, channel default `chat`, then preview/convert to an order. Web payment-link create explicitly excludes `source = commerce`. Do **not** put “add to cart” on the catalog manager. A separate operator order desk is in scope (B). The rolled-back `features/commerce` files are still on disk and are **not** routed.

| Metric | Count | Fraction |
| --- | ---: | --- |
| User-facing Chat capabilities (A+B+C+E) | **51** | |
| ✅ In Flutter (A) | **33** | **33/51 = 64.7%** |
| Missing from Flutter (B+C+E) | **18** | |
| Mobile contract ready, UI missing (B) | **8** | |
| Needs a mobile contract first (C+E) | **10** | |
| Web-only that must move (E) | **8** | |
| Backend-only (D) | **10** | |
| Not Chat Flutter (F) | **6** | |
| Flutter REST path with no Laravel route | **0** | |

P0 daily inbox/chat/search/stories/catalog CRUD is already in Flutter. The remaining work is connect, push, files, catalog publishing, billing, and operator commerce — not a rebuild of the inbox.

---

## 2. Matrix

### A — in Flutter

| # | Domain | Feature | Laravel | Mobile API | Web API/UI | Current Flutter | Target Flutter | Classification | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | Auth | Email login | `AuthController` | POST `/auth/login` | web login | `login_screen` | keep | A | — |
| 2 | Auth | Google sign-in | social | POST `/auth/social` | web social | `login_screen` | keep | A | — |
| 3 | Auth | Password recovery | forgot + reset | POST forgot/reset | web | matching screens | keep | A | — |
| 4 | Auth | Logout | logout | POST `/auth/logout` | web | settings | keep | A | — |
| 5 | Auth | Session bootstrap | me | GET `/auth/me` | — | splash / auth | keep | A | — |
| 6 | Account | Profile, password, avatar | auth profile | PATCH/PUT/POST | web profile | `profile_screen` | keep | A | — |
| 7 | Account | Device sessions | sessions | GET/DELETE `/sessions` | — | `security_sessions_screen` | keep | A | — |
| 8 | Workspace | Switch workspace | switch | POST `/workspaces/switch` | web switch | picker from `/auth/me` | keep | A | — |
| 9 | Inbox | List, filters, cursor | conversations | GET `/conversations` | web inbox | `conversations_screen` | keep | A | — |
| 10 | Messages | Thread + send | messages | GET/POST messages | web | `chat_screen` + outbox | keep | A | — |
| 11 | Media | Upload + open chat files | attachments | POST upload; signed URL on resource | web download | composer + `download_url` | keep | A | — |
| 12 | Inbox | Read, archive, mute | conversation | POST read/archive/mute | web | inbox + chat | keep | A | — |
| 13 | Inbox | Classification read + write | classification | GET filters + PATCH | web | chips + sheet; company only | keep | A | — |
| 14 | Handoff | Request, assign, takeover, resolve, resume AI | handoff | five POST routes | web handoff | chat menu + sheets | keep | A | — |
| 15 | Search | Conversations, customers, emails | `SearchController` | GET `/search` | — | `/search` | keep these three | A | — |
| 16 | Email | Folders, open, send, read, star | email | `/emails*` | web mail | email screens | keep | A | — |
| 17 | Email | Campaigns | campaigns | POST/GET/cancel | — | compose + status | keep | A | — |
| 18 | Contacts | CRUD, favorite, recent, groups | contacts | `/contacts*` `/contact-groups*` | web contacts | contacts screens | keep | A | — |
| 19 | Stories | List, create, view, track, delete, viewers | stories | full story routes | — | updates + viewer + sheet | keep | A | — |
| 20 | Channels | List connection status | channels | GET `/channels` | web channel index | `channels_screen` | keep list; connect is E | A | — |
| 21 | Catalog | Product CRUD, image, variants, active via save | catalog | GET/POST/PATCH/DELETE `/catalog` | web catalog | list + form | keep | A | — |
| 22 | Catalog | Categories CRUD | categories | `/catalog/categories*` | web | category screens | keep | A | — |
| 23 | Catalog | Digital pools, units, import, secret, status, reserve, release, consume | digital | `/catalog/digital-*` | web digital | `catalog_digital_screen` | keep | A | — |
| 24 | AI | Settings, employees, presets, default, activate, test, suggest, summarize | AI | `/ai/settings` `/ai/employees*` suggest/summarize | web AI settings | AI screens + chat | keep | A | — |
| 25 | Notifications | Center + preferences | notifications | `/notifications*` preferences | web list | both screens | keep | A | — |
| 26 | Plans | Read current plan + catalog | plans | GET `/plan` `/plans` | web compare is display | `plans_screen` | keep read; billing is E | A | — |
| 27 | Customers | Open profile + their conversations | customer | GET `/customers/{id}` + conversations | web also has writes | profile + drawer | keep; writes are E | A | — |
| 28 | Home | Activity snapshot | home | GET `/home` | web dashboard is wider | `/activity` | keep | A | — |
| 29 | Realtime | Socket config | realtime | GET `/realtime/config` | — | `HybridRealtimeService` | keep | A | — |
| 30 | Worker | Profile, photo, skills, languages, experience, identity, documents, verification | worker account | `/worker/*` | web worker profile | worker account | keep | A | — |
| 31 | Worker | Offers, engagements, guides, products, sessions, earnings, payouts, disputes | worker | matching `/worker` routes | web equivalents | worker screens | keep | A | — |
| 32 | Worker | Inbox + send | canonical inbox | `/conversations*` `inbox.actor` | web worker inbox duplicate | same Flutter inbox | keep canonical | A | — |
| 33 | Company | Worker discovery, open engagements, price, offer | company | `/company/worker-*` | — | company workers UI | keep | A | — |

### B — Flutter required, mobile contract exists

| # | Domain | Feature | Laravel | Mobile API | Web API/UI | Current Flutter | Target Flutter | Classification | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 34 | Push | Register and revoke device token | `DeviceController` `workspace.member` | POST/DELETE `/devices` | — | `NoopPushService`; in-app center works | company push on existing `PushService` | B | P1 |
| 35 | Catalog | Product templates list, create, clone, assign | `ProductTemplateService` | GET/POST templates, clone, assign. **No** update/delete on mobile or web routes | web index/create/clone | none | template manager for what the API allows | B | P1 |
| 36 | AI | Skills catalog | `SkillRegistry` | GET `/ai/skills` | skills ride on employee payload | presets only; no skills picker | picker when creating a custom employee | B | P2 |
| 37 | Catalog | Settings: general, communication, publish, draft, pause, archive | catalog settings | GET/PATCH settings, communication, four POSTs | web settings + transitions | repository + `chat_catalog_settings_controller` **with no screen** | settings section, not a second catalog app | B | P1 |
| 38 | Commerce | Operator cart | `CommerceCartService` | carts CRUD, items, validate, clear, cancel. Actor is a member; `customer_id`; channel `chat` | web cart **view** only | files under `features/commerce` **unrouted** | separate order desk. **Not** an add-to-cart button on the catalog manager | B | P2 |
| 39 | Commerce | Operator orders | `CommerceOrderService` | preview, convert, list, show, cancel. `source=commerce` | web order list/show | unrouted | same desk: list, open, cancel | B | P2 |
| 40 | Commerce | Intelligence read + evaluate | intelligence services | GET profile/attributes/requirements/item, POST evaluate | web also edits | none | read/evaluate after the desk exists | B | P2 |
| 41 | Sellers | Seller directory | `Seller` id, name, display_name | GET `/catalog/sellers` only. No create/update/delete route | no seller admin route found | none | show names where an order/item needs them. Do not invent seller CRUD | B | P3 |

### C — Flutter required, contract first

| # | Domain | Feature | Laravel | Mobile API | Web API/UI | Current Flutter | Target Flutter | Classification | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 42 | Email | Attachment list, open, preview | `EmailController::downloadAttachment` | GET download streams a file. Detailed resource is only `id`, `file_type`, `file_size` | web download, preview, delete | `EmailMessageModel` drops `attachments` | list + open after mobile-safe name, mime, size, download handle | C | P1 |
| 43 | Push | Independent Worker device token | devices route is `workspace.member` | worker is not a member, so POST `/devices` is the wrong gate | — | noop | worker-safe register/revoke, or an explicit in-app-only decision. Do not add workspace membership | C | P2 |

### E — Web-only today, must become mobile

| # | Domain | Feature | Laravel | Mobile API | Web API/UI | Current Flutter | Target Flutter | Classification | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 44 | Channels | Connect / disconnect WhatsApp, Facebook, Instagram, TikTok, Telegram | channel connect controllers | GET `/channels` + `manage_url` + `can_connect_in_app` flag with **no** mobile connect route | WhatsApp POST connect; IG/FB/TikTok OAuth redirect; FB disconnect; Telegram bot token POST; callbacks are server routes | list + external `manageUrl`. Flag is parsed and unused | native connect UI. OAuth stays on Laravel. Web remains fallback | E | P1 |
| 45 | Plans | Subscribe, change, cancel | `SubscriptionController` | none | store, checkout, return, confirm, destroy | read-only plans | Chat billing only. Not Finance invoices | E | P1 |
| 46 | Commerce | Tax setting | commerce tax | none | PUT `commerce/tax-setting` | none | one settings form after a mobile contract | E | P2 |
| 47 | Commerce | Intelligence authoring | attribute/requirement/rule/compatibility editors | mobile is read + evaluate only | full web CRUD | none | authoring screens after mobile write routes. Not part of catalog “add to cart” | E | P2 |
| 48 | Email | Mailbox accounts | email accounts | GET `/emails/accounts` (pick an account to send) | create, update, delete, sync, SMTP test | compose uses existing accounts | account admin so a mailbox can be added without Web | E | P2 |
| 49 | Email | Branding + template library | email branding/templates | none | edit branding, template library, preview, use | none | simple brand + template choice for outbound mail | E | P3 |
| 50 | Workspace | Workspace logo | dashboard logo | GET `/branding` is read-only platform name/color/logo, not an editor | POST/DELETE workspace logo | unused `BrandingRepository` | edit logo/name only for fields Laravel already stores | E | P3 |
| 51 | Customers | Directory create/edit, notes, tags, groups | web `CustomerController` | show + conversations only | resource except show; notes; tags/groups behind `advanced_customers` | open from chat/search only | CRM actions the web user does, after mobile writes. Tags stay behind the same feature gate | E | P2 |

### D — backend / infrastructure

| # | Domain | Feature | Laravel | Mobile API | Web API/UI | Current Flutter | Target Flutter | Classification | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 52 | Channels | Meta, WhatsApp, TikTok, Telegram, Resend webhooks | webhook controllers | none | public webhooks | none | none | D | — |
| 53 | Channels | OAuth callback endpoints | IG/FB/TikTok callback | none | server callback | none | Laravel keeps the callback; Flutter only starts and returns | D | — |
| 54 | Stories | Expire old stories | `StoryService::expireOldStories` | none | job | none | none | D | — |
| 55 | Media | Signed chat download route | attachment download | URL already on the message | web download | client opens the URL | none extra | D | — |
| 56 | Worker | Dedicated `/worker/workspaces/{id}/inbox*` | worker inbox | exists | web worker inbox | unused; canonical inbox is the product | none | D | — |
| 57 | Home | GET `/unread` | same counts as home | exists | — | unused; `/home` is the badge | none | D | — |
| 58 | Catalog | GET `/catalog/search` | duplicate of index `q` | exists | — | list uses `q` | none | D | — |
| 59 | Workspace | GET `/workspaces` and `/workspaces/current` | workspace index | exists | — | picker uses `/auth/me`; only switch is called | none | D | — |
| 60 | AI | POST `/ai/probe` | probe | exists | — | unused; employee test is the user action | none | D | — |
| 61 | Catalog | Dedicated show / activate / inventory routes | item sub-routes | exist | web activate | form loads the list row and saves `is_active` + policy on PATCH | none while save keeps working | D | — |

### F — not Hasim Chat Flutter

| # | Domain | Feature | Laravel | Mobile API | Web API/UI | Current Flutter | Target Flutter | Classification | Priority |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 62 | Payments | Non-commerce payment links, gateways, merchant KYC | `PaymentController` | none | web payments. Create **excludes** `source=commerce` | none | not this app (Finance/payment admin) | F | — |
| 63 | Workspace | API keys | api keys | none | web | none | advanced admin, not daily Chat | F | — |
| 64 | Workspace | Analytics dashboard | analytics | none | web | home snapshot only | not a second BI app | F | — |
| 65 | Appointments | Appointments | no Chat API | none | none | empty `features/appointments` | none until a product exists | F | — |
| 66 | Site | Legal pages, downloads, public assistant | public web | none | web | none | none | F | — |
| 67 | Commerce | Storefront “add to cart” on the catalog manager | cart API is operator-scoped | carts exist | web carts are staff views | unrouted commerce UI | do not restore this control | F | — |

---

## 3. Commerce decision (from code)

| Question | Evidence | Decision |
| --- | --- | --- |
| Is Chat commerce company operations? | Cart created by an authorized member for a customer, channel `chat`, then converted to an order | Yes. Operator desk |
| Is it an external customer storefront? | No public cart controller. Catalog Flutter is a management tool | No |
| Should Flutter get cart/orders? | Mobile contract exists and the company user cannot do it without Web | Yes, as its **own** desk (rows 38–40, 46–47) |
| Should catalog get “add to cart”? | That mixes management with selling and was rolled back for that reason | No (row 67) |

---

## 4. Known-gap recheck

| Item | Now |
| --- | --- |
| Company push | Still B. `NoopPushService` |
| Email attachments | Still C. Download route exists; resource is not mobile-safe |
| Native channel connect | Still E. `manageUrl` only |
| Worker push | Still C. Devices require membership |
| Product templates | Still B. Mobile list/create/clone/assign; no edit/delete in Laravel |
| AI skills | Still B |
| Branding GET | Not an editor. Workspace logo + email branding are E. Catalog visual settings are row 37 (B, unwired) |
| AI probe | D. Employee test covers the user |
| Unread | D. Home is authoritative |
| Catalog show / activate / inventory routes | D. The user actions exist on the product form |
| Story show | D. Viewer uses the list payload |
| Catalog publish/draft/pause | **Reopened as B.** Controller exists; screen does not call it |
| Global search | A for the three entities Laravel returns. Contacts and catalog already have their own search. Do not block on a wider `/search` |
| Commerce UI | Unrouted leftover. Not ✅ |

---

## 5. Orphan Flutter (do not delete in this audit)

| Piece | Why it is unused |
| --- | --- |
| `features/commerce/**` and commerce tests | Rollback leftover. Not in router, DI, catalog, or settings. Later operator desk should replace this, not revive storefront buttons |
| `chatCatalogSettingsController` + settings repository methods | Built, then the screen became a hub |
| `BrandingRepository.fetch` | No screen |
| `HomeRepository.unread` | Home uses `/home` |
| `WorkspaceRepository.list` / `current` | `/auth/me` |
| `AiRepository.probe` | Employee test is used |
| `DeviceRepository` | Held by the noop push service on purpose |
| `ChatCatalogRepository.show` / `updateInventory` / `setActive` | Form uses list row + PATCH |
| `StoryRepository.show` | Viewer uses the list |
| `ChannelModel.canConnectInApp` | Parsed, never read |
| `features/appointments` | Empty directory |

No Flutter HTTP path was found that is missing from `routes/mobile.php`.

---

## 6. Proposed phases (not started)

**Phase 1 — contract already exists, small UI**  
Company push on the current `PushService` (needs FCM/APNs credentials, not a new route). Wire catalog settings (row 37). Product templates for list/create/clone/assign only. AI skills picker.

**Phase 2 — small mobile contract, then UI**  
Email attachment fields + open/preview (row 42). Do not invent a client download path.

**Phase 3 — OAuth and billing**  
Channel connect/disconnect with Laravel-owned OAuth (row 44). Chat subscription checkout/change/cancel (row 45). Worker push only after a non-member device contract (row 43).

**Phase 4 — operator commerce and remaining admin**  
Order desk: cart, preview, orders, cancel (rows 38–39). Tax (46). Intelligence read then authoring (40, 47). Email accounts (48). Customer writes (51). Logo / email brand (49–50). Seller names only where the API has them (41). No catalog add-to-cart.

**Phase 5 — parity close**  
Remove or keep orphans with a reason. Full matrix recount. `flutter analyze`, focused tests, related Laravel tests, full Flutter suite. Final report with the same row IDs.

Dependencies: 38–39 before 40 and 46. 44 before treating channels as done. 42 before email files. 37 does not depend on commerce.

---

## 7. Backend contract required before Flutter

| Feature | Why Flutter cannot start |
| --- | --- |
| Channel connect/disconnect | No mobile connect/OAuth/disconnect. Callbacks must stay server-side |
| Subscription checkout, change, cancel | Web `SubscriptionController` only |
| Commerce tax | Web PUT only |
| Intelligence authoring | Mobile is read + evaluate |
| Email attachments | Download exists; name/mime/safe handle do not |
| Email accounts, branding, templates | Mobile can list accounts to send, not administer them |
| Workspace logo edit | GET `/branding` is not an update API |
| Customer create/edit/notes/tags | Mobile is show + conversations |
| Worker push | `POST /devices` requires `workspace.member` |
| Template edit/delete | Not in Laravel. Do not add Flutter edit until a route exists |

Rows 34–41 can start on the current mobile API.

---

## 8. Authorization notes

- Classification and handoff writes stay company/`workspace.member`. Worker uses canonical inbox and must not gain those writes.
- Story delete/viewers stay author-only on the server.
- Commerce cart/order uses `AuthorizesCommerceOrders`. Flutter hiding a button is not the control.
- Catalog and AI routes keep `workspace.feature` gates.
- No new Worker, Finance, or POS permission is in scope.

---

*End of master audit. Application code was not modified. Phase 1 has not started.*
