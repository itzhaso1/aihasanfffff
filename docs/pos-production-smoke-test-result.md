# Hasim Cashier — Real-Device Production Smoke Result

تاريخ التنفيذ: 2026-09-02  
المرجع: `docs/pos-production-smoke-test.md`  
الفرع: `cursor/pos-real-device-smoke-757c`  
بناء التطبيق المستهدف: `apps/hasim_cashier` **1.0.0+1** (Flutter 3.35.2 / Dart 3.9.0)

**لا تُحتسب اختبارات `flutter test` / SQLite in-memory كـ PASS هنا.**

---

## Environment

| حقل | القيمة |
|---|---|
| Device | **غير متوفر** — لا يوجد جهاز Android حقيقي متصل بهذه الجلسة |
| Android version | **غير معروف** — لم يُفتح أي جهاز |
| App version | 1.0.0+1 (من `apps/hasim_cashier/pubspec.yaml`) — **لم يُثبَّت APK** |
| Host that attempted the run | Cloud Agent Linux VM (`Ubuntu 24.04.4 LTS`, kernel `6.12.94+`, `x86_64`) |
| Flutter doctor | Android toolchain **missing** (no Android SDK, no `adb`, Android Studio not installed) |
| `flutter devices` | `Linux (desktop)`, `Chrome (web)` فقط |
| USB / ADB | `adb` غير موجود؛ `lsusb` غير موجود؛ لا `/dev/bus/usb` ظاهر كجهاز Android |
| Self-hosted Cursor workers | 0 متصل |
| Network printer | غير موفَّرة في هذه الجلسة |
| HID barcode scanner | غير موفَّر |

دليل الفحص البيئي (لا يغني عن جهاز):

- `/opt/cursor/artifacts/real_device_probe_environment.txt`
- `/opt/cursor/artifacts/real_device_probe_flutter_doctor.txt`

ما جُرّب ولم يُستخدم بديلاً عن الجهاز الحقيقي:

1. البحث عن `adb` / Android SDK / USB.
2. `flutter devices` — لا Android.
3. `cursor-cloud-list-self-hosted-workers` — قائمة فارغة.
4. لم يُشغَّل emulator ولم يُحتسب Linux/Chrome كجهاز Android.

---

## Results

| | العدد |
|---|---|
| Total tests (37 checklist + 11 operational) | **48** |
| Passed | **0** |
| Failed | **0** |
| Blocked | **48** |

السبب الواحد لكل بند BLOCKED: **لا يوجد جهاز Android حقيقي (ولا toolchain لبناء/تثبيت APK) في بيئة التنفيذ.**

لم يُعلَن PASS لأي بند لأن الاختبارات الآلية السابقة نجحت.

---

## Checklist (37)

لكل صف: الحالة **BLOCKED**، الجهاز **n/a**، Android **n/a**، البناء **1.0.0+1 uninstalled**.

| # | الخطوة | PASS/FAIL/BLOCKED | Exact failure |
|---|---|---|---|
| 1 | Fresh install | BLOCKED | لا APK ولا `adb install`. Android SDK غير مثبت. |
| 2 | Standalone setup | BLOCKED | التطبيق لم يُفتح على جهاز. |
| 3 | Create local admin/user | BLOCKED | لا جلسة UI على جهاز. |
| 4 | Login with PIN | BLOCKED | لا جلسة UI على جهاز. |
| 5 | Logout | BLOCKED | لا جلسة UI على جهاز. |
| 6 | Cold restart → PIN required | BLOCKED | لا يمكن force-stop/إعادة فتح تطبيق غير مثبّت. |
| 7 | Create category | BLOCKED | لا جلسة UI على جهاز. |
| 8 | Create product | BLOCKED | لا جلسة UI على جهاز. |
| 9 | Set stock | BLOCKED | لا جلسة UI على جهاز. |
| 10 | Barcode keyboard scanner | BLOCKED | لا جهاز ولا ماسح HID. |
| 11 | Open shift | BLOCKED | لا جلسة UI على جهاز. |
| 12 | Add products to cart | BLOCKED | لا جلسة UI على جهاز. |
| 13 | Kill app while draft exists | BLOCKED | لا يمكن `am force-stop` بدون ADB/جهاز. |
| 14 | Reopen → draft recovered | BLOCKED | يعتمد على 13. |
| 15 | Discount | BLOCKED | لا جلسة UI على جهاز. |
| 16 | Tax | BLOCKED | لا جلسة UI على جهاز. |
| 17 | Cash payment | BLOCKED | لا جلسة UI على جهاز. |
| 18 | Tendered/change | BLOCKED | لا جلسة UI على جهاز. |
| 19 | Split payment | BLOCKED | لا جلسة UI على جهاز. |
| 20 | Invoice generation | BLOCKED | لا جلسة UI على جهاز. |
| 21 | Print with configured printer | BLOCKED | لا طابعة شبكة ولا جهاز. |
| 22 | Printer unavailable → sale remains completed | BLOCKED | لا طابعة ولا جهاز. |
| 23 | Return | BLOCKED | لا جلسة UI على جهاز. |
| 24 | Verify stock after return | BLOCKED | لا جلسة UI على جهاز. |
| 25 | New sale after return | BLOCKED | لا جلسة UI على جهاز. |
| 26 | Close shift | BLOCKED | لا جلسة UI على جهاز. |
| 27 | Verify expected vs actual cash | BLOCKED | لا جلسة UI على جهاز. |
| 28 | Daily reports | BLOCKED | لا جلسة UI على جهاز. |
| 29 | Payment-method amounts | BLOCKED | لا جلسة UI على جهاز. |
| 30 | Backup | BLOCKED | لا تخزين تطبيق على جهاز. |
| 31 | Restore | BLOCKED | يعتمد على 30. |
| 32 | Corrupted backup rejected | BLOCKED | لا ملف backup على جهاز. |
| 33 | Wrong backup password rejected | BLOCKED | لا ملف backup على جهاز. |
| 34 | Disable internet completely | BLOCKED | لا يمكن تفعيل وضع الطيران على جهاز غير موجود. |
| 35 | Repeat complete sale workflow with zero network | BLOCKED | يعتمد على 34 وجهاز. |
| 36 | Restart app with internet still disabled | BLOCKED | يعتمد على 34 وجهاز. |
| 37 | Verify all local data remains available | BLOCKED | لا بيانات جهاز للتحقق. |

لقطات الجهاز: **لا يوجد** (لم يُعرض UI أصلاً).

---

## Additional real-device operational tests

| # | السيناريو | PASS/FAIL/BLOCKED | Exact failure |
|---|---|---|---|
| A1 | Kill app during checkout | BLOCKED | لا عملية checkout حيّة على جهاز. |
| A2 | Kill app immediately after checkout | BLOCKED | لا عملية checkout حيّة على جهاز. |
| A3 | Kill app while saving draft | BLOCKED | لا مسودة على جهاز. |
| A4 | Disconnect Wi-Fi/mobile data during POS use | BLOCKED | لا راديو جهاز للتحكم به. |
| A5 | Reconnect network | BLOCKED | يعتمد على A4. |
| A6 | Fill database with realistic catalog | BLOCKED | لا تثبيت؛ لم يُملأ كتالوج على جهاز. |
| A7 | Backup then restore | BLOCKED | لا تخزين تطبيق. |
| A8 | Printer disconnected | BLOCKED | لا طابعة موصولة بجهاز. |
| A9 | Printer unreachable | BLOCKED | لا IP طابعة على شبكة الجهاز. |
| A10 | Repeated payment button press | BLOCKED | لا زر دفع على شاشة جهاز. |
| A11 | Repeated return button press | BLOCKED | لا زر مرتجع على شاشة جهاز. |

ملاحظة: اختبارات الوحدة تغطي double-tap checkout ورفض كلمة مرور backup في CI. **ذلك ليس دليلاً على الجهاز** ولم يُحتسب PASS هنا.

---

## Critical findings

### F1 — لا منصة تنفيذ للجهاز الحقيقي (حاجز البوابة)

- **Reproduction:** من Cloud Agent: `flutter devices` → Linux + Chrome فقط. `flutter doctor` → `Unable to locate Android SDK`. لا `adb`. عمال self-hosted = 0.
- **Impact:** لا يمكن إثبات التثبيت، PIN بعد القتل، المسودة بعد Force Stop، الطباعة، وضع الطيران، أو أي مسار لمس زر/شاشة.
- **Severity:** **Blocker** لقرار الترخيص/البيع. لا يُعد عيباً في منطق POS بحد ذاته في هذا التقرير — هو غياب بيئة جهاز.
- **Recommended fix:** تشغيل نفس checklist على جهاز Android حقيقي (ADB ظاهر في `flutter devices`) مع بناء APK من هذا الفرع، وتسجيل لقطة/سجل لكل فشل. إرفاق: موديل الجهاز، إصدار Android، `versionName+versionCode`.

### F2 — طابعة وماسح غير موجودين حتى لو وُجد جهاز لاحقاً في *هذه* الجلسة

- **Reproduction:** لا طابعة شبكة ولا ماسح HID مُرفقين للـVM.
- **Impact:** البنود 10، 21، 22، A8، A9 ستبقى Blocked بدون عتاد.
- **Severity:** High للتشغيل الميداني؛ خارج قدرة هذه الجلسة.
- **Recommended fix:** جهاز مع طابعة شبكة معروفة IP، واختبار IP خاطئ عن قصد للبند 22.

### F3 — مخاطر معروفة من M1–M3 لم تُنفَ اختبارها على جهاز (ليست FAIL جهاز)

هذه **ليست** نتائج FAIL من شاشة جهاز. تبقى قيود منتج موثّقة في `docs/pos-production-readiness.md` ويجب أن تُراقَب أثناء التشغيل الحقيقي:

1. PIN = PBKDF2@100k وليس Argon2id؛ الحد الأدنى 4 أرقام.
2. Backup format 3 مشفّر بكلمة مرور المستخدم (ليست keychain).
3. Cold start يذهب إلى `/login` وليس مباشرة `/pin` (يجب التحقق من البند 6 على الجهاز).
4. أوامر المال تدخل `double` ثم `toCents` عند الحدود.
5. قائمة فواتير التقرير اليومي تعرض آخر 100؛ المجاميع كاملة.
6. لا كاميرا باركود ولا Bluetooth/USB printer plugin (خارج نطاق هذا الفحص أصلاً).

---

## Screenshots / logs

| ملف | ماذا يثبت |
|---|---|
| `real_device_probe_environment.txt` | لا Android في `flutter devices`؛ `adb` مفقود |
| `real_device_probe_flutter_doctor.txt` | Android toolchain ✗ |
| لا screenshots للتطبيق | لم يُثبَّت ولم يُفتح |

أي لقطة UI من محاكي أو Chrome **لن تُقبل** كدليل لهذا التقرير.

---

## Final decision

**NOT READY FOR LICENSING**

لم يمر Production Smoke Test على جهاز Android حقيقي. صفر PASS، 48 BLOCKED.

لا تُفتح بوابة الترخيص حتى يُعاد هذا الملف بعد تشغيل فعلي على جهاز مع PASS للبنود الحرجة على الأقل: تثبيت نظيف، إعداد Standalone، PIN بعد قتل التطبيق، بيع نقدي + باقي، فاتورة، مرتجع+مخزون، إغلاق وردية، تقارير، backup/restore، رفض نسخة تالفة/كلمة مرور خاطئة، ومسار بيع كامل بدون شبكة.
