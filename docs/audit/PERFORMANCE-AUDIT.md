# PERFORMANCE-AUDIT

Audit date: 2026-09-13  
No profilers were run. Bundle sizes UNKNOWN.

---

## Top 20 performance problems

| # | Problem | Location / evidence | Severity |
| --- | --- | --- | --- |
| 1 | Queue/cache/session default to **database** | `docs/PRODUCTION.md` | High |
| 2 | God services (invoice/POS) load large graphs | `InvoiceService`, `PosOrderService` | High |
| 3 | Dashboard vs ledger duplicate aggregates | finance dashboard + `LedgerReportService` | High |
| 4 | AI embeds up to 20 products JSON every reply | `AIService` | Medium |
| 5 | Finance search hits many entity tables | `FinanceSearchService` | Medium |
| 6 | Blade N+1 historically; only some bounded | invoice `withSum` repair | Medium |
| 7 | No report caching | status: “Report caching” remaining | Medium |
| 8 | Flutter google_fonts at runtime | all three apps | Medium |
| 9 | Chat image cache + video_player | `apps/hasim` | Medium |
| 10 | Cashier catalog images cached_network_image | cashier pubspec | Medium |
| 11 | Vite/Tailwind not code-split per module | single `npm run build` | Medium |
| 12 | 317 Blade views, multiple fat layouts | finance + communication + POS | Medium |
| 13 | Sync pull/push unbounded UNKNOWN | `PosSyncPullService` | High |
| 14 | Email campaign jobs per recipient | `SendCampaignRecipientJob` | Medium |
| 15 | SQLite DATE_FORMAT workaround | status doc | Low (tests) / High (MySQL drift) |
| 16 | Missing pagination on some web indexes | UNKNOWN all lists | Medium |
| 17 | Copilot SQL on demand | `FinanceCopilotService` | Medium |
| 18 | Integrity command full-table sums | `routes/console.php` | Low |
| 19 | Three Flutter engines if all desktops open | ops | Low |
| 20 | No APM / slow-query log in repo | composer.json | High (ops) |

UNKNOWN — Requires runtime verification: TTFB, PHP-FPM workers, MySQL explain, Flutter jank, Vite bundle KB.

---

## What is already improved

- Invoice payment status refresh uses `withSum` (repair audit + test bound).
- Inventory create form limited 100 products / 200 variants.
- Scheduler `withoutOverlapping`.
- Cashier stress numbers exist in POS readiness docs (CI SQLite, not production MySQL).
