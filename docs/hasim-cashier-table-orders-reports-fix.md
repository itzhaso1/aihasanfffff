# Table order edit/delete + Reports visibility fix

## 1) سبب عدم ظهور التقارير (تشخيص حقيقي)

**التصنيف: E (Permission) + B (Navigation مشروط) + race على الـ provider**

المشكلة لم تكن أن الـ API أو الشاشة غير موجودين.

| عنصر | الحالة قبل الإصلاح |
|------|---------------------|
| Screen `DailyReportsPanel` | موجودة |
| Route/Section `_PosSection.reports` | موجودة |
| API `GET /reports/daily` | موجود ويعيد بيانات حقيقية |
| Navigation | **يُخفي** تبويب التقارير إن `canViewReports(cashierPermissionsProvider) == false` |
| `cashierPermissionsProvider` | يبدأ `{}` فارغًا |
| Auth session permissions | موجودة من login/me لكن **لم تُنسخ** للـ provider قبل اكتمال bootstrap |

الملفات:
- `apps/hasim_cashier/lib/features/home/shell_screen.dart` (شرط `if (canViewReports(...))`)
- `apps/hasim_cashier/lib/core/permissions/permissions_provider.dart` (افتراضي فارغ)
- `apps/hasim_cashier/lib/core/permissions/cashier_permissions.dart`

**النتيجة للمستخدم:** تبويب «التقارير» يختفي من الـ Navigation حتى لو كان لديه صلاحية فعليًا في Laravel.

## 2) ما تم إصلاحه للتقارير

1. Seed فوري لصلاحيات الجلسة داخل `_loadBootstrap` قبل انتظار الشبكة.
2. `CashierPermissions.resolve(bootstrap, session)` كـ fallback.
3. `canViewReports` يقبل أيضًا `orders.manage` (أعضاء elevated مثل الويب).
4. إظهار تبويب **«التقارير»** بوضوح عند توفر الصلاحية.
5. توسيع `GET /reports/daily` بحقول Web-level: open/completed/cancelled، خصم، ضريبة، payment_methods.
6. واجهة تقارير تعرض كل الحقول الحقيقية (لا mock).

## 3) تعديل / حذف الطلب داخل الطاولة

### APIs كانت موجودة
- `POST /orders/{order}/items` (تحديث كميات/حذف أصناف موجودة)
- `POST /orders/{order}/status` مع `cancelled`

### APIs أُضيفت / وُسّعت
- `DELETE /orders/{order}` → حذف حقيقي عبر `PosOrderService::deletePosOrder` (= cancel) مع قواعد الويب
- توسيع `updateOrderItems` + `UpdateTableOrderRequest`:
  - إضافة منتج جديد عبر `pos_menu_item_id`
  - تعديل ملاحظات الطلب
  - خصم الطلب
  - إعادة حساب subtotal/tax/total في Laravel
  - منع التعديل/الحذف إن مدفوع أو مفوتر أو ملغي/مكتمل

### Flutter
- أزرار **تعديل الطلب** / **حذف الطلب** داخل تفاصيل الطاولة
- `TableOrderEditorDialog` يحفظ عبر API الحقيقي
- Confirmation قبل الحذف
- عرض سبب الرفض من Backend (422 message)

### الصلاحيات
- التعديل/الحذف: `orders.manage` (authorizeCashier الافتراضي) — مثل Web
- الخصم داخل المحرر: `orders.discount` / `orders.manage`
- التقارير: `reports.view` أو elevated `orders.manage`

## 4) اختبارات
- `php artisan test --filter=CashierApiV1Test` → 4 passed (يشمل edit+delete+reports)
- `flutter test` → 14 passed
- `flutter analyze` → infos فقط بعد إصلاح خطأ `_taxRate`

## 5) ما زال ناقصًا فعليًا
- طباعة حرارية Native (BLOCKED hardware)
- Realtime WebSocket credentials (Polling يعمل)
- Catalog write من التطبيق (إدارة عبر الويب)
