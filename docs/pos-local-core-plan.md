# POS Local Core Plan — كاشير حاسم Standalone

تاريخ: 2026-09-02  
المصدر: فحص `apps/hasim_cashier/lib` + `test` + التقارير الهندسية السابقة.  
القاعدة: Local POS هو النظام الأساسي. Sync Adapter اختياري.

## 1. Current architecture

```
UI (Shell / features)  →  Repositories  →  Drift SQLite
                       ↘  CashierApiClient (مباشر من عدة شاشات)
OfflineStore Hive      →  SyncEngine (قديم)  →  POST /orders
SyncQueue (SQLite)     →  SyncEngineV2        →  /sync/push + /sync/pull
```

- State: Riverpod + `setState` يدوي.
- السلة: `CartController` في RAM فقط.
- الدخول المحلي: توكن ثابت `local-offline` + بذر تجريبي.
- Initial Sync شرط لـ `isOfflinePosReady` (أو وجود منتج).
- المطبخ ومنيو QR وإدارة الأصناف تعتمد REST.
- الطباعة: `UnconfiguredPrinterGateway` يفشل دائمًا.
- لا DAO؛ المستودعات تستعلم Drift مباشرة.

## 2. Current database

Drift schema **v4** — ملف `hasim_cashier_pos_v2.sqlite`.

جداول تشغيلية: products, categories, tables (جلسة داخل JSON), customers, orders, order_items, invoices (payload JSON), payments (مبلغ+طريقة فقط), stock_movements (sale محلي بدون before/after كامل), sync_queue, sync_conflicts, sync_metadata.

غير موجود: Store، LocalUsers/PIN، Sessions table، Sequences، Returns، Shifts، Cash movements، Draft cart، tendered/change.

Hive موازٍ: `cashier_catalog` + `cashier_pending_orders`.

## 3. Current business flows

- بيع طاولة/خارجي: UI → OrdersRepository → SQLite + queue → flush اختياري.
- إغلاق طاولة: TablesRepository.closeSessionLocal → فاتورة `LOCAL-{uuid}`.
- الضريبة تُحسب في السلة وتُحفظ 0 على الطلب.
- المخزون: clamp(0) ولا يمنع البيع.
- المرتجع: POST Laravel فقط.
- التقارير: SQLite ناقص ثم يُستبدل بالسيرفر.
- المطبخ: GET `/kitchen/orders`.

## 4. Problems found

1. Laravel/Initial Sync/Hive شروط ضمنية للتشغيل.
2. مصدران للحقيقة (Drift + Hive).
3. سلة RAM تُفقد عند القتل.
4. لا Store/Users/PIN إنتاجي.
5. جلسات JSON وليست جدول.
6. ضريبة 0، لا ledger دفع، لا split/tendered/change.
7. أرقام فواتير غير تسلسلية آمنة.
8. مخزون غير ذرّي وغير سياساتي.
9. لا مرتجع/وردية/صندوق محلي.
10. شاشات تستدعي API داخل مسار البيع.
11. الطباعة فشل دائم؛ لا باركود جهاز؛ لا backup.

## 5. Target architecture

```
Presentation (UI)
    ↓
Application (UseCases / Services)  — لا Dio
    ↓
Domain (Pricing, Money, Errors, Policies)
    ↓
Repositories
    ↓
Drift SQLite  ← مصدر حقيقة POS الوحيد

Optional:
  Local transaction + Outbox
      ↓
  SyncEngineV2 (Connected Mode only)
      ↓
  Laravel
```

أوضاع:

- **Standalone:** لا شبكة، لا Initial Sync، SQLite فقط.
- **Connected:** نفس POS Core + outbox بعد COMMIT.

## 6. Database changes

Schema **v5** — إضافة جداول/أعمدة مع الإبقاء على جداول v4 للهجرة.

جديد:

- `local_stores`, `local_users`, `local_sequences`
- `local_sessions`, `local_draft_carts`, `local_draft_cart_lines`
- `local_returns`, `local_return_items`
- `local_shifts`, `local_cash_movements`

توسيع:

- products: cost, taxRate, trackStock, createdAt, imagePath
- orders: orderNumber, sessionId, customerLocalId, createdBy, completedAt, fulfillment, discountPercent
- order_items: snapshots sku/barcode, taxRate/taxAmount, notes, createdAt
- payments: tendered, changeDue, shiftId
- invoices: orderId, totals كاملة, status, createdBy, localInvoiceNumber
- tables: tableNumber, createdAt — الجلسة تخرج من JSON

Sequences: `INV-000001` / `ORD-000001` داخل معاملة (بدون max(id)+1).

## 7. Migration plan

1. v4 → v5: createTable + addColumn مع defaults.
2. نسخ جلسات من `payloadJson` إلى `local_sessions` إن وُجدت.
3. Hive pending orders → `OrdersRepository.migrateHivePending` ثم وسم `hive_pos_migrated=1`.
4. عدم حذف جداول v4 في هذه النسخة.
5. POS اليومي يقرأ Drift فقط بعد الهجرة.

## 8. Testing strategy

- تسعير/خصم/ضريبة rounding.
- بيع نقد/بطاقة/تقسيم + tendered/change.
- مخزون: سياسة سالب، ذرية، مرتجع.
- مسودة سلة بعد «قتل» (إعادة فتح DB).
- Double-tap دفع.
- Standalone بدون API.
- هجرة Hive.
- Backup/restore integrity.
- اختبارات sync الحالية تبقى Connected-only.

## 9. Files that will be deleted

ليس في هذه المرحلة (بعد التحقق): لا حذف Hive/SyncEngine القديم بعد. يُعزل ويُوقف استخدامه في POS اليومي.

مرشّح لاحقًا: كتابة الكتالوج/الطلبات إلى Hive.

## 10. Files that will be refactored

- `tables.dart` / `app_database.dart`
- `orders_repository.dart`, `tables_repository.dart`, `catalog_repository.dart`
- `cart_controller.dart` + `shell_screen.dart` checkout
- `auth_controller.dart` / `login_screen.dart`
- `kitchen_board.dart`, `daily_reports_panel.dart`, `invoices_list.dart`
- `printer_service.dart`
- `pos_sync_coordinator.dart` (لا يعمل في Standalone)
- `local_demo_seed_service.dart` → بذر اختياري بعد إنشاء المتجر لا توكن وهمي

## 11. Files that will be added

- `lib/core/pos/**` (domain, application, errors, mode)
- شاشات: onboarding, PIN login, payment sheet, shifts, returns, backup
- `docs/pos-local-core-architecture.md`
- `docs/pos-local-core-testing.md`
- اختبارات `test/pos_core_*.dart`
