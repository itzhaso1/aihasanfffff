# حاسم — تطبيق Flutter (Mobile API v1 · UX V3)

عميل عربي RTL لمنصة حاسم، يعتمد حصراً على:

```
{API_BASE}/api/mobile/v1
```

لا يعدّل هذا المشروع أي كود Laravel. العقد في `docs/mobile-api.md`، والمعمارية في [`ARCHITECTURE.md`](ARCHITECTURE.md). إعداد Google: [`GOOGLE_SIGNIN.md`](GOOGLE_SIGNIN.md).

## المتطلبات

- Flutter 3.35+ / Dart 3.9+
- جهاز أو محاكي Android / iOS
- خادم Laravel مع مسارات `/api/mobile/v1`

## التشغيل

```bash
cd apps/hasim
flutter pub get
flutter gen-l10n

flutter run --dart-define=API_BASE=http://10.0.2.2:8000
```

| البيئة | `API_BASE` |
|--------|------------|
| Android Emulator | `http://10.0.2.2:8000` |
| iOS Simulator | `http://127.0.0.1:8000` |
| جهاز حقيقي | `http://192.168.x.x:8000` |
| إنتاج | `https://your-domain.com` |

## V3 — قصص + جهات اتصال + حملات

- شريط قصص في الرئيسية + عارض ملء الشاشة + إنشاء نص/صورة/فيديو (`visibility=workspace` افتراضياً)
- دفتر عناوين البريد: قائمة/نموذج/تفاصيل + مفضلة + مجموعات وتعيين أعضاء
- تهيئة البريد: اختيار حساب، رقائق مستلمين، `RecipientPickerSheet` (جهات/مجموعات/الكل)، CC/BCC اختياري
- حملة جماعية عبر `POST /email/campaigns` عند تعدد المستلمين أو المجموعات أو «جميع جهات الاتصال»، مع شاشة حالة واستطلاع دوري
- إضافة سريعة لجهة اتصال من تفاصيل البريد / المحادثة / ملف العميل
- إعدادات: اختصارات «جهات الاتصال» و«مجموعات جهات الاتصال»

## V2 — ما تغيّر

- Splash بعلامة حاسم وانتقال حسب الجلسة
- تسجيل دخول محسّن + نسيت كلمة المرور / إعادة التعيين + Google (يتطلب إعداد عميل)
- سمة فاتح/داكن/تلقائي محفوظة
- رئيسية بتحية زمنية، إحصاءات، إجراءات سريعة، محادثات وحجوزات اليوم
- محادثة: تحميل أقدم، إعادة محاولة، مرفقات send-then-attach، AI اقتراح/تلخيص، كاش Hive
- بريد: حسابات الإرسال، رد، markRead عند الفتح
- إعدادات: ملف شخصي، باقات/استخدام، قنوات، تفضيلات إشعارات، مظهر
- ملف عميل `/customers/:id`
- شارات غير مقروء في الشريط السفلي
- gen-l10n عربي أساسي + إنجليزي stubs

## الاختبارات

```bash
flutter analyze
flutter test
```

## الوحدات

1. Splash / Login / Forgot / Reset / Social  
2. Workspace picker (بحث + أفاتار)  
3. Home V2 + Stories strip (V3)  
4. Conversations + Chat V2  
5. Email compose/detail V2 + campaigns/recipient picker (V3)  
6. Customers / Notifications  
7. Profile / Plans / Channels / Theme / Notification prefs  
8. Customer profile  
9. Contacts / Contact groups (V3)  
10. Story create / viewer (V3)  
