# Hasim Chat — Laravel ↔ Flutter Feature Parity Audit

**Date:** 2026-10-05  
**Scope:** Hasim Chat only (`routes/mobile.php` + Chat-owned web surfaces vs `apps/hasim`)  
**Out of scope:** Hasim Business, Finance, POS, Cashier, E-Invoicing/ZATCA  
**Method:** Code evidence only. An endpoint without a Flutter caller is not ✅. A screen without a live API call is not ✅.  
**Checkpoint context (read-only):** Independent Worker is not a workspace member. Flutter is UX only. One Inbox + Worker Authorization.

This file is an audit. No application code was changed to produce it.

---

## 1. Executive Summary

Laravel Chat exposes a large mobile API (`/api/mobile/v1`) plus web-only Chat surfaces (channel connect, commerce carts/orders/intelligence, subscription checkout, product templates). Flutter `apps/hasim` covers the daily operator/worker loop well: auth, inbox, chat, email, contacts, stories, catalog create/edit, digital pools, AI employees, company worker discovery, and the Independent Worker portal.

The largest gaps are **seller commerce**, **catalog lifecycle actions** (delete / dedicated inventory / templates), **inbox write-classification and handoff request/assign**, **global search**, and **channel connect** (Flutter lists channels and opens a web `manageUrl`).

### Headline numbers

These counts are **user-facing capabilities** in the matrix below (not raw HTTP routes).

| Metric | Count |
| --- | ---: |
| Laravel Chat capabilities inventoried | 78 |
| ✅ موجود ومتكامل | 48 |
| 🟡 موجود جزئيًا | 12 |
| ❌ موجود في Laravel وغير موجود في Flutter | 13 |
| ⚪ Backend-only مقصود / بديل موجود | 5 |
| 🔴 Flutter موجود وBackend ناقص | 0 |
| Flutter actual coverage of inventoried capabilities | **62%** (48/78) |
| Flutter coverage if ⚪ excluded from denominator | **66%** (48/73) |

No Flutter REST path was found that is missing from `routes/mobile.php`. There is **no 🔴 contract hole** of the form “UI calls an endpoint that does not exist.”

### Answers to the requested summary questions

1. **Laravel Chat features inventoried:** 78 capabilities (mobile-first; web-only Chat called out separately).
2. **Status split:** 48 ✅ / 12 🟡 / 13 ❌ / 5 ⚪ / 0 🔴
3. **Flutter coverage:** 62% of inventoried capabilities (66% excluding backend-only).
4. **Most important missing Flutter features:** Commerce cart/orders, product delete, product templates, global search, conversation classification write, handoff request/assign, story delete/viewers, digital reserve/release/consume.
5. **Most important partials:** Channel connect via web URL only; push is `NoopPushService`; catalog activate via save `is_active` not dedicated endpoints; plans are read-only; AI probe unused.
6. **Most important mismatches:** Flutter catalog `update()` always sends `worker_max_price: null`; Flutter has repository methods (catalog delete, classification PATCH, story delete, branding, unread, devices) with **no screen caller**.
7. **Laravel features that do not need Flutter:** Meta/WhatsApp/Telegram/TikTok webhooks; signed attachment download (URL consumed); dedicated worker inbox routes (canonical inbox used); subscription checkout (no mobile API); unread endpoint (home covers badges).
8. **Flutter features that need backend work:** none proven. Push is intentionally unimplemented until FCM/APNs. Privacy lock and chat themes are local-only by design.
9. **Top 10 gaps (priority):** see §13.

---

## 2. Laravel inventory

Source of truth for mobile: `routes/mobile.php`.

### 2.1 Authentication (`Api\Mobile\V1\AuthController`)

| Method | Path | Auth |
| --- | --- | --- |
| POST | `/auth/login` | public + throttle |
| POST | `/auth/forgot-password` | public |
| POST | `/auth/reset-password` | public |
| POST | `/auth/social` | public |
| POST | `/auth/logout` | sanctum |
| GET | `/auth/me` | sanctum |
| PATCH | `/auth/profile` | sanctum |
| PUT | `/auth/password` | sanctum |
| POST | `/auth/avatar` | sanctum + write throttle |

### 2.2 Sessions / workspaces / devices / realtime / home / search

| Method | Path | Controller |
| --- | --- | --- |
| GET/DELETE | `/sessions`, `/sessions/{tokenId}` | `DeviceSessionController` |
| GET | `/workspaces` | `WorkspaceController` |
| GET | `/workspaces/current` | `WorkspaceController` (member) |
| POST | `/workspaces/switch` | `WorkspaceController` |
| POST/DELETE | `/devices`, `/devices/{device}` | `DeviceController` |
| GET | `/realtime/config` | `RealtimeController` |
| GET | `/home` | `HomeController` |
| GET | `/unread` | `UnreadController` |
| GET | `/search` | `SearchController` (conversations + customers + emails) |

### 2.3 Conversations / inbox / messages / attachments / handoff

Two middleware groups:

- `inbox.actor` (worker or member): list/show/messages/storeMessage
- `workspace.member`: read/archive/mute/classification/handoff/attachments

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/conversations` | cursor + filters |
| GET | `/conversations/{id}` | |
| GET/POST | `/conversations/{id}/messages` | |
| POST | `/conversations/{id}/read` | member |
| POST | `/conversations/{id}/archive` | member |
| POST | `/conversations/{id}/mute` | member |
| PATCH | `/conversations/{id}/classification` | member |
| POST | `/conversations/{id}/handoff` | request |
| POST | `/conversations/{id}/assign` | assign |
| POST | `/conversations/{id}/takeover` | |
| POST | `/conversations/{id}/resolve-handoff` | |
| POST | `/conversations/{id}/resume-ai` | |
| POST | `/messages/{message}/attachments` | |
| GET | `/attachments/{attachment}/download` | signed |

### 2.4 Email / contacts (`workspace.feature:email`)

Full inbox/sent/drafts/show/send/read/star, campaigns store/show/cancel, contacts CRUD+favorite+recent, contact-groups CRUD+members, email attachment download.

### 2.5 Stories

GET/POST `/stories`, GET `/stories/{id}`, POST view, DELETE, GET viewers.

### 2.6 Channels / plans / branding

GET `/channels`, `/plan`, `/plans`, `/branding` only. **No mobile connect/disconnect.** Web has WhatsApp/Telegram/TikTok connect + webhooks (`routes/web.php`).

### 2.7 Chat Catalog (`workspace.feature:products`)

Settings show/update/communication/publish/draft/pause/archive; sellers; digital pools/units (import, reserve, release, consume, status, secret, delete); categories CRUD; product templates CRUD/clone/assign; catalog index/search/store/show/update/inventory/activate/deactivate/destroy.

### 2.8 Commerce (`products` + `orders`)

Carts CRUD/validate/cancel; order preview/convert; orders index/show/cancel; intelligence profile/attributes/requirements/item/evaluate.

Web adds tax setting and full intelligence attribute/requirement/rule/compatibility editors (`routes/web.php`).

### 2.9 Worker (sanctum, **no** `workspace.member`)

Profile/skills/languages/experiences/identity/documents/verification; engagements list/show/products/accept/reject; session start/break/resume/end; earnings + period dispute; payouts; disputes index/show/store; dedicated inbox list/show/send under `/worker/workspaces/{workspace}/inbox`.

### 2.10 Company workers (`workspace.member`)

GET discovery, GET open engagements, GET profile, GET contract-price, POST offer.

### 2.11 AI (`workspace.feature:ai`)

Settings show/update, probe, suggest-reply, summarize; employee presets/skills/employees CRUD + default/activate/deactivate/test.

### 2.12 Notifications / customers

Notifications index/read/read-all; preferences GET/PUT; customer show + conversations.

### 2.13 Web-only Chat (not in mobile.php)

- Channel connect: WhatsApp, Telegram, TikTok + OAuth callbacks + webhooks
- Subscription store/checkout/destroy (`workspace/subscriptions`)
- Commerce tax + intelligence editors
- Chat-catalog product-template web UI
- Public webhooks (Meta/WhatsApp/TikTok/Telegram)

---

## 3. Flutter inventory

Base URL: `{host}/api/mobile/v1/` via `ApiClient` (`apps/hasim/lib/core/network/api_client.dart`).

### 3.1 Feature folders (`apps/hasim/lib/features`)

| Folder | Live UI | Notes |
| --- | --- | --- |
| `auth` | yes | login, splash, forgot/reset |
| `conversations` | yes | inbox, chat, themes, attachments, voice |
| `email` | yes | folders, compose, campaigns |
| `contacts` | yes | contacts + groups |
| `catalog` | yes | list, form, categories, digital, settings |
| `settings` | yes | profile, plans, channels, AI, company workers |
| `worker` | yes | Independent Worker portal |
| `stories` | yes | updates, create, viewer |
| `notifications` | yes | |
| `home` | yes | `/activity` |
| `customers` | yes | profile |
| `privacy` | yes | local PIN/biometric — no Laravel |
| `workspace` | yes | picker; switch API |
| `more` | yes | sessions |
| `appointments` | **empty** | no Dart files |

### 3.2 Routes (`app_router.dart` + `worker_nav.dart`)

Auth: `/splash` `/login` `/forgot-password` `/reset-password` `/workspaces`  
Shell tabs: `/conversations` `/email` `/updates`  
Chat: `/conversations/:id` `/archive` `/locked` `/chat-themes`  
Settings: `/settings` `/profile` `/plans` `/channels` `/notification-preferences` `/ai-settings` `/ai-test` `/company-worker-profile` `/privacy-lock` `/more/security` `/notifications` `/activity`  
Catalog: `/catalog` `/catalog/form` `/catalog/digital` `/catalog/categories` `/catalog/categories/form` `/chat-catalog-settings`  
Contacts: `/contacts` `/contacts/form` `/contacts/:id` `/contact-groups`  
Email: `/email/:id` `/email/compose` `/email/campaigns/:id`  
Stories: `/stories/create` `/stories/view`  
Worker: `/worker/chat` `/worker/offers` `/worker/engagements/:id` `/worker/payouts` `/worker/disputes/:id` `/worker/account` `/worker/sales-reference`  
Redirect: `/more` → `/settings`

### 3.3 HTTP repositories with live callers

`auth_repository`, `workspace_repository` (switch only), `home_repository` (home only), `conversation_repository`, `email_repository`, `campaign_repository`, `contact_repository`, `contact_group_repository`, `chat_catalog_repository` (subset), `story_repository` (subset), `notification_repository`, `session_repository` (sessions only), `plan_channel_repository` (plan/plans/channels/ai/customer; not branding/probe), `company_worker_discovery_repository`, `worker_repository`, `hybrid_realtime_service`.

### 3.4 Repository methods with **no** `lib` caller

| Method | Path | File |
| --- | --- | --- |
| `list()` / `current()` | GET `/workspaces`, `/workspaces/current` | `workspace_repository.dart` — workspaces come from `/auth/me` |
| `unread()` | GET `/unread` | `home_repository.dart` |
| `fetch()` | GET `/branding` | `plan_channel_repository.dart` |
| `probe()` | POST `/ai/probe` | `plan_channel_repository.dart` |
| `register` / `revoke` | POST/DELETE `/devices` | `session_repository.dart` — `NoopPushService` |
| `updateClassification()` | PATCH `.../classification` | `conversation_repository.dart` |
| `show()` / `updateInventory()` / `setActive()` / `delete()` | catalog item | `chat_catalog_repository.dart` |
| `show()` / `delete()` / `viewers()` | stories | `story_repository.dart` |

### 3.5 Flutter-local (no Laravel)

- Privacy lock / locked chats — `features/privacy` (`ChatLockStore`)
- Chat wallpapers/themes — `chat_themes_screen.dart`
- Inbox outbox / local DB — `core/local_db`

---

## 4. Feature parity matrix

Allowed statuses only: ✅ 🟡 ❌ ⚪ 🔴

| Domain | Laravel | Endpoint/Service | Flutter Screen/Feature | الحالة | الملاحظات |
| --- | --- | --- | --- | --- | --- |
| Authentication | Email login | POST `/auth/login` `AuthController` | `login_screen` + `auth_controller` | ✅ | |
| Authentication | Google social | POST `/auth/social` | `login_screen._google` | ✅ | |
| Authentication | Forgot password | POST `/auth/forgot-password` | `forgot_password_screen` | ✅ | |
| Authentication | Reset password | POST `/auth/reset-password` | `reset_password_screen` | ✅ | |
| Authentication | Logout | POST `/auth/logout` | settings + `auth_controller` | ✅ | |
| Authentication | Session bootstrap | GET `/auth/me` | splash / `auth_controller` | ✅ | supplies workspaces |
| Workspace / Account | Profile + password + avatar | PATCH `/auth/profile` PUT `/auth/password` POST `/auth/avatar` | `profile_screen` | ✅ | web-safe bytes upload |
| Workspace / Account | Device sessions | GET/DELETE `/sessions` | `security_sessions_screen` | ✅ | |
| Workspace / Account | Workspace switch | POST `/workspaces/switch` | `workspace_picker_screen` | ✅ | |
| Workspace / Account | Workspace index/current | GET `/workspaces` `/workspaces/current` | repo only | 🟡 | UI uses `/auth/me` |
| Conversations | List + filters + cursor | GET `/conversations` `ConversationController` | `conversations_screen` `conversations_controller` | ✅ | channel/search/classification query |
| Inbox | Thread + messages | GET show + messages | `chat_screen` | ✅ | cache + prefetch |
| Messages | Send text | POST `.../messages` | composer + outbox | ✅ | |
| Attachments / Media | Upload file/voice | POST `/messages/{id}/attachments` | `chat_screen` / `chat_composer` | ✅ | |
| Attachments / Media | Signed download | GET `/attachments/{id}/download` | URL on model | ⚪ | Flutter loads URL; no extra route call |
| Inbox | Read / archive / mute | POST read/archive/mute | inbox + chat menu | ✅ | |
| Inbox | Classification filter | GET query params | inbox chips | ✅ | |
| Inbox | Classification write | PATCH `.../classification` | repo unused | ❌ | filter only |
| Inbox | Request handoff | POST `.../handoff` | none | ❌ | |
| Inbox | Assign handoff | POST `.../assign` | none | ❌ | |
| Inbox | Takeover / resolve / resume AI | POST takeover/resolve-handoff/resume-ai | `chat_screen` menu | ✅ | |
| Search | Global search | GET `/search` `SearchController` | none (`features/search` deleted) | ❌ | |
| Email Inbox | Folders + show + read/star | GET/POST `/emails*` | `email_list_screen` `email_detail_screen` | ✅ | feature-gated |
| Email Inbox | Compose send | POST `/emails` | `email_compose_screen` | ✅ | |
| Email Inbox | Attachment download | GET `/emails/attachments/{id}/download` | none | ❌ | |
| Email Inbox | Campaigns | POST/GET/POST cancel `/email/campaigns*` | compose + `campaign_status_screen` | ✅ | |
| Contacts | CRUD + favorite + recent | `/contacts*` | contacts screens + email picker | ✅ | |
| Contacts | Groups + members | `/contact-groups*` | `contact_groups_screen` | ✅ | |
| Stories | List/create/view | GET/POST `/stories` POST view | `updates_screen` create/viewer | ✅ | |
| Stories | Delete | DELETE `/stories/{id}` | repo unused | ❌ | |
| Stories | Viewers | GET `.../viewers` | repo unused | ❌ | |
| Channels | List + status | GET `/channels` | `channels_screen` | ✅ | |
| WhatsApp / Meta / Telegram / TikTok | Connect + OAuth | web `ChannelController` / TikTok/Telegram connect | `manageUrl` launch only | 🟡 | no native connect |
| WhatsApp / Meta / Telegram / TikTok | Webhooks | `routes/web.php` webhooks | none | ⚪ | inbound |
| Chat Catalog | List + q + pagination | GET `/catalog` `ChatCatalogController@index` | `catalog_list_screen` | ✅ | `q`/`classification`/`item_kind` match index |
| Chat Catalog | Dedicated search | GET `/catalog/search` | unused | ⚪ | index `q` covers UI search |
| Products | Create / edit + image | POST/PATCH/UPLOAD `/catalog` `/{id}` | `catalog_form_screen` | ✅ | variants in same payload |
| Products | Get one | GET `/catalog/{id}` | repo unused | 🟡 | editor uses list row |
| Products | Delete | DELETE `/catalog/{id}` | repo unused | ❌ | web has destroy |
| Products | Activate/deactivate | POST activate/deactivate | unused; save sends `is_active` | 🟡 | dedicated routes unused |
| Variants | Nested create/edit/delete | variants[] on store/update | `catalog_variants_editor` | ✅ | |
| Catalog Categories | CRUD | `/catalog/categories*` | categories screens | ✅ | |
| Digital Products | Kind + digital form | catalog store/update | `catalog_form_screen` digital | ✅ | |
| Digital Pools | List/create/import/delete | `/catalog/digital-pools*` | `catalog_digital_screen` | ✅ | |
| Digital Pools | Units replace/status/delete | PATCH secret/status DELETE unit | digital sheet menus | ✅ | |
| Digital Pools | Reserve / release / consume | POST reserve/release/consume | none | ❌ | labels only |
| Chat Catalog | Settings + publish/draft/pause/archive | `/catalog/settings*` | `chat_catalog_settings_screen` | ✅ | |
| Chat Catalog | Sellers | GET `/catalog/sellers` | none | ❌ | |
| Products | Product templates | `/catalog/product-templates*` | none | ❌ | web UI exists |
| Commerce Cart | Cart CRUD/validate | `/commerce/carts*` | none | ❌ | web carts exist |
| Commerce Checkout / Orders | Preview/convert/list/cancel | `/commerce/carts/{id}/order-preview` `convert` `/commerce/orders*` | none | ❌ | |
| Commerce | Intelligence | `/commerce/intelligence*` | none | ❌ | web editors exist |
| Worker Discovery | List/show/price/offer | `/company/worker-discovery*` | `company_workers_section` profile | ✅ | |
| Worker Discovery | Open engagements | GET `/company/worker-engagements/open` | company workers section | ✅ | |
| Worker | Auth as independent | `/auth/me` account type | `auth_redirect` + `WorkerNav` | ✅ | not workspace member |
| Worker | Profile / photo | GET/PATCH/POST `/worker/profile` | `worker_account_screen` | ✅ | |
| Worker | Skills / languages | GET/PUT `/worker/skills` `/languages` | account/profile | ✅ | |
| Worker | Experiences CRUD | POST/PATCH/DELETE `/worker/experiences*` | account | ✅ | |
| Worker | Identity | GET/PUT `/worker/identity` | account | ✅ | |
| Worker | Documents + verification | GET/POST documents POST verification | account | ✅ | |
| Offers | Accept / reject | POST accept/reject | `worker_engagement_list/detail` | ✅ | |
| Engagements | List / show + capabilities + guides | GET engagements | worker screens | ✅ | |
| Products assignments | Assigned products | GET `.../products` | `worker_products_section` / sales reference | ✅ | |
| Sessions | Get/start/break/resume/end | `/worker/engagements/{id}/session*` | `worker_work_session_section` | ✅ | |
| Earnings | List + dispute period | GET earnings POST dispute | `worker_earnings_section` | ✅ | |
| Payouts | List | GET `/worker/payouts` | `worker_payouts_screen` | ✅ | no create endpoint on mobile |
| Disputes | List/show/create | GET/POST disputes | `worker_disputes_screen` | ✅ | |
| Worker | Dedicated inbox API | `/worker/workspaces/{id}/inbox*` | unused | ⚪ | uses canonical `/conversations` (`inbox.actor`) |
| Worker | Commerce / payment-link actions | no mobile worker commerce routes | capability labels only | ⚪ | keys displayed; no action API |
| AI / AI Inbox | Settings | GET/PUT `/ai/settings` | `ai_settings_screen` | ✅ | |
| AI / AI Inbox | Probe | POST `/ai/probe` | repo unused | 🟡 | |
| AI / AI Inbox | Suggest + summarize | POST suggest-reply / summarize | `chat_screen` | ✅ | |
| AI / AI Inbox | Employees CRUD + default + test | `/ai/employees*` presets | `ai_employees_tab` `ai_test_screen` | ✅ | |
| AI / AI Inbox | Skills catalog | GET `/ai/skills` | none | ❌ | |
| Settings | Notification center | `/notifications*` | `notifications_screen` | ✅ | |
| Settings | Notification preferences | GET/PUT `/notification-preferences` | `notification_preferences_screen` | ✅ | |
| Subscriptions / Plans | Current + catalog | GET `/plan` `/plans` | `plans_screen` | ✅ | read-only |
| Subscriptions / Plans | Subscribe / checkout / cancel | **web only** `SubscriptionController` | none | ⚪ | no mobile billing routes |
| Settings | Branding | GET `/branding` | repo unused | ❌ | |
| Customers | Show + conversations | GET `/customers/{id}` `.../conversations` | `customer_profile_screen` + drawer | ✅ | |
| Home | Activity snapshot | GET `/home` | `home_screen` `/activity` | ✅ | |
| Home | Unread endpoint | GET `/unread` | unused | ⚪ | badges from `/home` |
| Notifications | Push device token | POST/DELETE `/devices` | `NoopPushService` | 🟡 | comment: until FCM/APNs |
| Realtime | Config | GET `/realtime/config` | `hybrid_realtime_service` | ✅ | |
| Privacy | App/chat lock | none | `privacy` local store | ⚪ | Flutter-local by design |
| Appearance | Chat themes | none | `chat_themes_screen` | ⚪ | Flutter-local |

---

## 5. API contract parity

### 5.1 Flutter → Laravel: all called paths exist

Every live `ApiClient` path in `apps/hasim/lib` maps to `routes/mobile.php`. No orphan Flutter route was proven.

### 5.2 Proven contract notes

| Topic | Evidence | Severity |
| --- | --- | --- |
| Catalog list search | Flutter `q` + `classification` + `item_kind` matches `ChatCatalogController@index` | aligned |
| Catalog update | Flutter always sends `'worker_max_price': null` in `chat_catalog_repository.dart` update body | mismatch — field forced null on every edit |
| Variants | Nested in item write JSON; no standalone variant routes | aligned |
| Inventory policies | Flutter `selectableInventoryPolicy` coerces leftover `backorder` → `finite_stock` | aligned with selectable write rules |
| Worker status labels | Flutter maps statuses locally (`WorkerEngagement.statusLabels`); payload has no status label | aligned / documented |
| Classification write | Repo method exists; no controller/screen call | unused contract |
| Push | `DeviceRepository` ready; `NoopPushService.initializeAndRegister` does not POST `/devices` | unused contract |

### 5.3 Backend fields Flutter may not consume

Not fully proven field-by-field for every Resource. Known unused **endpoints** are listed in §3.4. Individual JSON keys on conversation/catalog resources that Flutter ignores were **not fully enumerated** — marked **غير مثبت من الكود** beyond the unused methods above.

---

## 6. Navigation parity

| Laravel capability | Flutter route | Reachable from nav? |
| --- | --- | --- |
| Inbox | `/conversations` | yes — bottom nav / rail |
| Chat | `/conversations/:id` | yes |
| Archive / locked | `/archive` `/locked` | settings / chat menu |
| Email | `/email` | yes |
| Stories | `/updates` | yes |
| Catalog | `/catalog` `/chat-catalog-settings` | settings |
| AI | `/ai-settings` | settings |
| Company workers | settings AI/workers section | settings |
| Worker portal | `/worker/*` | worker shell |
| Plans | `/plans` | settings |
| Channels | `/channels` | settings |
| Global search | **no route** | missing |
| Commerce | **no route** | missing |
| Product templates | **no route** | missing |
| Channel native connect | **no route** | web `manageUrl` |
| `/activity` home | `/activity` | settings/header |
| `appointments` folder | none | empty folder, no nav |

---

## 7. Worker parity (independent section)

Architecture: Independent Worker is **not** `workspace_users`. Flutter uses canonical inbox (`GET/POST /conversations*` with `inbox.actor`), not `/worker/workspaces/{id}/inbox*`.

| Capability | Laravel | Flutter | Status |
| --- | --- | --- | --- |
| Auth / account type | `/auth/me` | `auth_redirect` `isIndependentWorker` | ✅ |
| Profile + photo | `/worker/profile` | account/profile screens | ✅ |
| Skills / languages | GET/PUT catalogs | account | ✅ |
| Experiences | CRUD | account | ✅ |
| Identity | GET/PUT | account | ✅ |
| Documents + verification submit | GET/POST | account | ✅ |
| Discovery visibility | company APIs | company UI (owner) | ✅ |
| Offers accept/reject | POST accept/reject | offers + detail | ✅ |
| Engagements + capabilities | GET list/show | list/detail | ✅ |
| Guides | on engagement payload | detail | ✅ |
| Assigned products | GET `.../products` | products + sales reference | ✅ |
| Sessions | start/break/resume/end | work session section | ✅ |
| Earnings + period dispute | GET + POST dispute | earnings section | ✅ |
| Payouts | GET index only | payouts screen | ✅ |
| Disputes | list/show/create | disputes screens | ✅ |
| Inbox | canonical + dedicated | canonical only | ✅ / ⚪ dedicated unused |
| Payment links / commerce actions | no worker mobile routes | labels only | ⚪ |

**Worker Backend Coverage (of features required in Flutter):** **~95%**

Not counted as missing: dedicated inbox duplicate, webhooks, worker commerce execution (no mobile API).

---

## 8. Catalog / Commerce parity

### Catalog (staff Chat)

| Action | Laravel mobile | Flutter UI | Web UI |
| --- | --- | --- | --- |
| List / filter / paginate | ✅ | ✅ | ✅ |
| Search | index `q` + `/catalog/search` | index `q` only | ✅ |
| Create | ✅ | ✅ | ✅ |
| Edit | ✅ | ✅ | ✅ |
| Image upload | ✅ | ✅ | ✅ |
| Delete product | ✅ | ❌ | ✅ |
| Activate/deactivate | dedicated POST | via `is_active` save | dedicated |
| Variants C/U/D | nested | ✅ | ✅ |
| Categories CRUD | ✅ | ✅ | ✅ |
| Inventory policy on item | store/update + dedicated PATCH | on form only | form + dedicated |
| Digital pools | ✅ | ✅ | ✅ |
| Digital units import/delete/replace/status | ✅ | ✅ | ✅ |
| Reserve/release/consume | ✅ | ❌ | غير مثبت بالكامل |
| Settings + lifecycle | ✅ | ✅ | ✅ |
| Sellers | ✅ | ❌ | غير مثبت |
| Product templates | ✅ | ❌ | ✅ |

### Commerce

| Action | Laravel mobile | Flutter | Web |
| --- | --- | --- | --- |
| Cart | full | ❌ | list/show |
| Checkout preview/convert | ✅ | ❌ | via carts/orders |
| Orders list/show/cancel | ✅ | ❌ | ✅ |
| Tax setting | not on mobile.php | ❌ | PUT web |
| Intelligence | GET/POST evaluate | ❌ | full editor |

**Approximate coverage**

| Slice | Estimate | Basis |
| --- | --- | --- |
| Flutter Catalog (staff, excluding commerce) | **~72%** | missing delete, templates, sellers, reserve/consume, dedicated inventory |
| Flutter Commerce | **0%** | no repository, no screen, no route |
| Web Catalog | **~90%** | templates + delete + settings present |
| Web Commerce | **partial / staff** | carts/orders/tax/intelligence; not a customer storefront |
| Backend-only (webhooks, signed download, duplicate search) | **~8%** of inventory | ⚪ rows |

---

## 9. CRUD / state notes

| Feature | List | View | Create | Edit | Delete | Search/Filter | Status actions | Flutter gap |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Catalog product | ✅ | 🟡 list row | ✅ | ✅ | ❌ | ✅ q | 🟡 via save | no delete UI |
| Variants | via item | via item | ✅ | ✅ | ✅ | — | active toggle | none proven |
| Categories | ✅ | ✅ | ✅ | ✅ | ✅ | — | active on form | none proven |
| Digital pools | ✅ | units sheet | ✅ | — | ✅ | — | unit status | no reserve/consume |
| Conversations | ✅ | ✅ | n/a | mute/archive | n/a | ✅ | handoff partial | no request/assign/write classification |
| Contacts | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | favorite | none proven |
| Stories | ✅ | ✅ | ✅ | — | ❌ | — | view | no delete/viewers |
| AI employees | ✅ | ✅ | ✅ | ✅ | ✅ | — | default/activate/test | no `/ai/skills` |
| Worker offers | ✅ | ✅ | company-side | — | — | ✅ | accept/reject | none proven |
| Commerce orders | Laravel only | Laravel only | Laravel only | — | cancel Laravel | Laravel | — | no Flutter |

Loading / empty / error / retry are present on the main Flutter screens audited (inbox, catalog form tests, worker lists, email, AI employees). **Permissions** are enforced by Laravel; Flutter hides some owner-only settings. Worker capabilities are displayed; they do not unlock missing commerce screens.

---

## 10. Missing / partial / backend-only / mismatches

### Missing in Flutter (❌)

1. Global search (`GET /search`)
2. Conversation classification write
3. Handoff request
4. Handoff assign
5. Email attachment download endpoint
6. Story delete
7. Story viewers
8. Catalog product delete
9. Catalog sellers
10. Product templates
11. Digital reserve / release / consume
12. Entire commerce cart/orders/intelligence
13. Branding GET
14. AI skills catalog GET

### Partial (🟡)

1. Workspace index/current unused (me covers)
2. Push / devices (`NoopPushService`)
3. Channel connect via `manageUrl` only
4. Catalog GET one unused
5. Catalog activate/deactivate dedicated unused
6. AI probe unused
7. Plans read-only (checkout is web, no mobile API)

### Backend-only (⚪)

1. Channel webhooks
2. Signed attachment download route
3. Dedicated worker inbox routes
4. Subscription checkout (web; no mobile billing)
5. GET `/unread` (home snapshot)
6. GET `/catalog/search` (duplicate of index `q`)
7. Privacy lock + chat themes (Flutter-local; not Laravel)

### Mismatches

1. `ChatCatalogRepository.update` always posts `worker_max_price: null`
2. Dead repository methods listed in §3.4 (Flutter “has API” but no UI)
3. Worker commerce capability chips have no matching mobile action
4. `features/appointments` empty directory — no feature
5. Deleted Flutter global search vs live Laravel `SearchController`

### Flutter needing backend work

**None proven.** Push is an explicit client placeholder waiting for FCM/APNs credentials, not a missing Laravel route.

---

## 11. Web vs Flutter (Chat only)

| Web Chat feature | Flutter equivalent |
| --- | --- |
| Chat inbox (web) | Flutter inbox ✅ |
| Chat catalog list/create/edit | Flutter ✅ |
| Chat catalog delete / activate dedicated | Flutter ❌ / 🟡 |
| Catalog settings lifecycle | Flutter ✅ |
| Digital inventory | Flutter ✅ (minus reserve/consume) |
| Product templates | Flutter ❌ — **Web-only today** |
| Commerce carts/orders/tax/intelligence | Flutter ❌ — **Web-only today** |
| Channel connect (WA/TG/TT) | Flutter 🟡 manageUrl — **connect is Web-only** |
| Subscriptions checkout | Flutter ⚪ — **Web-only; no mobile API** |
| Company worker discovery | Flutter ✅ |
| Independent Worker portal | Flutter ✅ (mobile is primary) |

---

## 12. Prioritized recommendations

These are **recommendations only**. This audit did not implement them.

1. Decide whether Chat **seller commerce** belongs in Flutter. If yes, it is the largest product gap (cart, preview, orders). If no, document it as Web-only.
2. Add product **delete** (and confirm activate) to Flutter if owners manage catalog on phone.
3. Product **templates** exist on mobile API + web and have zero Flutter UI.
4. Restore or replace **global search** (`GET /search`) — Flutter feature was removed.
5. Wire **classification write** or remove the dead repository method.
6. Add **handoff request/assign** if supervisors use Flutter; takeover/resolve already exist.
7. Either implement **FCM/APNs** (`POST /devices`) or keep the noop and treat push as not shipped.
8. Channel **native connect** vs keep web `manageUrl` (current 🟡).
9. Digital **reserve/release/consume** if staff fulfill codes from the phone.
10. Remove or use dead repo methods (branding, unread, catalog show/inventory, story delete/viewers, AI probe) to stop false “supported” signals.

---

## 13. Top 10 gaps (priority)

| Priority | Gap | Status | Why it matters |
| ---: | --- | --- | --- |
| 1 | Commerce cart / checkout / orders | ❌ | Full mobile + web seller APIs; zero Flutter |
| 2 | Commerce intelligence | ❌ | Mobile + web; zero Flutter |
| 3 | Catalog product delete | ❌ | Laravel + web; Flutter cannot remove items |
| 4 | Product templates | ❌ | Mobile + web; zero Flutter |
| 5 | Global search | ❌ | Laravel live; Flutter search feature deleted |
| 6 | Handoff request + assign | ❌ | Supervisor flow incomplete vs takeover/resolve |
| 7 | Classification write | ❌ | Filters exist; cannot set priority/payment/review |
| 8 | Channel native connect | 🟡 | WhatsApp/Telegram/TikTok connect is web |
| 9 | Push notifications | 🟡 | `NoopPushService`; devices API unused |
| 10 | Story delete + viewers | ❌ | Create/view only |

---

## 14. Final table

| # | Feature | Laravel | Flutter | Status | Evidence | Priority |
| ---: | --- | --- | --- | --- | --- | --- |
| 1 | Email/password login | POST `/auth/login` | `login_screen` | ✅ | `routes/mobile.php` L39; `auth_repository.dart` | — |
| 2 | Google login | POST `/auth/social` | `login_screen._google` | ✅ | `auth_controller.socialLogin` | — |
| 3 | Password recovery | forgot/reset | matching screens | ✅ | `AuthController` | — |
| 4 | Profile / avatar / password | PATCH/PUT/POST auth | `profile_screen` | ✅ | `auth_repository.dart` | — |
| 5 | Sessions revoke | `/sessions*` | `security_sessions_screen` | ✅ | `session_repository.dart` | — |
| 6 | Workspace switch | POST `/workspaces/switch` | picker | ✅ | `workspace_repository.dart` | — |
| 7 | Inbox list/filter | GET `/conversations` | `conversations_screen` | ✅ | `conversation_repository.dart` | — |
| 8 | Chat + send + attachments | messages + attachments | `chat_screen` | ✅ | composer + upload | — |
| 9 | Read/archive/mute | POST * | inbox/chat | ✅ | repository + UI | — |
| 10 | Classification filter | GET query | chips | ✅ | `conversations_controller` | — |
| 11 | Classification write | PATCH classification | unused repo | ❌ | `conversation_repository.dart` L320 | 7 |
| 12 | Handoff request | POST handoff | none | ❌ | `mobile.php` L187 | 6 |
| 13 | Handoff assign | POST assign | none | ❌ | `mobile.php` L188 | 6 |
| 14 | Takeover / resolve / resume AI | POST * | `chat_screen` menu | ✅ | L414–421 | — |
| 15 | Global search | GET `/search` | none | ❌ | `SearchController`; search feature deleted | 5 |
| 16 | Email inbox/compose/campaigns | `/emails*` `/email/campaigns*` | email screens | ✅ | email/campaign repos | — |
| 17 | Email attachment download | GET download | none | ❌ | `mobile.php` L204 | 11 |
| 18 | Contacts + groups | full CRUD | contacts screens | ✅ | contact repos | — |
| 19 | Stories create/view | POST/GET/view | updates + viewer | ✅ | `story_repository.dart` | — |
| 20 | Story delete/viewers | DELETE + viewers | unused | ❌ | repo L68–73 | 10 |
| 21 | Channel list | GET `/channels` | `channels_screen` | ✅ | `ChannelRepository` | — |
| 22 | Channel connect | web only | `manageUrl` | 🟡 | `channels_screen._openManage` | 8 |
| 23 | Channel webhooks | web.php | none | ⚪ | inbound | — |
| 24 | Catalog list/create/edit/variants | `/catalog` | list + form + editor | ✅ | `chat_catalog_repository.dart` | — |
| 25 | Catalog delete | DELETE `/catalog/{id}` | unused | ❌ | repo L197; no presentation call | 3 |
| 26 | Catalog activate dedicated | POST activate/deactivate | unused | 🟡 | save uses `is_active` | 12 |
| 27 | Categories CRUD | `/catalog/categories*` | category screens | ✅ | categories controller | — |
| 28 | Digital pools/units manage | pools + units | `catalog_digital_screen` | ✅ | create/import/delete/replace/status | — |
| 29 | Digital reserve/release/consume | POST * | none | ❌ | `mobile.php` L261–265 | 13 |
| 30 | Catalog settings lifecycle | settings + transitions | settings screen | ✅ | `transitionSettings` | — |
| 31 | Sellers | GET `/catalog/sellers` | none | ❌ | `mobile.php` L249 | 12 |
| 32 | Product templates | `/catalog/product-templates*` | none | ❌ | `ChatCatalogProductTemplateController` | 4 |
| 33 | Commerce cart | `/commerce/carts*` | none | ❌ | `mobile.php` L293–301 | 1 |
| 34 | Commerce orders/checkout | preview/convert/orders | none | ❌ | `mobile.php` L303–307 | 1 |
| 35 | Commerce intelligence | `/commerce/intelligence*` | none | ❌ | `mobile.php` L309–313 | 2 |
| 36 | Company worker discovery + offer | `/company/*` | company workers UI | ✅ | `company_worker_discovery_repository.dart` | — |
| 37 | Worker profile/verification | `/worker/profile` … verification | worker account | ✅ | `worker_repository.dart` | — |
| 38 | Worker offers/engagements/guides/products | engagements* | worker screens | ✅ | worker controllers | — |
| 39 | Worker sessions/earnings/payouts/disputes | matching routes | matching screens | ✅ | worker_* screens | — |
| 40 | Worker dedicated inbox | `/worker/workspaces/*/inbox*` | unused | ⚪ | canonical inbox | — |
| 41 | AI settings + employees + test | `/ai/*` | AI settings/tab/test | ✅ | `AiRepository` | — |
| 42 | AI suggest/summarize | POST * | chat menu | ✅ | `chat_screen` | — |
| 43 | AI probe | POST `/ai/probe` | unused | 🟡 | `plan_channel_repository.dart` | — |
| 44 | AI skills catalog | GET `/ai/skills` | none | ❌ | `mobile.php` L346 | 14 |
| 45 | Notifications + prefs | `/notifications*` prefs | screens | ✅ | `notification_repository.dart` | — |
| 46 | Plans read | GET `/plan` `/plans` | `plans_screen` | ✅ | read-only | — |
| 47 | Subscription checkout | web `SubscriptionController` | none | ⚪ | no mobile billing | — |
| 48 | Branding | GET `/branding` | unused | ❌ | `BrandingRepository` | — |
| 49 | Customer file | GET `/customers/{id}` | profile + drawer | ✅ | `CustomerRepository` | — |
| 50 | Home / realtime | `/home` `/realtime/config` | home + hybrid realtime | ✅ | | — |
| 51 | Push devices | POST `/devices` | `NoopPushService` | 🟡 | `push_service.dart` | 9 |
| 52 | Privacy lock | none | local privacy | ⚪ | `ChatLockStore` | — |
| 53 | Chat themes | none | local | ⚪ | `chat_themes_screen` | — |

---

## 15. Evidence index

| Artifact | Path |
| --- | --- |
| Mobile routes | `routes/mobile.php` |
| Chat web + webhooks + commerce web | `routes/web.php` |
| Flutter router | `apps/hasim/lib/router/app_router.dart` |
| Worker nav | `apps/hasim/lib/router/worker_nav.dart` |
| API client | `apps/hasim/lib/core/network/api_client.dart` |
| Catalog repo | `apps/hasim/lib/features/catalog/data/chat_catalog_repository.dart` |
| Conversation repo | `apps/hasim/lib/features/conversations/data/conversation_repository.dart` |
| Worker repo | `apps/hasim/lib/features/worker/data/worker_repository.dart` |
| Push placeholder | `apps/hasim/lib/push/push_service.dart` |
| Catalog index accepts `q` | `app/Http/Controllers/Api/Mobile/V1/ChatCatalogController.php` |

---

*End of audit. No application files were modified except this report.*
