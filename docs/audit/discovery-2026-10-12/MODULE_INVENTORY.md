# Module Inventory — HASEM Discovery Audit

Legend: **Implemented (code)** | **Partial** | **Schema/docs only** | **Not in tree** | **Unknown (runtime)**

Each row cites primary evidence. Line numbers refer to files read during audit.

---

## 1. Identity, auth, registration

| Field | Detail |
| --- | --- |
| Purpose | Web session login (Breeze), Sanctum API tokens, OTP/social hooks, platform admin guard |
| Status | **Implemented (code)** for web + mobile auth; platform admin separate |
| Models/tables | `users`, `auth_identities`, `personal_access_tokens` (migration); `User`, `AuthIdentity` |
| Routes | `routes/auth.php`, `routes/api.php` `/auth/*`, `routes/mobile.php` `/auth/*`, `routes/platform.php` login |
| Services | `app/Services/Auth/*`, `app/Services/Mobile/MobileAuthService.php` |
| UI | Blade `resources/views/auth/*`; Flutter `apps/hasim/lib/features/auth/` |
| Tenancy | User is global; workspace via membership |
| Tests | `tests/Feature/Feature/Mobile/*Auth*` (exist; not run) |
| Dependencies | Spatie permissions, Sanctum |
| Risk if changed | Breaks all surfaces |

**Evidence:** `bootstrap/app.php` guards; `app/Http/Controllers/Platform/AuthController.php` lines 20–38 (platform login validation).

---

## 2. Workspaces, membership, switching

| Field | Detail |
| --- | --- |
| Purpose | Multi-tenant workspaces; session `current_workspace_id`; header `X-Workspace-Id` on API |
| Status | **Implemented (code)** |
| Models | `Workspace`, `WorkspaceUser`, `WorkspaceFeatureFlag` |
| Middleware | `ResolveWorkspaceContext`, `EnsureWorkspaceSelected`, `EnsureWorkspaceMembership` |
| Services | `WorkspaceResolverService`, `WorkspaceContext` |
| Flutter | `apps/hasim/lib/features/workspace/presentation/workspace_picker_screen.dart` |
| Tests | Cross-workspace tests e.g. `CrossWorkspaceIdorTest` |
| Dependencies | Spatie `setPermissionsTeamId` in `ResolveWorkspaceContext.php` lines 31–32 |

**Evidence:** `app/Http/Middleware/ResolveWorkspaceContext.php`; `app/Models/WorkspaceScopedModel.php`.

---

## 3. RBAC & feature flags

| Field | Detail |
| --- | --- |
| Purpose | Spatie roles/permissions per workspace; plan entitlements |
| Status | **Implemented (code)** |
| Services | `FeatureAccessService`, `EnsureFeatureAccess` |
| Tests | Feature gate tests under `tests/Feature/Feature/Mobile/Mobile*FeatureGate*` |
| Docs vs code | Receptionist/accountant as Spatie roles; membership enum still owner/admin/manager/agent/member — **Partial** UX |

**Evidence:** `app/Http/Middleware/EnsureFeatureAccess.php` lines 21–44; `docs/audit/DOMAIN-CATALOG-SEPARATION.md` lines 33–35.

---

## 4. Customers & lightweight CRM

| Field | Detail |
| --- | --- |
| Purpose | Customers, notes, tags, groups; CRM leads (`crm_leads` per migrations) |
| Status | **Implemented (code)** for customers; leads **Unknown** (no `CrmLead` model in `app/Models` listing) |
| Models | `Customer`, `CustomerNote`, `CustomerTag`, `CustomerGroup` |
| Routes | Web workspace customers; mobile `/customers`; inbox links |
| Tests | IDOR in `CrossWorkspaceIdorTest` |

---

## 5. Finance / Commerce catalog (`products`)

| Field | Detail |
| --- | --- |
| Purpose | ERP products, inventory, invoices, GL (per master spec) |
| Status in **this tree** | **Schema/docs only** — migrations include `products`, `finance_invoices`, etc.; **no** `Product` model or `app/Services/Finance/` |
| Routes | **Not in tree** (`finance-api.php` missing) |
| Flutter | **Not in tree** (`apps/hasim_finance` missing) |
| Tests | Many `tests/Feature/Feature/Finance/*` reference finance — **may not load on this checkout** |
| Dependencies (documented) | InvoiceService, AccountingService, TaxService |

**Evidence:** `database/migrations/2026_08_18_151003_create_products_table.php`; absent `app/Models/Product.php`.

---

## 6. Cashier / POS catalog (`pos_menu_items`)

| Field | Detail |
| --- | --- |
| Purpose | POS menu, tables, sync, cashier API |
| Status | **Schema/docs only** in this tree (migrations `pos_menu_items`, `pos_cashier_invoices`; no POS controllers found in quick inventory) |
| Flutter | **Not in tree** |
| Docs | `docs/cashier-api.md`, `docs/hasim-cashier-status.md` |

---

## 7. Chat catalog (`chat_catalog_items`)

| Field | Detail |
| --- | --- |
| Purpose | Sellable items for chat AI + mobile catalog + commerce carts |
| Status | **Implemented (code)** |
| Models | `ChatCatalogItem`, variants, media, digital pools/units, templates |
| Controllers | `ChatCatalogController`, mobile `ChatCatalogController`, settings |
| Services | `ChatCatalogRetrievalService`, chat catalog CRUD services |
| AI | `SearchProductsTool`, `GetProductIntelligenceTool` use chat catalog IDs |
| Tests | `ChatCatalogProductTemplateTest`, `ArabicCatalogSearchTest`, `DomainCatalogSeparationTest` (referenced in docs) |
| Web UI | `resources/views/workspace/chat-catalog/*` |

**Evidence:** `app/Models/ChatCatalogItem.php`; `tests/Unit/Chat/ArabicCatalogSearchTest.php`.

---

## 8. Commerce order engine (chat checkout path)

| Field | Detail |
| --- | --- |
| Purpose | Carts → validated orders on `orders`/`order_items` with chat catalog line snapshots |
| Status | **Implemented (code)**; payment execution for commerce **Partial/stub** |
| Services | `CommerceOrderService`, `CommerceCartService`, `CommercePricingService`, inventory reservations |
| Mobile | `CommerceCartController`, `CommerceIntelligenceController` in `app/Http/Controllers/Api/Mobile/V1/` |
| Payment block | `PaymentService::createPaymentLink` rejects `source === 'commerce'` |

**Evidence:** `app/Services/Commerce/CommerceOrderService.php` lines 25–30, 61+; `app/Services/Payment/PaymentService.php` lines 31–33.

---

## 9. Orders, payments (merchant / legacy)

| Field | Detail |
| --- | --- |
| Purpose | Workspace orders, payment links, webhooks |
| Status | **Partial** — models + `PaymentService` present; full POS/finance order flows not verified here |
| Models | `Order`, `OrderItem`, `Payment`, `PaymentGateway` |
| Jobs | `CreatePaymentLink`, `ProcessPaymentWebhook` |
| Webhooks | CSRF exceptions in `bootstrap/app.php` lines 45–49 |

---

## 10. Subscriptions & SaaS billing

| Field | Detail |
| --- | --- |
| Purpose | Plans, subscriptions, checkout sessions, renewal/dunning schedules |
| Status | **Implemented (code)** (models + scheduled commands) |
| Scheduler | `routes/console.php` lines 13–37 |
| Platform | `routes/platform.php` plans/subscriptions CRUD |
| Tests | `BillingLifecycleTest` (referenced in docs) |

---

## 11. Unified inbox & messaging

| Field | Detail |
| --- | --- |
| Purpose | Omnichannel conversations, assignment, handoff, worker boundaries |
| Status | **Implemented (code)** |
| Models | `Conversation`, `Message`, `MessageAttachment`, `ChannelOutboundDelivery` |
| Web | Shared inbox blades under `resources/views/workspace/communication/inbox/` |
| Realtime | Reverb/broadcasting config; `resources/js/inbox-realtime.js` |
| Tests | `OmnichannelInboxTest`, `WorkerInboxTest`, `ConversationHandoffV2Test` |

---

## 12. WhatsApp / Meta / Telegram / TikTok

| Field | Detail |
| --- | --- |
| Purpose | Inbound webhooks → jobs; outbound send |
| Status | **Implemented (code)** for WhatsApp/Meta paths (tests exist); TikTok/Telegram **Partial** (connections + webhooks; capability matrix in docs) |
| Jobs | `ProcessIncomingWhatsAppMessage`, `ProcessIncomingFacebookMessage`, etc. |
| Docs | `docs/integrations/p6-provider-capability-matrix.md` |

---

## 13. Email (connected mailboxes)

| Field | Detail |
| --- | --- |
| Purpose | Per-workspace SMTP/IMAP mailboxes, sync, send, campaigns — **not** the same as Laravel `Mail` for system email |
| Status | **Implemented (code)** |
| Services | `CustomerSmtpMailer`, `SyncEmailInboxJob`, branding, template library |
| Mobile | `EmailController` mobile routes |
| Web | `Workspace\EmailController`, inbox hub views |
| Security scope | P0 audit docs on attachment/recipient scoping |

**Evidence:** `app/Services/Email/CustomerSmtpMailer.php`; `app/Jobs/SyncEmailInboxJob.php`.

---

## 14. Chat / inbox AI

| Field | Detail |
| --- | --- |
| Purpose | Auto-replies, tools, memory extraction, AI employees |
| Status | **Implemented (code)** |
| Components | `AIService`, inbox agent runtime, `AiEmployee` tables, `PlatformAiConfiguration` |
| Jobs | `ProcessAIResponse`, `ExtractInboxAgentMemoryJob` |
| Guardrails | `AiReplyEligibility`, tool security config in `config/ai.php` |
| Finance AI | **Schema/docs only** here (`finance_ai_*` tables; no finance API controllers) |

---

## 15. Appointments & website builder

| Field | Detail |
| --- | --- |
| Purpose | Booking, public websites per workspace |
| Status | **Partial** — `app/Models/Appointment/*`, `Website/*`; web routes exist in `web.php` (not fully traced) |
| Migrations | `appointment_*`, website tables |

---

## 16. Commerce intelligence

| Field | Detail |
| --- | --- |
| Purpose | Rules, attributes, compatibilities, recommendations on chat catalog entities |
| Status | **Implemented (code)** |
| Models | `CommerceIntelligenceProfile`, rules, compatibilities, etc. |
| Web | `Workspace/Commerce/CommerceIntelligenceController` |

---

## 17. Human workforce platform

| Field | Detail |
| --- | --- |
| Purpose | Independent workers, engagements, capabilities, earnings, disputes, payouts |
| Status | **Implemented (code)** |
| Models | `Worker/*` under `app/Models/Worker/` |
| Surfaces | Mobile worker API, worker web inbox, platform admin verification |

---

## 18. E-invoicing / ZATCA foundation

| Field | Detail |
| --- | --- |
| Purpose | EGS, certificates, UBL, crypto stubs (Saudi e-invoicing prep) |
| Status | **Partial** — migrations + extensive `docs/einvoicing/*`; finance API endpoints **not in route tree** |
| Tables | `e_invoice_*`, `egs_*` |

---

## 19. Platform admin & ops

| Field | Detail |
| --- | --- |
| Purpose | Site settings, AI model catalog, merchant/worker verification, ops health |
| Status | **Implemented (code)** |
| Routes | `routes/platform.php` |
| AI config | `PlatformAiConfiguration`, `PlatformAiSetting` model |

---

## 20. Notifications & push

| Field | Detail |
| --- | --- |
| Purpose | Database notifications, FCM push tokens, jobs |
| Status | **Implemented (code)** |
| Models | `DevicePushToken`; job `SendPushNotificationJob` |
| Flutter | Firebase in `apps/hasim/pubspec.yaml` |

---

## Modules documented but not verified in this tree

- Full **general ledger**, **payroll**, **treasury**, **purchase orders** (PHP services missing)
- **POS shifts**, **offline sync API** (cashier routes missing)
- **OCR**, **anomaly ML**, **warehouses/FIFO** — marked **Not started** in `docs/HASEM_IMPLEMENTATION_STATUS.md` lines 46, 76–84

---

## Absent or uncertain (explicit)

| Module | Conclusion |
| --- | --- |
| Finance ERP web UI | **Not in tree** (no finance routes in `web.php`) |
| Finance API | **Not in tree** |
| Cashier API | **Not in tree** |
| `hasim_finance` / `hasim_cashier` apps | **Not in tree** |
| Gmail OAuth as productized inbox | **Unknown** — search `Socialite` + email; IMAP/SMTP on `EmailAccount` confirmed |

**No application files were modified for this inventory.**
