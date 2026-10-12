# Hasim Chat — تشخيص فشل التشغيل على الهاتف

**Date:** 2026-10-05  
**Scope:** تشخيص فقط. لم يُعدَّل أي ملف في التطبيق، ولم يُغيَّر Gradle أو Drift أو عنوان الـ API.

التطبيق لم يُبنَ ولم يُثبَّت ولم يصل إلى كود Dart. السبب الظاهر هو أن الهاتف لا يقدّم واجهة Android Debug Bridge.

---

## 1. الجهاز والبيئة

الهاتف ظاهر لويندوز كجهاز Samsung:

- الاسم: `SAMSUNG Mobile USB Composite Device`
- المعرّف: `USB\VID_04E8&PID_6863\R83Y405GKMK`
- الواجهة الوحيدة التابعة له: `SAMSUNG Mobile USB Remote NDIS Network Device` (شبكة USB)

لا توجد واجهة ADB أو Android Composite ADB في أجهزة ويندوز الحالية.

Flutter 3.47.2، Dart 3.13.2، Android SDK 36.0.0 في `C:\Android\Sdk`.  
`adb` غير موجود في `PATH`. النسخة المستخدمة من حزمة SDK هي:

`C:\Android\Sdk\platform-tools\adb.exe` — Android Debug Bridge 1.0.41، الإصدار 37.0.1-15733141.

`flutter doctor` يعلّم رخص Android بأنها غير معروفة (`Android license status unknown`). هذا تحذير منفصل. `adb` نفسه يعمل، وقائمة الأجهزة فارغة، لذلك الرخص ليست ما أخفى الهاتف.

`flutter devices` أظهر ثلاثة أهداف فقط:

- Windows (desktop)
- Chrome
- Edge

لا محاكي قيد التشغيل، ولا هاتف.

## 2. الأوامر

```text
flutter --version
flutter devices
flutter doctor -v
C:\Android\Sdk\platform-tools\adb.exe devices -l
flutter devices --device-timeout 15
flutter run -d R83Y405GKMK --no-pub
```

مجلد التشغيل: `apps/hasim`.

## 3. أين توقف التشغيل

توقف **قبل اختيار الجهاز، وقبل build، وقبل التثبيت، وقبل startup**.

`adb devices -l` طبع:

```text
List of devices attached
```

القائمة فارغة. الجهاز ليس `unauthorized` وليس `offline`.

`flutter run -d R83Y405GKMK --no-pub` خرج بالكود 1:

```text
No supported devices found with name or id matching 'R83Y405GKMK'.

The following devices were found:
Windows (desktop) • windows • windows-x64    • Microsoft Windows [Version 10.0.26200.9457]
Chrome (web)      • chrome  • web-javascript • Google Chrome 153.0.8010.53
Edge (web)        • edge    • web-javascript • Microsoft Edge 154.0.4258.53
```

لا يوجد stack trace من Dart أو Gradle أو Logcat، لأن عملية التطبيق لم تبدأ.

## 4. التصنيف

المشكلة من **اتصال الجهاز / وضع USB**، وليست:

- Build أو Gradle
- إعداد Android داخل المشروع
- شبكة أو API
- إقلاع التطبيق
- ترحيل SQLite أو Drift
- إضافة أو صلاحية داخل التطبيق
- انهيار أثناء التشغيل

الدليل: ويندوز يرى هاتف Samsung بالرقم التسلسلي `R83Y405GKMK`، والواجهة المعروضة هي RNDIS فقط. `adb` لا يرى أي جهاز.

## 5. الملف أو الإعداد المرتبط

لا يوجد ملف في مستودع Hasim Chat مرتبط بهذا الفشل. الإعداد المعني على الهاتف: وضع USB وتصحيح USB، لأن واجهة ADB غير معروضة للنظام.

## 6. العلاقة بـ Phase G

تغييرات Phase G لم تُنفَّذ على الهاتف. الفشل حدث قبل أن يختار Flutter جهاز Android. لا يوجد في هذا التشخيص دليل على أن Phase G سبب عدم ظهور الهاتف، ولا دليل على انهيار Drift أو الإقلاع.

## 7. الخطوة التالية المقترحة، بدون تنفيذ

على هاتف Samsung نفسه: تفعيل خيارات المطوّر وتصحيح USB، واختيار وضع USB الذي يعرض واجهة ADB وليس شبكة RNDIS فقط، ثم قبول حوار التفويض على الهاتف.

بعدها يجب أن يظهر الرقم `R83Y405GKMK` في:

```text
C:\Android\Sdk\platform-tools\adb.exe devices -l
flutter devices
```

وعندها فقط يُعاد `flutter run` مع معرّف ذلك الجهاز. لا تُشغَّل نسخة Windows أو Chrome بدل الهاتف.
