# POS Local Core Architecture

تاريخ: 2026-09-02  
التطبيق: `apps/hasim_cashier`

## مبدأ التشغيل

```
UI → UseCase / Application Service → Drift SQLite
```

Laravel و SyncEngineV2 طبقة اختيارية بعد نجاح المعاملة المحلية.

```
POS CORE
   |
   +---- Standalone Mode → SQLite فقط
   |
   +---- Connected Mode → نفس POS Core + Outbox → SyncEngineV2 → Laravel
```

لا يدخل `CashierApiClient` داخل معاملة بيع/مرتجع/مخزون/وردية.

## الطبقات

| طبقة | موقع | مسؤولية |
|---|---|---|
| Presentation | `lib/features/**` | شاشات، رسائل عربية، لا حسابات مالية |
| Application | `lib/core/pos/application/**` | Checkout, Stock, Return, Shift, Auth, Backup, Reports, Draft cart |
| Domain | `lib/core/pos/domain/pricing_service.dart` | تقريب half-up لخانتين، خصم، ضريبة بعد الخصم |
| Data | Repositories + Drift | قراءة/كتابة SQLite |
| Optional Sync | `SyncEngineV2` + queue | Connected فقط |

## الهوية والأرقام

- كل كيان تشغيلي يعمل بـ UUID `localId`.
- `serverId` nullable. Standalone لا يحتاجه.
- أرقام الفواتير/الطلبات من `DocumentNumberService` داخل معاملة (`INV-000001`) وليس `max(id)+1`.

## البيع الذري

معاملة SQLite واحدة:

1. تحقق idempotency عبر `clientReference`
2. تسعير مركزي
3. طلب + بنود بلقطات الاسم/السعر/الضريبة
4. فاتورة محلية
5. دفعات (نقد/بطاقة/بنك/آجل/أخرى) + tendered/change
6. حركة مخزون before/after
7. حركة صندوق إن وُجدت وردية
8. Outbox فقط إذا `connected == true`

فشل أي خطوة = ROLLBACK. الطباعة بعد COMMIT وليست شرطاً للبيع.

## الأوضاع

- **Standalone:** توكن `standalone:{userId}`، مستخدم PIN محلي، لا Dio.
- **Connected:** جلسة Laravel كما هي. المزامنة اختيارية ولا تفشل البيع.

`PosSyncCoordinator.allowNetwork` يكون false في Standalone ما لم يُفعَّل `connectedMode` على المتجر.

## المصادقة

- إعداد أول مرة: متجر + مدير + PIN مُملّح (SHA-256).
- أدوار: admin / manager / cashier / kitchen.
- التوكن الثابت `local-offline` لم يعد مسار إنتاج؛ يُقبل فقط كجلسة قديمة أثناء الاستعادة.

## السلة

`DraftCartStore` يكتب كل تغيير في SQLite. عند إعادة التشغيل تُستعاد المسودة حسب القناة: table / takeaway / delivery.

## المطبخ والباركود والطباعة

- المطبخ يقرأ `LocalOrders` من SQLite ويحدّث `pos_status` محلياً.
- الباركود: HID keyboard wedge → بحث محلي → إضافة للسلة.
- الطباعة: `PrinterService` → Network TCP:9100 / Bluetooth / USB. الفشل رسالة واضحة ولا يلغي الفاتورة.
