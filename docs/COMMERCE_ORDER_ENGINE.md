# Commerce Order Engine

## Scope

Commerce Phase 3 creates the transaction foundation for Chat Catalog products. It stops after a valid order has been created and is ready for a future payment phase.

It does not create HyperPay checkouts or payment links, process webhooks, mark orders paid, issue a Finance invoice, deliver digital content, ship goods, run bookings, redesign POS, or add Inbox AI write tools.

```text
Customer
  ↓
Commerce Cart
  ↓
Validation + authoritative pricing
  ↓
Commerce Order
  ├── immutable Order Items
  ├── Seller Allocations
  └── Inventory Reservations
  ↓
Pending payment
```

## Architecture decision

Commerce uses the existing `orders` and `order_items` tables through `CommerceOrderService`. This avoids a second order system while preserving the legacy tables' established foreign keys, order-number sequences, payment links, reports, and POS integration.

Commerce is explicitly identified by:

- `orders.source = commerce`
- `orders.order_type = commerce`
- `orders.commerce_status`, currently `draft`, `pending_payment`, or `cancelled`
- `orders.commerce_cart_id`
- `orders.commerce_channel`
- Chat Catalog references on each `order_items` row

The existing `orders.status` enum remains untouched. A Commerce draft and an order in `pending_payment` both have legacy `status = draft`; payment-ready orders additionally retain `payment_status = pending`. This is deliberate: adding `pending_payment` to the shared legacy enum would risk POS and historical integrations. API consumers use `commerce_status` as the Commerce lifecycle state.

## Inspection findings and reuse

The Phase 3 inspection found these reusable foundations:

- `Order`, `OrderItem`, `WorkspaceOrderNumberGenerator`, workspace scoping, and the shared audit infrastructure.
- `Seller`, `SellerAllocation`, and `CommerceSellerOwnershipGuard`.
- `ChatCatalog`, Chat Catalog item/variant publication state, catalog channel settings, availability service, and digital inventory lifecycle service.
- `Money` for fixed-scale arithmetic.
- Finance `TaxRateResolver` fixed-scale profile lookup for workspace tax-rate lookup only.

These components were extended, not replaced:

- `orders` and `order_items` receive nullable, additive Commerce fields.
- Seller allocation gains fee metadata and line/seller idempotency.
- New cart and reservation tables own transient Commerce state.

These remain legacy-compatible:

- `OrderService` continues to resolve only Finance `Product` and `ProductVariant` references and call `InventoryService`.
- `PaymentService` retains its existing checkout/link/webhook behavior and explicitly refuses Commerce orders in Phase 3.
- Finance invoice bridges remain owned by their existing Finance/POS services.
- `Product`, `ProductVariant`, and `PosMenuItem` are not merged with `ChatCatalogItem`.

## Cart lifecycle

`commerce_carts` has:

- a workspace-scoped optional customer
- an optional seller context, never client-authoritative
- explicit currency and channel
- status: `active`, `converted`, `abandoned`, `expired`, or `cancelled`
- expiry and metadata
- a converted order reference and conversion idempotency key

`commerce_cart_items` only accept a Chat Catalog item identity, optional variant identity, quantity, selected options, and safe metadata. Unit price, currency, seller, and price snapshot are calculated on the server. A cart accepts no client price, workspace, or seller authority.

Cart changes revalidate the complete cart and refresh server-side price snapshots. A converted cart remains immutable.

## Validation

`CommerceCartValidationService` verifies every line against current state:

- cart, customer, item, variant, seller, and catalog all belong to the current workspace
- catalog is published, open, and visible in the cart channel
- item and variant are active, visible, published, and not archived
- variant belongs to the requested item
- seller comes from the item or catalog root and is active in the workspace
- cart seller context cannot conflict with the resolved seller
- quantity is positive, bounded by item/catalog policy when configured, and defaults to a safe maximum of 100
- all lines use the cart's explicit currency
- finite stock accounts for existing active Commerce reservations
- digital pool/file inventory has a valid profile, pool, enough available non-expired units, and existing private assets for file lines
- service-capacity items must actually be service-classified

The validator returns structured availability problems rather than exposing digital values or private paths.

## Pricing

`CommercePricingService` returns an immutable `CommercePriceResolution`. It resolves the current base price, valid sale price, compare-at price, currency, subtotal, tax presentation, tax total, and line total from catalog data.

All Commerce arithmetic uses `App\Support\Money\Money`, fixed two-decimal strings, and fixed-scale tax-rate strings. Commerce checkout does not use binary floating-point arithmetic, and no cart request price is read.

There is no promotion engine in this phase. The only supported adjustment is a current catalog sale price.

Tax behavior is intentionally limited to data the existing catalog can express:

- `tax_inclusive` and `tax_exclusive` use the existing Finance `TaxRateResolver` and Money helpers.
- `tax_hidden_until_checkout` calculates as exclusive tax but is a presentation setting.
- `tax_downstream` has zero Commerce tax; downstream invoicing owns it later.

No FX conversion is performed. A mismatched currency is rejected.

## Order creation and snapshots

`CommerceOrderService::createDraftFromCart()` locks the cart and catalog rows in a database transaction, validates again, creates the shared `Order`, creates line snapshots, allocations, and reservations, then converts the cart into a Commerce `draft`. `createOrder()` promotes that same idempotent draft to `pending_payment`; the mobile conversion API uses `createOrder()`.

Every Commerce line captures immutable commercial facts:

- Chat Catalog item and variant references
- item/variant name, SKU, barcode, customer-facing description
- seller identity and display name
- classification and fulfillment classification
- quantity, price, tax, currency, and selected options
- catalog, pricing, and tax snapshots

Catalog changes after conversion do not change existing lines. `recalculateOrder()` only sums the already-stored immutable line values.

Finance Product and ProductVariant references remain nullable optional bridges. This phase does not fabricate Finance Products or silently synchronize the Chat Catalog into Finance/POS catalogs.

## Seller ownership and allocations

Seller ownership is line-level through `order_items.commerce_seller_id`. The order-level seller is populated only when all lines have one seller; multi-seller orders retain `NULL` at the order header and individual seller IDs per line.

`commerce_seller_allocations` creates one pending allocation for each Commerce order line:

- gross amount
- zero platform fee by default
- explicit `fee_type = none`
- fee metadata recording that no fee configuration exists
- seller net amount
- currency
- `settlement_status = pending`

An allocation is an accounting/reconciliation record only. It is not a wallet, payout, transfer, payment confirmation, or settlement execution. A unique line/seller constraint prevents duplicate allocations, and the ownership guard rejects forged line references or allocations whose seller does not own the referenced Commerce line.

## Inventory reservation

`CommerceInventoryReservationService` provides `reserve`, `release`, and `consume` operations.

For Chat Catalog finite physical stock, it writes a Commerce reservation row and validation subtracts only active, non-expired reservation quantity from catalog stock. Physical and policy reservations inherit the source cart expiry, so an abandoned payment-ready draft does not reserve catalog stock indefinitely. It does not call `InventoryService`, because that service is authoritative only for Finance Products and would incorrectly imply a Chat Catalog = Finance Product mapping.

Unlimited, not-tracked, on-request, and backorder lines receive policy reservations. Service-capacity lines with an explicit stock value receive a capacity reservation; otherwise a policy reservation preserves the booking requirement without pretending that a booking engine exists.

Cancelling a pending Commerce order releases every outstanding reservation. Consumption exists only as a future fulfillment hook and is not invoked in this phase.
Any future payment phase must revalidate or renew an expired reservation before payment capture.

## Digital reservation

Digital reservations use the existing `ChatCatalogDigitalInventoryService`, including its row locks, per-unit lifecycle, expiry checks, reservation keys, and timeout rules. The Commerce reservation stores only unit identity, status, quantity, expiry, and non-sensitive metadata.

No response, audit record, snapshot, or event contains encrypted secrets, fingerprints, credentials, private storage paths, download URLs, or the digital value itself.

Digital delivery remains deferred.

## API

Mobile API routes are workspace-member and feature-gated by both `products` and `orders`.

- `POST /api/mobile/v1/commerce/carts`
- `GET /api/mobile/v1/commerce/carts/{cart}`
- `POST /api/mobile/v1/commerce/carts/{cart}/items`
- `PATCH /api/mobile/v1/commerce/carts/{cart}/items/{cartItem}`
- `DELETE /api/mobile/v1/commerce/carts/{cart}/items/{cartItem}`
- `POST /api/mobile/v1/commerce/carts/{cart}/clear`
- `POST /api/mobile/v1/commerce/carts/{cart}/validate`
- `POST /api/mobile/v1/commerce/carts/{cart}/order-preview`
- `POST /api/mobile/v1/commerce/carts/{cart}/convert`
- `GET /api/mobile/v1/commerce/orders`
- `GET /api/mobile/v1/commerce/orders/{order}`
- `POST /api/mobile/v1/commerce/orders/{order}/cancel`

Conversion requires a domain idempotency key. The existing mobile request idempotency middleware continues to protect HTTP retries independently.

Minimal workspace inspection views are available at:

- `/workspace/commerce/orders`
- `/workspace/commerce/orders/{order}`
- `/workspace/commerce/carts`
- `/workspace/commerce/carts/{cart}`

They show Commerce-only cart, order, allocation, and reservation state without accessing digital secrets.

## Permissions

Commerce uses dedicated workspace permissions:

- `commerce.cart.view`
- `commerce.cart.manage`
- `commerce.orders.view`
- `commerce.orders.manage`
- `commerce.orders.cancel`

`CommerceOrderAccess` requires active membership and one of those permissions, with the established owner/admin/manager elevated-role fallback. Finance permissions are not reused.

## Idempotency and concurrency

Cart conversion is protected by:

- cart row locks
- a unique converted order relation
- a unique workspace conversion idempotency key
- deterministic reservation keys
- unique line/seller allocation records
- catalog/item/variant row locks before finite-stock reservation
- the existing digital inventory row-lock and reservation-key protocol

No lock is held over an external HTTP request because this phase makes none.

## Audit and events

The engine emits internal Commerce events:

- `CommerceCartCreated`
- `CommerceCartUpdated`
- `CommerceOrderValidated`
- `CommerceOrderCreated`
- `CommerceSellerAllocationCreated`
- `CommerceInventoryReservationCreated`
- `CommerceOrderCancelled`

There are no AI, payment, HyperPay, Finance Agent, or delivery listeners.

Semantic audit entries cover cart changes, order creation/recalculation/cancellation, and reservation reserve/release/consume transitions. Audits intentionally record IDs and operational summaries only for digital inventory.

## POS compatibility

POS remains unchanged.

POS semantics continue to be:

- `source = pos` or `qr_menu`
- POS order types: table, takeaway, delivery
- `pos_status`, table sessions, cashier invoices, kitchen updates, offline client references, POS tax adapter, and POS inventory behavior

Commerce does not call `PosOrderService`, does not set a POS invoice, does not alter table/session state, and does not use `pos_menu_item_id`. Existing legacy `OrderService` remains available for Finance Product orders and is not replaced.

## Finance boundary

Commerce reads only the existing Finance tax-rate resolver where catalog tax presentation permits a calculation. It does not invoke Finance AI, Finance Agent tools, invoice issuance, journal posting, or Finance Product synchronization.

Future path:

```text
Commerce order pending payment
  ↓
future confirmed payment event
  ↓
future Finance domain integration
  ↓
Finance invoice / accounting
```

## Payment boundary

Payment is strictly outside this phase:

- no HyperPay checkout or payment link
- no payment provider request
- no payment webhook handling
- no payment confirmation
- no settlement transfer

`PaymentService` explicitly refuses `source = commerce` orders so legacy payment-link endpoints cannot bypass this boundary.

The only next step exposed for a new order is `payment_required`.

## Database changes

Additive migration `2026_09_22_190000_create_commerce_order_engine_tables.php` creates:

- `commerce_carts`
- `commerce_cart_items`
- `commerce_inventory_reservations`

It extends:

- `orders` with Commerce cart/lifecycle/channel fields
- `order_items` with Chat Catalog references and immutable snapshot fields
- `commerce_seller_allocations` with fee type, fee metadata, and idempotent line/seller uniqueness

No existing `orders`, `order_items`, POS, payment, or Finance columns were removed or reinterpreted.

## Performance

Order conversion loads/locks only the cart lines and their concrete catalog rows. It creates seller allocations and reservations in the same transaction. Order reads eager-load sellers, allocations, and reservations to avoid line-level N+1 queries. Digital availability uses count queries and reserves individual units only after validation.

## Tests

Focused Commerce coverage includes:

- server-authoritative price and immutable snapshots
- currency and foreign-seller rejection
- cart conversion idempotency
- multi-seller line ownership and pending allocations
- physical reservation and finite-stock oversell prevention
- digital availability, reservation, release, and secret non-exposure
- mobile API authority, permissions, and safe output
- server-database concurrent same-cart conversion test
- existing Commerce Seller foundation regression

The concurrency test skips SQLite because actual row-lock semantics require a server database.

## Deferred features

- HyperPay and all payments
- settlement/payout execution
- invoices and accounting entries
- shipping and fulfillment
- digital delivery/downloads
- booking/provider execution
- refunds
- promotions and fee-rate configuration
- AI order/payment write tools
- POS redesign
- catalog merging
