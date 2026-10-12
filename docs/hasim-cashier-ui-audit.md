# Web Cashier UI → Flutter Cashier Audit

Source of truth: Laravel POS views under `resources/views/workspace/pos/*` + `resources/css/app.css` (`:root` Hasim tokens).

## Design tokens (from web)

| Token | Value |
|-------|-------|
| Brand | `#06C2A4` |
| Brand dark | `#049e86` |
| Brand soft | `#E8FAF6` |
| Surface page | `#F1F5F9` (slate-100) / `--hs-surface #f7fcfb` |
| Ink | `#0F172A` |
| Muted | `#64748B` |
| Danger | `#DC2626` / rose accents |
| Success | emerald-600 `#059669` (CTAs often emerald-600; brand teal for accents) |
| Font | Cairo (400–800) |
| Card radius | `rounded-2xl` ≈ 16 |
| Control radius | `rounded-xl`/`rounded-lg` ≈ 12/8 |
| Border | `#E2E8F0` |
| Shadow | soft `shadow-sm` |

Primary CTA in cashier cart: **emerald-600** filled. Brand teal used for category active + hs-btn-primary elsewhere.

## WEB → FLUTTER matrix (after remake)

| Web screen | Flutter | Status |
|------------|---------|--------|
| POS layout + sticky header | `_TopHeader` | MATCHED |
| Top nav pills | `_TopNav` (الكاشير/الطاولات/Menu/الطلبات) | MATCHED |
| Channel stats strip | `_ChannelStats` | MATCHED |
| Categories sidebar (desktop) | `HsCategoryTile` sidebar | MATCHED |
| Categories chips (mobile) | horizontal chips | MATCHED |
| Products grid + search/SKU | `_ProductsPanel` + `ProductCard` (+ إضافة) | MATCHED |
| Cart panel / ملخص الطلب | `_CartPanel` | MATCHED |
| Order types طاولة/خارجي/توصيل | `_OrderTypeChip` | MATCHED |
| Success modal optional print | `_SuccessOrderDialog` | MATCHED |
| Tables board + details | `TablesBoard` | MATCHED |
| Table actions (open/close/transfer/merge/split) | dialogs + ⋯ menu | MATCHED |
| Running orders | `OrdersList` | MATCHED |
| Menu new-order toast + sound | `MenuOrdersFeed` + `MenuSoundService` | MATCHED |
| Login guest brand | `LoginScreen` | MATCHED |
| Splash | `_Splash` | MATCHED |
| Offline / pending sync | `ConnectionBanner` | MATCHED |
| Kitchen | `KitchenBoard` | MATCHED |
| Invoices | `InvoicesList` | MATCHED |
| Customers | `CustomersPanel` | MATCHED |
| Settings sound | `SettingsPanel` | MATCHED |
| Items admin / daily reports | — | MISSING (admin surfaces) |
| Native ESC/POS printing | success dialog option only | PARTIAL |

## RTL cashier home (web)

DOM order = visual right→left: **Categories | Products | Cart** (`xl:grid-cols-12` → 2|7|3).
