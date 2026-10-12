# Products, Catalogs, and Commerce — HASEM Discovery Audit

## Executive answer

HASEM is **architecturally designed** as **three independent catalogs** plus optional **POS→Finance inventory mapping**. In **this checkout**, the **Chat catalog and commerce order engine are implemented in PHP**; **Finance `products` and POS `pos_menu_items` are present in migrations and documentation** but **lack corresponding application code** (models/services/routes).

**Evidence:** `docs/audit/DOMAIN-CATALOG-SEPARATION.md`; missing `app/Models/Product.php`; present `ChatCatalogItem` model and services.

---

## 1. Three catalogs (design)

| Catalog | Table(s) | Created/edited via | Consumed by |
| --- | --- | --- | --- |
| Finance / Commerce ERP | `products`, `categories`, `product_variants`, `inventory_movements` | Documented: Finance web + `/api/finance/v1` | Invoices, COGS, inventory accounting |
| Cashier / POS | `pos_menu_items`, `pos_item_categories` | Documented: Cashier API + POS UI | POS orders, electronic menu |
| Chat | `chat_catalog_items` (+ variants, media, digital inventory) | Web `ChatCatalogController`, mobile API, templates | Hasim app, inbox AI retrieval, commerce carts |

**Does creating in one catalog create another?** **No** (explicit product decision).

**Evidence:** `DOMAIN-CATALOG-SEPARATION.md` lines 31–33.

**Optional link:** `pos_menu_items.product_id` → finance product for inventory — **mapping only**.

**Evidence:** migration `2026_08_31_160100_add_product_id_to_pos_menu_items.php`.

---

## 2. Chat catalog — implementation trace

### Creation / edit

- Web: `app/Http/Controllers/Workspace/Chat/ChatCatalogController.php` (+ digital inventory, templates, settings controllers)
- Mobile: `app/Http/Controllers/Api/Mobile/V1/ChatCatalogController.php`, `ChatCatalogSettingsController.php`
- Permissions: Spatie `chat.catalog.view`, `chat.catalog.manage` (per domain separation doc)

### Search / list

- Retrieval for AI: `App\Services\Chat\ChatCatalogRetrievalService::searchForPrompt`
- Tests: `tests/Unit/Chat/ArabicCatalogSearchTest.php`, performance bounded search test

### AI consumption

- Inbox tools (e.g. search products) use chat catalog, **not** `products` table
- **Evidence:** `tests/Unit/AI/Inbox/Tools/SearchProductsToolTest.php` mocks `ChatCatalogRetrievalService`

### Digital products

- Tables: `chat_catalog_digital_inventory_pools`, `chat_catalog_digital_inventory_units`
- Models: `ChatCatalogDigitalInventoryPool`, `ChatCatalogDigitalInventoryUnit`
- Fulfillment path tied to commerce reservations — **Partial** (reservation service exists; delivery automation not fully traced)

---

## 3. Commerce carts and orders (chat checkout)

### Flow

1. Cart CRUD (mobile API + validation services)
2. `CommerceOrderService::validateCartForOrder` → `CommerceCartValidationService`
3. `createDraftFromCart` with idempotency key → persists `Order` + `OrderItem` with chat catalog snapshots
4. Inventory: `CommerceInventoryReservationService`

**Explicit non-use of finance OrderService:**

```25:30:app/Services/Commerce/CommerceOrderService.php
 * Canonical creation service for Chat Catalog Commerce orders.
 *
 * It intentionally does not use the legacy OrderService: that service is a
 * Finance Product + InventoryService flow, while Commerce lines snapshot
 * Chat Catalog identities and reserve their own authoritative inventory.
```

### Payment links & gateways

- `PaymentService::createPaymentLink` **throws** when `$order->source === 'commerce'` with message *Commerce payment execution is not enabled in this phase.*

**Status:** Checkout/order creation **Implemented**; payment execution for commerce **Stub / not enabled**.

**Evidence:** `app/Services/Payment/PaymentService.php` lines 31–33.

### Seller allocations

- Marketplace-style seller splits: `SellerAllocationService`, `commerce_seller_allocations` tables
- **Evidence:** `app/Services/Commerce/SellerAllocationService.php`

---

## 4. Finance catalog & invoices (expected vs tree)

Documented capabilities (`docs/HASEM_IMPLEMENTATION_STATUS.md`):

- Invoices, credit notes, journal posting, tax, treasury, purchase orders
- Money helper: `app/Support/Money/Money.php` (**present**)

Missing in tree:

- `InvoiceService`, `AccountingService`, finance controllers, `Product` model

**Status:** **Schema/docs only** in this audit environment.

**Consequence if finance PHP removed from deploy:** Migrations still create `finance_*` tables; app cannot manage them via UI/API.

---

## 5. POS catalog (expected vs tree)

Documented:

- Offline-first cashier app, `/api/cashier/v1`, sync engine
- Tables: `pos_menu_items`, `pos_sync_operations`, etc.

Missing in tree:

- Cashier routes file, POS controllers (not found in controller inventory)

**Status:** **Schema/docs only** here.

---

## 6. Stock and accounting interactions

| Action | Chat commerce | Finance ERP (documented) |
| --- | --- | --- |
| Stock decrement | `commerce_inventory_reservations` | `inventory_movements` + GL via `InventoryAccountingService` |
| Invoice generation | Not wired from commerce orders in traced code | Finance invoice service |
| COGS | Not verified for chat orders | Documented in implementation status |

**Removing payment links:** Merchant orders using `PaymentService` would lose checkout URL creation; historical `payments` rows remain.

**Disabling gateway:** New checkouts fail; pending payments need manual resolution.

**Deleting chat catalog item:** Risk to active carts/reservations — depends on FK constraints and service validation (**not fully audited**).

---

## 7. Chat AI product delivery

- AI can **retrieve** catalog items and run commerce intelligence tools
- **Product delivery** (digital unit assignment) — logic in commerce/digital inventory services; end-to-end **Unknown (runtime)**
- Commerce orders from chat — **draft creation implemented**; payment **disabled**

---

## 8. Tests anchoring catalog separation

- `DomainCatalogSeparationTest` referenced in docs (finance vs chat vs POS)
- `ChatCatalogRetrievalServiceTest`, inbox tool tests

**Not executed** in this audit.

---

## 9. Change impact preview (catalog-specific)

| Change | Blast radius |
| --- | --- |
| Merge catalogs into one table | Breaks DOMAIN-CATALOG-SEPARATION, AI retrieval, POS sync, finance COGS |
| Enable commerce payments | `PaymentService`, webhooks, merchant eligibility, order status enums |
| Add SKU sync Finance→Chat | New integration service; no auto-sync today |
| Remove `products` table | Finance ERP dead; optional POS mapping broken |

**No code or schema was modified.**
