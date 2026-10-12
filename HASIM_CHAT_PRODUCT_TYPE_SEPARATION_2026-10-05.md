# Hasim Chat — Product Type Separation

**Date:** 2026-10-05  
**Scope:** فصل تجربة إنشاء وتعديل المنتج العادي عن المنتج الرقمي داخل Hasim Chat فقط.

لم يتم تعديل Laravel أو إضافة endpoint أو تغيير Drift أو إضافة أي ميزة تجارية جديدة.

## ما تم تغييره

- عند فتح «إضافة منتج» تظهر شاشة «اختر نوع المنتج» أولاً.
- الشاشة تحتوي بطاقتين مستقلتين:
  - «منتج عادي»
  - «منتج رقمي»
- اختيار البطاقة يفتح نموذجًا ثابت النوع، ولا يوجد Dropdown لتغيير النوع داخل النموذج.
- تم تحديث مسار `/catalog/form`:
  - فتحه بلا نوع يعرض شاشة الاختيار.
  - فتحه مع `digital=1` يعرض النموذج الرقمي.
  - تعديل منتج رقمي يستنتج النوع من المنتج ويحافظ عليه.

## المنتج العادي

النموذج يعرض:

- الاسم والوصف والمرجع / SKU.
- صورة المنتج، لأن endpoint الوسائط الحالي يدعمها.
- السعر والعملة.
- الحد الأدنى للعامل.
- سياسة التوفر التقليدية:
  - غير متابع.
  - مخزون محدود.
  - غير محدود.
  - عند الطلب.
- كمية المخزون عند اختيار المخزون المحدود.
- التصنيف.
- المتغيرات.

ولا يعرض سياسات `digital_pool` أو `file_asset` أو أي حقول خاصة بالأكواد والتراخيص.

## المنتج الرقمي

النموذج يعرض:

- الاسم والوصف والمرجع / SKU.
- صورة المنتج.
- السعر والعملة.
- التصنيف.
- سياسة المنتج الرقمي:
  - غير متابع.
  - غير محدود.
  - `digital_pool` — أكواد أو تراخيص.
  - `file_asset` — ملف رقمي.
- تنبيه يوضح أن الأكواد والتراخيص والملفات الرقمية تُدار من «المخزون الرقمي» بعد حفظ المنتج.

ولا يعرض:

- كمية المخزون التقليدي.
- الحد الأدنى للعامل.
- المتغيرات التقليدية.
- حقول اختيار النوع أو الطبيعة.

المخزون الرقمي الحالي، بما فيه Digital Pools وUnits، بقي على شاشة «المخزون الرقمي» والعقود الحالية `/catalog/digital-pools`؛ لم تتم إضافة تدفق أو endpoint جديد.

## النوع والتعديل

يظهر داخل كل نموذج:

- `نوع المنتج: منتج عادي`
- `نوع المنتج: منتج رقمي`

في وضع التعديل النوع للقراءة فقط. إزالة حقلي النوع والطبيعة من النموذج تمنع تحويل المنتج من عادي إلى رقمي أو العكس عبر الواجهة.

## Payload وLaravel

تم استخدام نفس:

- `POST /api/mobile/v1/catalog`
- `PATCH /api/mobile/v1/catalog/{item}`
- endpoint الوسائط الحالي للصور.

Payload المنتج العادي يرسل `classification=physical` وحقول السعر/المخزون/العامل/المتغيرات اللازمة، ولا يرسل حقولًا رقمية.

Payload المنتج الرقمي يرسل:

- `item_kind=product`
- `classification=digital`
- `tangibility=intangible`
- `inventory_policy=digital_pool` أو `file_asset` أو السياسة الرقمية المختارة.

ولا يرسل المنتج الرقمي `stock` أو `worker_min_price` أو `variants`. لم يتغير عقد Laravel.

## الملفات المعدلة في هذه المرحلة

- `apps/hasim/lib/features/catalog/presentation/catalog_product_type_selection_screen.dart` — ملف جديد لشاشة اختيار النوع.
- `apps/hasim/lib/features/catalog/presentation/chat_catalog_settings_screen.dart`
- `apps/hasim/lib/features/catalog/presentation/catalog_form_screen.dart`
- `apps/hasim/lib/features/catalog/data/chat_catalog_repository.dart`
- `apps/hasim/lib/router/app_router.dart`
- `apps/hasim/test/catalog_form_ui_test.dart`
- `apps/hasim/test/chat_catalog_repository_test.dart`

## التحقق

- `flutter analyze`: **No issues found**
- linter للملفات المعدلة: **لا توجد أخطاء**
- اختبارات واجهة فصل النوع: **22 passed**
- اختبارات Payload/catalog repository: **8 passed**
- الاختبارات الكاملة بتزامن واحد: **475 passed, 2 failed**

الفشلان قديمان ولم يتغير سببهما:

- `app_config_test.dart`: fallback الإنتاج يستخدم `http://127.0.0.1:8000` ويفشل تحقق HTTPS.
- `inner_chrome_corner_test.dart`: الحجم الفعلي للـ overlay هو `5×4` بدل المتوقع `4×4`.

التشغيل الكامل المتوازي أظهر أيضًا أخطاء بيئة مؤقتة مرتبطة بـ `AssetManifest.bin` و`ink_sparkle.frag`. إعادة التشغيل بتزامن واحد أعطت النتيجة المستقرة أعلاه.

## Blockers

لا يوجد blocker متعلق بفصل المنتج العادي عن الرقمي. القيود المتبقية هي أن إدارة Digital Pool/Units تتم من الشاشة الحالية بعد حفظ المنتج، وأن شراء/تدفقات التجارة خارج نطاق Hasim Chat ولم تتم إضافتها.
