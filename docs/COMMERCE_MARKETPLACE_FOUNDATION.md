# Commerce / Marketplace Foundation

Foundation phase only — no checkout, catalog merge, HyperPay settlement API, or AI commerce tools.

## Workspace vs Seller

| Concept | Role |
| --- | --- |
| **Workspace** | Tenant, security boundary, `WorkspaceContext`, membership, existing Finance/POS/Chat modules |
| **Seller** | Commerce merchant identity **within** a workspace (`commerce_sellers`) |

A workspace may have zero or many sellers (marketplace). A seller **never** replaces workspace isolation.

**Not reused as Seller:** `MerchantProfile` (workspace-level payment **verification/KYC**), `FinanceSupplier` (AP/procurement), `PaymentGateway` (checkout connector config).

## Seller ownership

- Table: `commerce_sellers` — `workspace_id`, `name`, `slug` (unique per workspace), `display_name`, `seller_type`, `status`, `metadata`.
- Types: `workspace_merchant`, `marketplace_seller` (enum only; behavior unchanged in this phase).
- Authorization: `SellerPolicy`, `CommerceSellerOwnershipGuard` — server-side workspace match required.

## Seller payment account

- Table: `commerce_seller_payment_accounts` — links **Seller** to a **provider reference** (e.g. HyperPay beneficiary id when known).
- No secrets on the row; credentials stay in existing secure config/gateway patterns.
- Distinct from `PaymentGateway` (how checkout is initiated) and `MerchantProfile` (workspace onboarding).

## Order ownership preparation

- Nullable `orders.commerce_seller_id` — optional primary seller for single-seller orders.
- Nullable `order_items.commerce_seller_id` — per-line seller for future multi-seller carts.
- **Legacy orders** remain valid with NULL seller columns; `OrderService` unchanged.

## Seller allocation

- Table: `commerce_seller_allocations` — reconciliation record per order/line/seller.
- Amounts: `gross_amount`, `platform_fee_amount`, `seller_net_amount` (computed via `App\Support\Money\Money`).
- **`settlement_status`** (`pending`, `processing`, `settled`, `failed`) is **not** `payments.status` and **not** a wallet balance.

## Payment vs settlement

| Layer | Source of truth | HASEM role |
| --- | --- | --- |
| **Payment** | Verified provider webhook via `PaymentService` | `payments.status`, `orders.payment_status` |
| **Settlement** | HyperPay distribution (future) | Store allocation + `settlement_status` + `provider_settlement_reference` only |

HASEM must **not** mark paid from customer/AI claims. HASEM must **not** transfer seller money internally.

## HyperPay boundary

- HyperPay: external payment + settlement/distribution.
- HASEM: orders, payment state, allocations, fulfillment orchestration (future).
- No settlement/split/onboarding API calls in this phase. `SellerPaymentAccount.provider_reference` is a placeholder for future verified linkage.

## Catalog separation (unchanged)

Three catalogs remain separate (spec):

- Finance `products` / `product_variants` — `OrderService` lines today.
- Chat `chat_catalog_*` — Inbox AI `search_products`.
- POS `pos_menu_items` — optional `product_id` inventory bridge only.

Future commerce SKUs must declare an explicit **seller owner**; no auto-sync in this phase.

## Marketplace mode

- Table: `commerce_workspace_profiles.operating_mode` — `independent_store`, `marketplace`, `hybrid`.
- Resolved by `CommerceWorkspaceProfileService` (default from `config/commerce.php` if no row).
- **Does not** change Finance/POS/Chat behavior yet.

## Security / trust boundary

Seller IDs, payment account IDs, allocation amounts, and settlement status are **trusted domain data**. They must be set only by authorized backend code — not AI tool arguments or customer text. Same principles as `docs/INBOX_AI_TRUST_BOUNDARY.md`.

## Architecture diagram

```text
Workspace (tenant / security)
  |
  +-- CommerceWorkspaceProfile (operating_mode)
  |
  +-- Seller (commerce_sellers)
  |     +-- SellerPaymentAccount (provider + reference)
  |
  +-- [Future] Catalog / SKU → seller owner
  |
  +-- Order ── OrderItem (optional commerce_seller_id per line)
  |     |
  |     +-- Payment (webhook-verified status)
  |     |
  |     +-- SellerAllocation → settlement_status, provider_settlement_reference
  |
  +-- Finance Product / Chat Catalog / POS (unchanged, separate)
```

## Not implemented yet

Checkout UI, AI create-order/payment-link tools, catalog migration/unification, digital/physical/service fulfillment, HyperPay split/settlement API, internal payouts/wallet, inventory changes for marketplace.
