# Offline-First POS Architecture v2 — Migration Plan

**Branch:** `cursor/offline-first-pos-v2-757c`  
**Base:** `cursor/cashier-ui-ux-fix-757c`  
**Status:** Production offline-first POS + batch Laravel sync

## Architecture

```text
    Flutter UI → Repositories → SQLite (Drift) → SyncEngineV2
                         ↓
              POST /api/cashier/v1/sync/push  (batch, idempotent UUID)
              POST /api/cashier/v1/sync/pull  (cursor delta)
                         ↓
                    Laravel (Source of Truth)
```

Aliases: `POST /api/pos/sync/push` and `POST /api/pos/sync/pull`.

Daily POS is local-first. Network is optional after Initial Sync.

## Sync flow

```text
POS Operation → Save SQLite → Enqueue UUID op → Keep working
Internet → Push batch → ACK → Mark synced → Pull cursor delta → Update SQLite
```

Backoff (never drop the row): 2s, 5s, 15s, 30s, 60s, 120s, 300s.

## Conflict / Source of Truth

| Entity | SoT |
|--------|-----|
| Products / prices / categories / users / settings | Laravel |
| Orders / payments / table sessions / invoices | POS then Laravel |
| Stock | Laravel via movements. Sale stock is applied by `order.created`, not a second `stock.movement`. |

No silent Last-Write-Wins.

## Stock

Local `local_stock_movements` records sale/purchase/return/adjustment/transfer.  
Sale rows are audit-only locally. Laravel deducts inventory when the order is pushed.  
Standalone `stock.movement` ops (purchase/return/adjustment/transfer) are pushed in the batch API.

## Monitoring

Cashier banner always shows Online/Offline, last sync, and pending count.  
Sync panel shows device_id, cursor, pending, failed.
