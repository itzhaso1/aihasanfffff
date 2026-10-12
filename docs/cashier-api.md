# Cashier API v1 — كاشير حاسم

Base: `/api/cashier/v1`

Independent from Hasim Chat Mobile API (`/api/mobile/v1`).

Auth: Sanctum Bearer token  
Workspace: `X-Workspace-Id` header (or token workspace)  
Idempotency: `Idempotency-Key` on mutating routes + `client_reference` on orders

## Auth
- `POST /auth/login`
- `POST /auth/social` (Google/Facebook access token — real Socialite)
- `POST /auth/forgot-password`
- `POST /auth/reset-password`
- `POST /auth/logout`
- `GET /auth/me`

## Bootstrap / Workspace / Plans
- `GET /bootstrap` — app config, permissions, entitlements, pos_enabled, tax_rate
- `GET /workspaces`
- `GET /workspaces/current`
- `POST /workspaces/switch`
- `GET /plan`
- `GET /plans`

## Catalog
- `GET /catalog/categories`
- `GET /catalog/items?q=&barcode=&sku=&category_id=`

## Orders
- `GET /orders?status=running|menu|all|{pos_status}`
- `POST /orders` — wraps `PosOrderService::createPosOrder`
- `GET /orders/{order}`
- `POST /orders/{order}/status`
- `POST /orders/{order}/items`
- `POST /orders/{order}/invoice`
- `POST /orders/{order}/payment-link`
- `POST /orders/{order}/returns`
- `POST /returns/{return}/refund`

## Tables
- `GET /tables`
- `GET /tables/{table}`
- `POST /tables/{table}/sessions/open|…/close|cancel|transfer|merge|split|discount`

## Invoices / Customers
- `GET /invoices`, `GET /invoices/{invoice}`, `PUT /invoices/{invoice}`
- `GET /customers?q=`, `POST /customers`

Business logic is **not** reimplemented — controllers call existing Pos services.
