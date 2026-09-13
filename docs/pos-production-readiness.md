# Hasim Cashier — Production Readiness Audit

تاريخ: 2026-09-02 — **Final Code Freeze Audit**  
النطاق: `apps/hasim_cashier` POS Core. لا Features، لا Licensing، لا إعادة كتابة SyncEngine، **لا ادعاء باختبار Android**.

العمارة المعتمدة:

```
One Cashier App → POS Core → Local SQLite/Drift → Optional Cloud/Sync → Laravel
```

Standalone: لا Laravel، لا Sync، لا Initial Sync، لا Internet.  
Cloud: نفس POS Core؛ الـoutbox اختياري (`connected=true`)؛ البيع يكتمل Offline ثم يُزامَن عند رجوع الشبكة.

---

## 1. BLOCKERS

**0**

لا يوجد blocker برمجي يمنع إكمال Code-Based Hardening والانتقال لاختبار Android الحقيقي.

---

## 2. FIXED (تجميد نهائي)

1. **حساب الضريبة/النسبة داخل المحرك** — `Money.percentOf` أصبح قسمة صحيحة على السنتات (بدون `double` وسيط). اختبار: `tax percent uses integer cents of the rate`.
2. **كلمة مرور النسخة** — تُقلَّم عند التصدير والاستعادة حتى لا تفشل الاستعادة بسبب مسافات الحقل. اختبار: `backup password is trimmed on export and restore`.
3. **Standalone لا يكتب sync_queue** — مثبت: `standalone checkout does not enqueue a sync-queue row`.

قرارات لم تُنفَّذ كـ «إصلاح» لأنها ليست أعطال:

* **PBKDF2 بدل Argon2id** — مقبول. انظر ACCEPTED RISKS.
* **Backup password من المستخدم** — مقبول ومطلوب لاستعادة جهاز بديل. انظر ACCEPTED RISKS.
* **double عند حدود UI/الأوامر** — مقبول بعد تحويل فوري بـ `toCents`. REAL في SQLite للنسب فقط (`tax_rate`, `discount_percent`) وليس للمال.

SyncEngineV2 لم يُمس (لا bug يمنع العمارة).

---

## 3. VERIFIED

`flutter analyze`: 0 errors / 0 warnings (28 info سابقة، خارج POS Core).  
`flutter test`: **176 passed, 0 failed**.

| بند | دليل |
|---|---|
| Checkout ذري + حقن فشل | `checkout faults after each write roll back the sale` |
| Double checkout | `double tap pay is idempotent` |
| Duplicate return | idempotent + `Future.wait` |
| وردية إلزامية للبيع/المرتجع | `ShiftNotOpen` بلا طفرة |
| مخزون + دفتر | sale→return→sale؛ كتالوج عبر StockEngine |
| الفاتورة لا تُغيَّر | المرتجع لا يمس `total_amount` |
| تسلسل | لا `max(id)+1` |
| مال INTEGER cents | 0.1+0.2؛ remainder مرتجع؛ percentOf صحيح |
| FK / ترحيل | v5/v6→v7 cents+FK |
| Backup AES-256-GCM | كلمة مرور خاطئة لا تمسح؛ trim |
| PIN PBKDF2 + salt + ترقية SHA-256 | لا plaintext |
| مسودة | بقاء الملف؛ clear لا يعيد الأسطر |
| صلاحيات / عزل شبكة | كاشير ممنوع؛ `NetworkGuard==0`؛ لا Dio في Core |
| Standalone بدون outbox | `connected` افتراضي false |
| Stress | 10k فواتير / 100k بنود in-memory |
| طابعة | الفشل بعد COMMIT لا يغيّر الفاتورة |

---

## 4. ACCEPTED RISKS

### PBKDF2-HMAC-SHA256 @ 100k بدل Argon2id — **مقبول للإنتاج (قرار تصميم)**

ليست مشكلة يجب إصلاحها الآن.

الـPIN قفل واجهة، لا تشفير للقاعدة. ملف SQLite المسروق يكشف الفواتير والمخزون أصلاً؛ Argon2id لا يغيّر هذا التهديد. التحويل يغيّر صيغة الهاش وصيغة النسخ الاحتياطي ويتطلب قياس أداء على جهاز ضعيف — ذلك إعادة بناء وليست إصلاح صحة. الموجود: ملح لكل مستخدم، مقارنة ثابتة الزمن، عدد الدورات داخل الصيغة، ترقية SHA-256 القديم عند الدخول.

### كلمة مرور النسخة من المستخدم — **مقبولة ومطلوبة (قرار تصميم)**

ليست مشكلة يجب إصلاحها الآن (عدا التقليم أعلاه).

ربط المفتاح بـkeychain/العتاد يمنع الاستعادة على جهاز بديل، وهو سبب وجود النسخة. الحد الأدنى 6 بعد التقليم، AES-256-GCM، checksum قبل الكتابة، كلمة مرور خاطئة لا تمسح البيانات. لا تُخزَّن كلمة المرور في SQLite.

### double عند حدود الأوامر/الـUI — **مقبول بعد التحويل الفوري**

المال في SQLite وفي محرك التسعير INTEGER cents. `PricedLine.unitPrice` و`PaymentTender.amount` و`CheckoutResult.total` حدود عرض/إدخال تُحوَّل بـ `Money.toCents` قبل الجمع. النسب (`tax_rate`) تبقى REAL لأنها ليست مالاً. ما أُصلح في التجمد: `percentOf` لم يعد يمر عبر `fromCents * percent`.

مخاطر مقبولة أخرى (ليست ضمن الثلاث): قائمة فواتير التقرير آخر 100 (المجاميع كاملة)؛ `_safeIndex` قد يتخطى unique قديماً إن وُجدت تكرارات؛ Hive غير محذوف لكن Standalone لا يقرأه؛ format 2 plaintext إن وُجد على قرص قديم.

---

## 5. DEVICE-ONLY TESTS

**لم يُنفَّذ أي اختبار على جهاز Android.** يبقى BLOCKED:

- تثبيت APK / أول تشغيل / أداء PIN (PBKDF2@100k) على عتاد ضعيف
- لمس، سكانر كاميرا، طابعة Bluetooth/USB
- قتل التطبيق من Recents أثناء checkout/return
- نسخة إلى تخزين الجهاز واستعادة بعد إعادة التثبيت
- checklist `docs/pos-production-smoke-test.md`

النتيجة السابقة: `docs/pos-production-smoke-test-result.md` — 48 BLOCKED، 0 PASS.

**NOT READY FOR LICENSING** حتى يمر الدخان على جهاز حقيقي.

إذا BLOCKERS = 0: **Code-Based Hardening مكتمل.** الخطوة التالية اختبار Android فقط.

---

## الحكم التاريخي (M1–M3 / hardening سابق)

أُثبتت بالاختبارات الآلية (ليس على جهاز):

| بند | دليل |
|---|---|
| Checkout atomicity + failure injection | `checkout faults after each write roll back the sale` |
| Duplicate / double checkout | `double tap pay is idempotent` |
| Duplicate return (sequential + parallel) | `pos_code_hardening_test.dart` idempotent + `Future.wait` |
| Return without shift / closed shift | لا إعادة مخزون ولا صف مرتجع |
| Stock consistency sale→return→sale | دفتر حركات + `after = before ± qty` |
| Invoice immutability | المرتجع لا يغيّر `total_amount` / `payloadJson` |
| Sequences | `INSERT … ON CONFLICT`؛ اختبار `never use max(id)+1` |
| Money INTEGER cents | 0.1+0.2، ضريبة بعد خصم، remainder للمرتجع الجزئي = 3003 |
| FK / orphans | `pos_hardening_test.dart` M1 + ترحيل v5/v6→v7 ينظّف orphans |
| Migrations | v5/v6 REAL → INTEGER cents + remap `1→900001` |
| Backup encryption / integrity / restore | format 3 AES-256-GCM؛ checksum؛ كلمة مرور خاطئة لا تمسح |
| PIN / KDF | PBKDF2-HMAC-SHA256 100k؛ لا plaintext |
| Draft crash recovery | مسودة تبقى بعد إعادة فتح الملف؛ `clear` لا يعيد الأسطر |
| Shift rules | بيع بدون وردية مرفوض؛ وردية مفتوحة واحدة |
| Permissions | كاشير لا يستطيع مرتجع / خصم / كتالوج / backup |
| Standalone network isolation | `NetworkGuard.attempts == 0`؛ Hive migration no-op |
| Large catalog / invoices / items | 2k منتجات، 10k فواتير، 100k بنود |
| Report correctness | `net_sales`، COGS بعد المرتجع، top items صافية |
| Printer failure | فشل الطباعة بعد COMMIT لا يغيّر الفاتورة |
| App restart recovery (SQLite file) | مسودة على ملف ثم إعادة فتح — ليس عملية Android |

---

## FIXED (هذا الفرع)

1. **مرتجع مكرر** — `clientReference` + `return_idemp:*` في نفس المعاملة؛ UI: in-flight + UUID.
2. **مرتجع بدون وردية مفتوحة** — يُرفض قبل أي حركة مخزون/نقد. وردية مغلقة كذلك.
3. **مخزون الكتالوج** — الافتتاح والتسوية عبر `StockEngine` داخل معاملة (دفتر حركات).
4. **دفعتان نقديتان** — `client_reference = ref:method:index` + unique index على المدفوعات (v8).
5. **Checkout بدون متجر** — `StoreNotFound`؛ لا fallback إلى `local-store`.
6. **serverId كهوية محلية** — السلة/الباركود/تعديل الصنف يرفضون `local_id` فارغ/`null`.
7. **مسودة القنوات** — التبديل يحمّل مسودة القناة الهدف بدل الكتابة فوقها.
8. **persist fire-and-forget** — طابور كتابات متسلسل؛ `clear()` على نفس الطابور حتى لا تعود الأسطر.
9. **مرتجع جزئي** — آخر كمية تأخذ المتبقي بالسنت حتى لا يضيع فلس.
10. **تقارير** — COGS يخصم تكلفة المرتجع؛ top items كمية/إيراد صافيان.
11. **StockEngine** — تسوية إلى كمية 0 مسموحة.

لم يُمس: SyncEngineV2، Laravel sync، Licensing.

---

## REMAINING DEVICE-ONLY VALIDATION

**لم يُنفَّذ أي اختبار على جهاز Android حقيقي في هذه المهمة.** القائمة التالية تبقى BLOCKED حتى يتوفر جهاز:

- تثبيت APK / أول تشغيل / أداء بارد على عتاد ضعيف
- لوحة PIN، لوحة لمس، سكانر كاميرا، طابعة Bluetooth/USB
- قتل التطبيق من Android recents أثناء checkout/return ثم التحقق بصرياً
- نسخ احتياطي إلى تخزين الجهاز واستعادة بعد إعادة التثبيت
- Permissive SELinux / صلاحيات تخزين / لوحة مفاتيح أجهزة
- checklist التشغيلي 37+ خطوة في `docs/pos-production-smoke-test.md`

النتيجة السابقة للجهاز: `docs/pos-production-smoke-test-result.md` — 48 BLOCKED، 0 PASS.

---

## RISKS

1. PIN = PBKDF2@100k وليس Argon2id؛ الحد الأدنى 4 أرقام.
2. Backup format 3 مشفّر بكلمة مرور المستخدم (ليست keychain). format 2 القديم plaintext إن وُجد.
3. أوامر التسعير/الدفع تدخل `double` ثم `toCents`.
4. قائمة فواتير التقرير اليومي محدودة بـ100 صف (المجاميع كاملة).
5. `_safeIndex` يبتلع فشل إنشاء unique index إن وُجدت تكرارات قديمة — أفضل جهد عند الترقية.
6. Hive لا يُحذف؛ Standalone لا يقرأه في المسار اليومي.
7. Stress 10k/100k in-memory فقط — ليس جهاز تجاري.
8. مسارات الطاولات/QR المتصلة ما زالت API-first (خارج Standalone Core).
9. Connect إلى Laravel غير منفّذ. SyncEngineV2 لم يُمس (مقصود).
10. لا كاميرا باركود، لا BT/USB printer plugin، لا Licensing.

---

## بوابة M1–M3 (2026-09-02، تاريخية)

| بند | الحكم | دليل |
|---|---|---|
| Foreign Keys | **PASS** | Drift `.references()` + orphan tests في `pos_hardening_test.dart` |
| Money INTEGER cents | **PASS** | أعمدة `IntColumn` + ترحيل `ROUND(col*100)` + اختبارات 0.1+0.2/ضريبة/خصم/تقسيم/باقي |
| Migration safety v5/v6→v7 | **PASS** | `legacy_sqlite.dart` يبني ملفات قديمة؛ الفواتير/الأصناف/المخزون/المسودات/التسلسل تُحفظ |
| PIN KDF | **PASS** (بتحفظ) | PBKDF2-HMAC-SHA256 100k + ترقية SHA-256 القديم عند الدخول. ليس Argon2id |
| Encrypted backup | **PASS** | format 3 AES-256-GCM |
| Backup integrity | **PASS** | SHA-256 للـplaintext؛ رفض التالف |
| Restore safety | **PASS** | التحقق قبل DELETE؛ كلمة مرور خاطئة لا تمسح |
| 10k invoices | **PASS** | `pos_scale_stress_test.dart` |
| 100k items | **PASS** | نفس الاختبار؛ reports 55ms بعد تجميع SQL |
| Smoke checklist | **PASS كوثيقة / FAIL كتنفيذ جهاز** | انظر `docs/pos-production-smoke-test-result.md` — 48 BLOCKED، **NOT READY FOR LICENSING** |
| Standalone network isolation | **PASS** | `NetworkGuard` + اختبار عدم استيراد Dio في وحدات POS |

`flutter analyze`: 0 errors (28 info سابقة). `flutter test`: **159 passed, 0 failed** (كان 141).

Stress (SQLite in-memory على CI): catalog 1ms، search 0ms، barcode 2ms، checkout 17ms، invoice list 0ms، reports 55ms، stock 27ms، backup 9073ms، restore 7649ms.

---

## Before → After

| المنطقة | قبل التدقيق | بعد الإصلاح |
|---|---|---|
| هوية Standalone | `workspaceId = 1` يتصادم مع Laravel WS 1 | نطاق محجوز `900001` + هوية المتجر UUID. ترحيل v6 يحوّل `1 → 900001` فقط لمتجر standalone غير متصل وغير مُزامَن |
| المال | `double` + REAL في SQLite | **INTEGER cents** في Drift v7. الحساب داخل المحرك بالسنت. الحدود UI/API ما زالت major units |
| Checkout | وردية اختيارية في الخدمة؛ مسودة تُمسح خارج المعاملة | وردية مفتوحة إلزامية. مسودة تُمسح داخل نفس المعاملة. حقن فشل بعد كل نقطة يُراجع البيع بالكامل |
| الصلاحيات | إخفاء أزرار فقط | `PosPermissions` في Checkout/Return/Shift/Catalog/Backup/Stock adjust |
| جلسة Standalone | استعادة `standalone:` بدون PIN | Cold start يطلب PIN. Logout لا يستدعي Laravel |
| مرتجع UI | `order['id'] as num` يُجهض UUID | `local_id` أولاً. لا يغيّر إجمالي الفاتورة الأصلية |
| Isolation | شاشات كانت تستدعي Dio ثم تُرفض | Standalone يتخطى HTTP. `NetworkGuard` + اختبار عزل |
| Backup | format v2 plaintext + checksum | format **3** AES-256-GCM + checksum؛ format 2 ما زال يُستعاد |
| التقارير | تحميل كل الجداول ثم فلترة في Dart | نطاق تاريخ في SQL + تجميع بنود دفعة واحدة + `net_sales = invoices − returns` |
| التسلسل | read-modify-write | `INSERT … ON CONFLICT UPDATE` ذري |
| الفهارس | ناقصة للباركود/التواريخ | فهارس v6 + unique `client_reference` + وردية مفتوحة واحدة |

---

## Tests

- `apps/hasim_cashier/test/pos_local_core_test.dart` يغطي: تسعير السنتات، بيع ذري، double-tap، مخزون، مرتجع، مسودة، تسلسل، PIN، تقارير، backup، وردية، باركود، هوية 900001، عزل الشبكة، فشل جزئي للبيع، صلاحيات الكاشير، عدم تغيير الفاتورة، sale→return→sale، مخزون سالب، remap.
- التحقق بعد M1–M3: `flutter analyze` = 0 errors (28 info سابقة)، `flutter test` = **159 passed, 0 failed** (قبل التصلب: 141).

---

## BLOCKER / CRITICAL (أُصلحت في هذا الفرع)

### B1 — Standalone identity = Laravel workspace 1

- **Problem:** `PosMode.standaloneWorkspaceId = 1` يجعل صفوف المتجر المستقل تبدو كأنها مساحة Laravel رقم 1.
- **Location:** كان `lib/core/pos/pos_mode.dart`؛ يُكتب في `LocalStores`, `LocalUsers`, `Orders`, `Products`, `Customers`, `Invoices`, `SyncMetadata`, `WorkspaceScope`.
- **Why it matters:** Connect لاحقاً إلى Workspace 1 يصطدم بالمفاتيح والترحيل.
- **Reproduction:** إعداد Standalone ثم فحص `local_stores.workspace_id`.
- **Recommended fix (applied):** `900001` نطاق محجوز. الهوية الحقيقية `LocalStores.localId` (UUID). v6: remap `1 → 900001` فقط إذا وُجد متجر و`connected_mode=0` ولا `initial_sync_completed`. Connect لاحقاً يجب أن يعيد تعيين `workspace_id` من 900001 إلى معرّف Laravel مع الإبقاء على UUID.
- **Risk:** إن وُجد Laravel workspace حقيقي رقمه 900001 (غير محتمل) سيحتاج Connect خطة خاصة.

### C1 — Money as floating point

- **Problem:** `0.1 + 0.2 != 0.3` على `double`.
- **Location:** `pricing_service.dart`, أعمدة `RealColumn`, التقارير، الصندوق.
- **Why it matters:** فروقات هللة في الضريبة/الباقي/الوردية.
- **Reproduction:** جمع أسعار 0.1 و 0.2.
- **Recommended fix (applied):** سنتات صحيحة في المحرك؛ `Money.round` = `fromCents(toCents)`.
- **Risk:** أوامر `PricedLine` / المدفوعات ما زالت `double` عند الحدود ثم `toCents`. نسب الضريبة/الخصم تمر عبر `double` ثم تقريب. الأعمدة INTEGER بعد v7.

### C2 — Permissions UI-only

- **Problem:** الكاشير كان يملك `orders.manage` فيدور المرتجع. الخدمات لا تفحص الصلاحية.
- **Location:** `local_auth_service.permissionsFor`, Checkout/Return/Catalog/Shift/Backup.
- **Why it matters:** إخفاء الزر لا يمنع الاستدعاء.
- **Reproduction:** استدعاء `ReturnService` بصلاحيات كاشير.
- **Recommended fix (applied):** `PosPermissions.require` في طبقة التطبيق. كاشير: بيع + فتح وردية + تقارير فقط.
- **Risk:** أدوار Laravel القديمة ما زالت واسعة عبر `orders.manage` في الوضع المتصل.

### C3 — Standalone session restores without PIN

- **Problem:** `_bootstrap` كان يعيد جلسة `standalone:` مباشرة.
- **Location:** `auth_controller.dart`
- **Why it matters:** أي شخص يفتح الجهاز يدخل كآخر مستخدم.
- **Reproduction:** قتل التطبيق بعد تسجيل PIN ثم إعادة الفتح.
- **Recommended fix (applied):** لا استعادة جلسة Standalone؛ الإبقاء على المتجر وطلب PIN.
- **Risk:** تجربة إضافية في كل فتح — مقصود.

### C4 — Returns UI aborted on UUID

- **Problem:** `_returnOrder` يحوّل `id` إلى `num` قبل مسار `local_id`.
- **Location:** `features/orders/orders_list.dart`
- **Why it matters:** المرتجع المحلي لا يعمل في Standalone.
- **Reproduction:** بيع محلي ثم مرتجع من قائمة الطلبات.
- **Recommended fix (applied):** `local_id` أولاً؛ `allowNegativeStock` من إعداد المتجر؛ صلاحية في الخدمة.
- **Risk:** لا.

### C5 — Sale without shift / multiple open shifts

- **Problem:** `shiftLocalId` اختياري؛ لا unique partial index.
- **Location:** `checkout_service.dart`, `local_shifts`
- **Why it matters:** مبيعات بلا صندوق؛ ورديتان مفتوحتان تفسدان النقد المتوقع.
- **Reproduction:** Checkout بدون وردية؛ `open()` مرتين من جهازين نظرياً.
- **Recommended fix (applied):** الخدمة ترفض بلا وردية مفتوحة. `UNIQUE (workspace_id) WHERE status='open'`.
- **Risk:** SQLite يفرض القيد عند الكتابة المتزامنة.

### C6 — Half-complete sale

- **Problem:** معاملة واحدة موجودة لكن بلا اختبار فشل بعد كل كتابة. مسودة خارج المعاملة.
- **Location:** `checkout_service.dart`, `shell_screen.dart`
- **Why it matters:** بيع مكرر بعد crash أو فاتورة بلا مخزون.
- **Reproduction:** فشل بعد insert الطلب؛ قتل التطبيق بعد الدفع قبل `cart.clear`.
- **Recommended fix (applied):** `CheckoutFaultPoint` + rollback؛ مسودة داخل نفس المعاملة.
- **Risk:** حقن الفشل للاختبار فقط.

### C7 — Network still attempted from UI

- **Problem:** تقارير/فواتير/عملاء/إعدادات/قائمة طعام/حذف صنف/logout كانت تستدعي Dio. المعترض يرفض لكن المحاولة موجودة.
- **Location:** `daily_reports_panel`, `invoices_list`, `customers_panel`, `settings_panel`, `menu_orders_feed`, `admin_placeholders`, `auth_controller`
- **Why it matters:** عزل Standalone غير مثبت؛ أخطاء اتصال مربكة.
- **Reproduction:** فتح التقارير بدون إنترنت في Standalone.
- **Recommended fix (applied):** تخطي HTTP عندما `PosMode.isStandaloneRuntime`. `NetworkGuard` في المعترض. اختبار عزل للمسار الأساسي.
- **Risk:** شاشات الطاولات/QR ما زالت Connected (انظر المتبقي).

---

## HIGH (أُصلح ما يمس البيع/المال/المخزون/البيانات)

### H1 — Incomplete backup / restore

- **Problem:** sequences عامة؛ مسودات تُحذف ولا تُصدَّر؛ لا checksum.
- **Location:** `backup_service.dart`
- **Fix applied:** format 2 + checksum + drafts + sequences حسب `store_id`. v1 ما زال يُستعاد.
- **Risk:** UX ما زال يختار أحدث ملف بالاسم (MEDIUM).

### H2 — Reports load entire tables / ignore returns in net

- **Problem:** N+1 على البنود؛ `grand_total` = إجمالي الفواتير دون طرح المرتجع كصافي.
- **Location:** `reports_service.dart`
- **Fix applied:** فلترة تاريخ SQL؛ بنود دفعة واحدة؛ `gross_sales` vs `net_sales`. الفاتورة الأصلية لا تُعدَّل.
- **Risk:** تقرير المخزون الافتتاحي/المشتريات غير مبني بالكامل (MEDIUM).

### H3 — client_reference not UNIQUE; sequences racy

- **Fix applied:** unique index + upsert ذري للتسلسل.
- **Risk:** أرقام مكررة من backup مستعاد فوق بيانات حيّة إذا تغيّر `store_id`.

### H4 — Hive migration on standalone home

- **Fix applied:** لا تشغيل `HiveLegacyMigration` على نطاق Standalone.
- **Risk:** Hive لم يُحذف (مقصود).

### H5 — Catalog delete API-only

- **Fix applied:** `CatalogAdminService.deleteProduct` (soft-delete) من الواجهة المحلية.

### H6 — Return hardcoded `allowNegativeStock: true`

- **Fix applied:** يحترم إعداد المتجر.

---

## MEDIUM (مغلقة في v7 أو متبقية)

### M1 — Foreign Keys — **أُغلقت في schema v7**

Drift `.references()` مع CASCADE / RESTRICT / SET NULL. لا FK على `deviceId` (الاختبارات والبيع يكتبان `dev-1` بلا صف جهاز).

### M2 — INTEGER cents — **أُغلقت في schema v7**

ترحيل `CAST(ROUND(col*100) AS INTEGER)`. الحدود UI/API ما زالت major units.

### M3 — PIN KDF — **أُغلق جزئياً**

PBKDF2-HMAC-SHA256 @ 100k + ترقية SHA-256 القديم. ليس Argon2id؛ الحد الأدنى ما زال 4 أرقام.

### M4 — طباعة غير متوفرة لا تفشل البيع (تحقق جزئي)

- `shell_screen` يطبع بعد COMMIT. إعادة الطباعة من قائمة الفواتير موجودة.
- Bluetooth/USB غير مكتمل. لا قفل صلاحية على reprint.

### M5 — Restore يختار `backups.first` (الأحدث حسب اسم الملف)

- يعمل إن التسمية صحيحة؛ لا منتقي ملفات عام.

### M6 — `local-offline` ما زال مقبولاً كرمز تراثي

- يجب إيقافه بعد هجرة كل الأجهزة.

### M7 — تبويب المزامنة ظاهر في Standalone

- إرباك تشغيلي فقط.

### M8 — `LocalPermissions` جدول مكتوب ولا يُقرأ

- الصلاحيات من دور `LocalUsers` / خريطة الجلسة.

### M9 — تقرير المخزون = لقطة حالية وليس دفتر (افتتاح + مشتريات + مرتجع − مبيعات ± تسوية)

- الحركات موجودة في `local_stock_movements` لكن تقرير الدفتر غير مبني.

---

## LOW

- طابعات Bluetooth/USB غير منفّذة.
- اختبار ضغط 10k فاتورة / 100k بند موجود في CI (`pos_scale_stress_test.dart`) على SQLite in-memory — ليس جهاز تجاري.
- `table_detail_screen` / `table_add_order_sheet` ما زالا يستدعيان API لمسار الطاولات المتصل.
- SyncEngineV2 لم يُمس (مقصود، خارج M1–M3).
- لا Licensing (مقصود).

---

## Standalone isolation (مسار مثبت بالتحليل الثابت + اختبار)

```
Standalone setup → PIN → Home → Catalog → Cart → Checkout
→ Invoice → Payment → Stock → Return → Shift → Reports → Backup
```

الخدمات: `CheckoutService`, `ReturnService`, `StockEngine`, `ShiftService`, `LocalReportsService`, `BackupService`, `CatalogAdminService`, `DocumentNumberService`, `LocalAuthService` لا تستورد Dio / CashierApiClient / InitialSync / SyncEngine.

الاختبار `standalone core path never increments NetworkGuard` يفشل إن سُجِّلت محاولة HTTP أثناء هذا المسار.

الحد Connected الصحيح:

```
POS Core → (اختياري) Outbox فقط إذا connected=true → SyncEngineV2 → Laravel
```

وليس `POS Core → API` داخل معاملة البيع.

---

## Database integrity

| جدول | PK | ملاحظات |
|---|---|---|
| معظم الكيانات | `localId` نص UUID | `serverId` nullable |
| LocalSettings / SyncMetadata | `(workspaceId, key)` | |
| LocalSequences | `(storeId, kind)` | ليس max(id)+1 |
| SyncQueueItems | autoIncrement | Connected فقط |

قيود v6 المضافة:

- UNIQUE `(workspace_id, client_reference)` على الطلبات
- UNIQUE جزئي لرقم الفاتورة/الطلب
- UNIQUE وردية مفتوحة واحدة لكل نطاق
- فهارس باركود/SKU/`created_at`

FK في v7. سلوك الحذف: soft-delete للأصناف. الفاتورة المدفوعة لا تُحدَّث مالياً عند المرتجع. `deviceId` بلا FK.

---

## Migration strategy (آمنة)

1. **تثبيت جديد:** schema **7** + INTEGER cents + FK + `workspace_id=900001` + `store.localId=UUID`.
2. **تثبيت v5/v6 standalone:** ترقية إلى v7 مع تحويل المال وتنظيف الـorphans ثم remap `1 → 900001` إن انطبقت شروط v6.
3. **تثبيت متصل سبق وزامَن Laravel WS 1:** لا remap. تلك الصفوف تبقى لـ Laravel.
4. **Connect لاحقاً:** لا تُعاد كتابة UUID. تُنقل صفوف `900001` إلى `laravelWorkspaceId` داخل معاملة واحدة بعد تأكيد أن الهدف فارغ أو أن الدمج مُخطَّط. لا تُستخدم القيمة `1` كهوية Standalone أبداً.
5. **هجرة متقطعة:** `CREATE INDEX IF NOT EXISTS` و`addColumn` محميان. remap شرطى وidempotent.
6. **Hive:** لا يُحذف. Daily POS لا يقرأه في Standalone.

---

## Remaining Risks

1. PIN = PBKDF2@100k وليس Argon2id؛ الحد الأدنى 4 أرقام. ملف DB المسروق ما زال عرضة لهجوم تخمين أبطأ لا مستحيلاً.
2. Backup format 3 مشفّر بكلمة مرور المستخدم (ليست keychain/hardware). format 2 القديم plaintext إن وُجد على القرص.
3. أوامر التسعير/الدفع تدخل `double` ثم `toCents`. نسب الضريبة تمر عبر double ثم تقريب.
4. مسارات الطاولات/QR/قائمة الويب ما زالت API-first (خارج Standalone Core).
5. Stress 10k/100k نجح in-memory؛ لم يُقاس على جهاز Android/Windows ضعيف.
6. Checklist الـ26 خطوة لم تُنفَّذ على جهاز حقيقي في هذه المهمة.
7. Connect إلى Laravel غير منفّذ — الخطة موثّقة فقط.
8. تقرير المخزون الدفتري غير مكتمل. قائمة فواتير التقرير اليومي محدودة بـ100 صف (المجاميع كاملة).
9. صلاحيات Connected ما زالت واسعة إن أعطى Laravel `orders.manage`.
10. Hive لا يُحذف. Cold start يذهب إلى `/login` وليس مباشرة إلى `/pin`.
11. لا كاميرا باركود، لا Bluetooth/USB printer plugin، لا Licensing.

Licensing / SyncEngine rewrite / Realtime / كاميرا باركود / طابعات BT-USB ما زالت مؤجلة عن قصد.
