# كاشير حاسم — تقرير إكمال النواقص (UI Gaps)

Branch: `cursor/hasim-cashier-app-757c`  
تاريخ: 2026-09-01  
مرجع Web: `resources/views/workspace/pos/*` + Brand tokens  
**لم يُمس** `apps/hasim` ولا `/api/mobile/v1`.

## Feature | Status | Web | Flutter | API | Notes

| Feature | Status | Web | Flutter | API | Notes |
|---------|--------|-----|---------|-----|-------|
| Cashier 3-col layout | READY | نعم | Shell `_CashierHome` | catalog | Desktop ≥1100 |
| Categories Desktop sidebar | READY | نعم | HsCategoryTile | `/catalog/categories` | |
| Categories Mobile strip | READY | chips أفقية | شريط أفقي touch ≥44px لا Dropdown | cache offline | كان PARTIAL |
| Products grid | READY | نعم | ProductCard + CachedNetworkImage | `/catalog/items` | keepAlive provider |
| Cart panel | READY | نعم | `_CartPanel` | create order | Discount gated by permissions |
| Table board | READY | نعم | `TablesBoard` | `/tables` | |
| Table transfer wizard | READY | Alpine panels | `TableTransferWizard` 3 خطوات | transfer | |
| Table merge wizard | READY | نعم | نفس الـwizard | merge | |
| Split bill wizard | READY | نعم | `SplitBillWizard` إجمالي→أصناف→تأكيد | split | |
| Close table | READY | confirm | `CloseTableWizard` | close | |
| Orders list | READY | running | بحث+فلاتر+منتجات+دفع+حالة | `/orders?status=running` | كان PARTIAL |
| Menu Orders section | READY | menu feed | قسم كامل + badge + صوت + حالات Laravel | `/orders?status=menu` | ليس Toast فقط |
| Menu sound settings | READY | settings | Settings + chip | prefs | |
| Kitchen | READY | kitchen blade | `KitchenBoard` | running orders filter | |
| Invoices list+detail | READY | invoices | تفاصيل+طباعة/إعادة | `/invoices` | payment_method غير مُرجَع من API |
| Invoice tax line | PARTIAL | قد يظهر في ويب | يظهر فقط إن وُجد `tax_amount` | show payload بدون tax صريح | لا Fake |
| Customers | READY | — | `CustomersPanel` | `/customers` | |
| Offline catalog | READY | — | Hive cache items+categories | — | |
| Offline create order | READY | — | Pending queue + idempotency | POST `/orders` | |
| Sync queue UI | READY | — | Pending/Syncing/Synced/Failed+Retry | — | |
| Conflict strategy | READY | — | documented `ConflictStrategy` | server SoT | table ops online-only |
| Realtime architecture | PARTIAL | Echo/channel | `PosEventSource` + Polling fallback | bootstrap channel | لا Fake Pusher |
| Printing ESC/POS arch | PARTIAL | browser print | Builder+Settings+Test+Gateway | — | لا Fake success؛ يحتاج Native SDK |
| Printer Bluetooth/USB/Network | PARTIAL | — | Profile+transport enums | — | Gateway غير موصول بجهاز |
| Permissions UI gating | READY | Spatie | bootstrap permissions | permissionMap | tables/discount/create |
| 401 → Login | READY | session | bootstrap 401 logout | Sanctum | |
| Items admin / Daily reports | PARTIAL | نعم | placeholders | **لا Endpoint** | موثّق |
| Shifts | MISSING | لا في Laravel | — | shifts_supported:false | ممنوع Fake |
| Touch targets | READY | — | chips/qty/nav enlarged | — | |
| Performance catalog | READY | — | غير autoDispose + image cache | — | |

## PRODUCTION BLOCKERS

هذه فقط تمنع إطلاق تشغيلي حقيقي على أجهزة كاشير:

1. **طباعة حرارية فعلية** — المعمارية جاهزة (ESC/POS bytes + إعدادات + Test Print) لكن `UnconfiguredPrinterGateway` يرفض الإرسال عمدًا حتى ربط Native SDK (Bluetooth/USB/Network) لكل منصة. بدون ذلك لا يمكن طباعة فاتورة من الجهاز.
2. **Realtime اختياري للإنتاج الكثيف** — Polling (3ث) يعمل؛ Pusher/Reverb يحتاج credentials/infrastructure. ليس blocker للإطلاق الخفيف، لكنه blocker إذا كان SLA يتطلب تنبيه فوري بدون polling.
3. **اختبار أجهزة حقيقية** — لم يُنفَّذ اختبار Touch على أجهزة Android/iOS/Windows كاشير فعلية في هذه البيئة (analyze/test فقط). يلزم QA ميداني قبل الإطلاق.
4. **إدارة الأصناف / التقارير من التطبيق** — تحتاج API Additive إن أردتم إغلاق لوحة الويب بالكامل من الموبايل؛ ليست blocker إذا بقي الويب للإدارة.

ليست blockers: `flutter analyze` نظيف، أو وجود placeholders للتقارير.

## ما لم يُختلق (No Fake)

- لا نجاح طباعة وهمي
- لا Pusher متصل بدون مفاتيح
- لا payment_method مزيف على الفاتورة
- لا tax_amount إن لم يرجعه API
- لا Shifts
