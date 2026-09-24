# Domain catalog separation — implementation close-out

**SUBSCRIPTIONS / PLANS WERE NOT MODIFIED.**

Official architecture:

- ONE PLATFORM / ONE WEBSITE / ONE BACKEND / ONE DATABASE
- THREE DOMAINS / THREE CATALOGS
- Finance Catalog ≠ Cashier Catalog ≠ Chat Catalog
- Electronic Menu ← Cashier Catalog (active `pos_menu_items`) only
- Chat AI ← Chat Catalog + approved business context + AI role + conversation context

## What changed

- Added Chat Catalog tables/models/API/web screens and Hasim Chat (`apps/hasim`) Catalog UI.
- Chat AI no longer loads `products`. It retrieves from `chat_catalog_items` by query tokens.
- `AiSetting.ai_role` plus unused fields (`business_information`, `tone`, `reply_style`, `rules`) now enter the Chat AI prompt (redacted/truncated).
- Spatie `chat.catalog.view` / `chat.catalog.manage` added. Agent lost finance view keys. Receptionist lost `invoices.view` / `receipts.view`.
- `ProductPolicy::create()` is fail-closed. Finance API `POST /api/products` authorizes create.

## What did not change

- Subscriptions, plans, pricing, billing, plan feature gates, entitlements.
- One database / one Laravel monolith / three Flutter apps.
- Finance still consumes `products` (no `finance_products` table).
- Cashier still uses `pos_menu_items`. `product_id` remains optional inventory mapping.
- Finance may still **read** POS invoices for accounting (explicit integration, not catalog sharing).
- Instagram / Messenger remain UI / coming-soon. No fake Graph backends.
- P0-1 … P0-8 and P1-A … P1-D behaviors were not redesigned.

## Remaining UNKNOWN / risks

- Existing workspaces do not receive auto-backfill from Finance/POS into Chat Catalog (intentional).
- `workspace_users.membership_role` enum is still owner/admin/manager/agent/member; receptionist/accountant are Spatie roles.
- Agent still has POS permissions (combined Chat+POS role). Finance leak on agent was removed.
- Chat AI cannot deterministically rewrite a live model hallucination; grounding is retrieval + prompt rules + isolation tests.
