# كاشير حاسم — تقرير الحالة

Branch: `cursor/hasim-cashier-app-757c`  
Base: `cursor/final-web-cashier-757c` (يحوي منطق POS؛ الـdiff الخاص بالتطبيق/API فقط)  
Flutter app: `apps/hasim_cashier`  
API: `/api/cashier/v1`  
**لم يُمس** `apps/hasim` (حاسم شات) ولا `/api/mobile/v1`.

## التصنيف

| البند | الحالة | ملاحظات |
|------|--------|---------|
| A. موجود في Laravel | READY | PosOrderService, tables transfer/merge/split, invoices, returns, QR menu, events, feature flags |
| B. APIs معاد استخدامها | READY | MobileAuthService, FeatureAccessService, PosOrderService, PosReturnService, PosOrderStatsService, StorePosOrderRequest |
| C. APIs جديدة | READY | `/api/cashier/v1/*` (additive) — انظر `docs/cashier-api.md` |
| D. Models معاد استخدامها | READY | Order, PosMenuItem, DiningTable, TableSession, PosCashierInvoice, Customer, PosOrderReturn… |
| E. Models جديدة | READY | لا يوجد (مقصود) |
| F. Migrations | READY | لا يوجد migrations جديدة |
| G. Services | READY | Controllers تغلف الخدمات الحالية فقط |
| H. Events | PARTIAL | القناة `workspace.{id}.pos` موثّقة في bootstrap؛ Flutter يستخدم polling الآن |
| I. Offline architecture | PARTIAL | Hive catalog + pending orders + statuses؛ ليس كل عمليات الطاولات offline |
| J. Sync architecture | PARTIAL | SyncEngine للطلبات مع client_reference؛ Retry يدوي/عند الفتح |
| K. Authentication | READY | Login/logout/me/forgot/reset/social عبر Sanctum الحقيقي |
| L. Workspace isolation | READY | X-Workspace-Id + membership middleware |
| M. Permissions | READY | permission map من Spatie/عضوية؛ Laravel مصدر الحقيقة |
| N. Payments | PARTIAL | payment-link حقيقي؛ لا Fake HyperPay؛ لا offline card success |
| O. Printing | PARTIAL | زر «طباعة الفاتورة» ينشئ invoice عبر API؛ طباعة أجهزة لاحقًا |
| P. Realtime | PARTIAL | Polling fallback جاهز؛ Reverb/Pusher لاحقًا بدون إعادة تصميم |
| Q. Tests | PARTIAL | Laravel CashierApiV1Test + Flutter cart tests؛ مزيد من الحالات لاحقًا |
| R. Performance | PARTIAL | pagination catalog، cache محلي، تجنب تحميل chat |
| S. غير منفّذ | NOT READY | Shifts (لا يوجد في Laravel)، Push FCM credentials، طابعات SDK، Google Sign-In UI كامل في Flutter، Split/Transfer UI كاملة في التطبيق (API جاهز) |

## ما لم يُنفَّذ ولماذا

1. **الورديات (Shifts)** — لا Model/API في Laravel؛ ممنوع Fake Shift.
2. **Push Notifications** — لا credentials؛ البنية جاهزة للربط لاحقًا.
3. **Realtime WebSocket في Flutter** — Polling واضح كـfallback حتى تشغيل Reverb.
4. **واجهة Split/Merge/Transfer كاملة** — الـAPI جاهز؛ UI الطاولات الأساسية موجودة، العمليات المتقدمة تُستكمل لاحقًا.
5. **تسجيل Google داخل Flutter UI** — الـAPI الاجتماعي حقيقي؛ يحتاج إعداد Google Sign-In على الجهاز.

## قاعدة عدم الخلط

- حاسم شات: `apps/hasim` + `/api/mobile/v1` — بدون تعديل في هذا الفرع.
- كاشير حاسم: `apps/hasim_cashier` + `/api/cashier/v1` — فقط.
