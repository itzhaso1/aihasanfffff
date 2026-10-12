# Hasim Chat — Company Push Repair

**التاريخ:** 2026-10-06  
**النطاق:** إشعارات Push لحساب الشركة داخل Hasim Chat فقط. Independent Worker لم يُمس. لا AI، لا Local-First architecture، لا POS/Finance.

Checkpoint: Independent Worker خارج `workspace_users`. Backend يبقى مصدر Authorization. Flutter يسجّل الجهاز فقط عندما يكون الحساب شركة.

---

## سبب المشكلة

التسجيل كان موجودًا (`CompanyDevicePushService` + `POST /api/mobile/v1/devices`)، لكن السلسلة كانت مقطوعة في ثلاث نقاط:

1. Flutter: `pushTokenSourceProvider` كان `UnconfiguredNativePushTokenSource` و`currentToken()` يرجع `null`. لا يُرسل token.
2. Laravel: `SendPushNotificationJob` كان يسجّل تحذير `FCM HTTP client is not implemented` ولا يتصل بـ FCM/APNs.
3. لا يوجد dispatch من مسار الرسائل الواردة. الـ job كان يُستدعى من الاختبارات فقط.

وجود صف في `device_push_tokens` لا يعني أن الإشعار يصل للهاتف.

---

## ما كان ناقصًا

| الحلقة | قبل | بعد |
| --- | --- | --- |
| Native SDK | لا `firebase_core` / `firebase_messaging` | أضيفا رسميًا |
| Token source | `UnconfiguredNativePushTokenSource` | `NativeDevicePushTokenSource` + `FirebaseMessagingGateway` |
| Android permission / channel | لا `POST_NOTIFICATIONS` ولا channel | أضيفا |
| Laravel FCM HTTP | غير منفّذ | `FcmPushClient` (HTTP v1 أو legacy key) |
| Inbound dispatch | غير موجود | `CompanyInboxPushDispatcher` من `InboxRealtimePublisher` |
| عزل تبديل المساحة | `revoke` بعد تغيير `X-Workspace-Id` | `revoke` قبل تبديل المساحة |

---

## Flutter changes

- `NativeDevicePushTokenSource` يأخذ token من FCM بعد الإذن. Token فارغ لا يُرسل.
- Independent Worker: `companyPushAllowed` في Prefs. العامل لا يُسجَّل عبر `POST /devices`.
- Web/Desktop: `pushProviderForPlatform()` يرجع `null`.
- Token refresh يعيد التسجيل ويستبدل التسجيل السابق لنفس المساحة.
- Logout يستدعي `revokeCurrent` ثم يمسح prefs (وليس Drift للـ token).
- تبديل المساحة يلغي تسجيل المساحة الحالية **قبل** تغيير `X-Workspace-Id` ثم يسجّل المساحة الجديدة.
- الضغط على الإشعار يستخدم `resolvePushPayloadRoute` الموجود (`conversation_id` / `email_id`). لا نظام تنقّل جديد.
- إذا كان `workspace_id` في الـ payload لمساحة أخرى يملكها المستخدم، يُستخدم `switchWorkspace` الحالي ثم `push` للمسار.

ملفات أساسية:

- `apps/hasim/lib/push/push_token_source.dart`
- `apps/hasim/lib/push/firebase_messaging_gateway.dart`
- `apps/hasim/lib/push/firebase_push_config.dart`
- `apps/hasim/lib/push/company_push_binder.dart`
- `apps/hasim/lib/push/push_service.dart`
- `apps/hasim/lib/features/auth/providers/auth_controller.dart`
- `apps/hasim/lib/main.dart`

---

## Laravel changes

لا endpoint جديد.

- `FcmPushClient`: يرسل FCM. لا يسجّل token أو مفاتيح في الـ logs.
- HTTP v1 إذا وُجد `FCM_CREDENTIALS` أو `FCM_CREDENTIALS_PATH`.
- Legacy `FCM_SERVER_KEY` إذا لم توجد حساب خدمة.
- `NotRegistered` / `UNREGISTERED` → `markInvalid` على صف الجهاز. لا retry أبدي.
- 429 / 5xx → retry حسب سياسة الـ job (3 محاولات، backoff 30/120/300).
- `CompanyInboxPushDispatcher`: رسالة `inbound` فقط، أعضاء `workspace_users` النشطين الذين لهم جهاز في **نفس** `workspace_id`. العامل غير العضو لا يُستهدف. المحادثة المكتومة لا تُرسل.
- Payload: `type=message`, `conversation_id`, `message_id`, `workspace_id` (قيم نصية كما يطلب FCM).

---

## Dependencies / configuration

Flutter (`apps/hasim/pubspec.yaml`):

- `firebase_core: ^3.15.2`
- `firebase_messaging: ^15.2.10`

Android:

- `POST_NOTIFICATIONS`
- قناة `hasim_messages` في `MainActivity`
- لا `google-services.json` في المستودع (خيارات العميل عبر dart-define)

Laravel `.env` (لا تُرفع أسرار):

```
FCM_ENABLED=true
FCM_PROJECT_ID=...
FCM_CREDENTIALS=...          # JSON حساب الخدمة، مفضّل
FCM_CREDENTIALS_PATH=...     # أو مسار ملف خارج git
FCM_SERVER_KEY=...           # legacy فقط إذا لم يتوفر v1
```

Flutter build/run للشركة على Android:

```
flutter run --dart-define=FIREBASE_ANDROID_API_KEY=...
  --dart-define=FIREBASE_ANDROID_APP_ID=...
  --dart-define=FIREBASE_MESSAGING_SENDER_ID=...
  --dart-define=FIREBASE_PROJECT_ID=...
```

هذه قيم عميل Firebase وليست service account. لا تُخزَّن في Drift.

---

## Device registration flow

1. Login شركة → `setCompanyPushAllowed(true)`.
2. `CompanyDevicePushService.initializeAndRegister()`.
3. FCM permission + `getToken()`.
4. `POST /api/mobile/v1/devices` `{ token, provider: fcm, platform }`.
5. Laravel يخزّن الصف بـ `user_id` + `workspace_id` + token.
6. Refresh: revoke القديم ثم تسجيل الجديد.
7. Logout: `DELETE /devices/{id}` (مساحة الجلسة الحالية) + Laravel يلغي الصفوف المرتبطة بـ PAT.

---

## Notification delivery flow

1. رسالة واردة تُحفظ.
2. `InboxRealtimePublisher` يستدعي الـ dispatcher حتى لو فشل البث اللحظي.
3. Job لكل عضو شركة له جهاز في نفس المساحة.
4. Job يتحقق: عضوية نشطة + تفضيل `messages` + tokens غير ملغاة.
5. FCM `notification` + `data`.
6. الخلفية / المغلق: نظام Android يعرض الإشعار.
7. التطبيق المفتوح: التحديث عبر الـ inbox polling الحالي (لا حزمة إشعارات محلية إضافية).

---

## Workspace isolation

- تسجيل الجهاز مقيّد بـ `X-Workspace-Id` وعضوية الشركة.
- نفس FCM string يمكن أن يوجد لـ A و B كصفوف منفصلة.
- Job لـ workspace A لا يقرأ tokens لـ B.
- عضو غير منتمٍ لـ B لا يُرسل إليه من job بـ `workspaceId=B`.
- تبديل المساحة في Flutter يلغي تسجيل A قبل ترويسة B.

---

## Security

- لا credentials داخل Flutter سوى dart-define لخيارات العميل.
- لا token في SQLite/Drift.
- Prefs تحتفظ بـ device id + workspace + token للتسجيل المكرر فقط، وتُمسح عند الخروج.
- Laravel logs: `user_id`, `workspace_id`, `device_count` — بدون token أو private key.
- `DevicePushTokenResource` لا يعيد `token`.

---

## الاختبارات

Laravel (هذه الجلسة):

- `SendPushNotificationJobTest` + dispatcher + inbound realtime + push isolation + job isolation: **47 passed**
- `tests/Feature/Mobile`: **78 passed**

Flutter:

- `flutter analyze`: **No issues found**
- مجموعة كاملة: **518 passed, 1 failed**
- الفشل الوحيد: `inner_chrome_corner_test.dart` (حجم overlay 5×4 مقابل 4×4) — قديم، ليس blocker لهذا الإصلاح

تغطية مضافة: token حقيقي عبر gateway وهمي، رفض العامل، refresh، duplicate، payload `conversation_id`، عزل workspace في فتح الإشعار، إرسال FCM، invalid token، inbound dispatch، كتم، outbound لا يُرسل.

---

## Real Device UAT

**لم يُنفَّذ. لا يُعتبر نجاح توصيل.**

- `adb devices -l`: قائمة فارغة (نفس قيد USB/ADB السابق).
- لا توجد Firebase client dart-defines في بيئة هذه الجلسة.
- لم يُضبط `FCM_ENABLED` + حساب خدمة على السيرفر في هذه الجلسة.

حتى يتم ذلك، التسجيل على هاتف حقيقي سيبقي الحالة: «لا يوجد رمز جهاز حقيقي» أو job يتخطى «FCM provider not configured».

مسار UAT المطلوب بعد وضع الأسرار:

1. `queue:work`
2. Login شركة على Android مع dart-defines
3. التأكد من صف `device_push_tokens`
4. رسالة inbound والق التطبيق في الخلفية ثم مغلقًا
5. الضغط يفتح `/conversations/{id}` إذا كان المستخدم على نفس المساحة أو يملكها

---

## iOS

المشروع فيه مجلد iOS. أُضيف `UIBackgroundModes=remote-notification`.  
**لا يُعتبر iOS مكتملًا:** لا `GoogleService-Info.plist`، لا APNs key في Apple Developer، ولا جهاز للتحقق.

على iOS، `firebase_messaging.getToken()` ما زال **FCM registration token**؛ Flutter يسجّله `provider=fcm` وليس raw APNs.

---

## ما لم يمكن التحقق منه

- وصول إشعار فعلي إلى شريط النظام.
- FCM HTTP v1 ضد مشروع Firebase حي (الاختبارات تستخدم `Http::fake`).
- صلاحيات Android 13 على جهاز حقيقي.
- سلوك التطبيق المغلق تمامًا (killed).

---

## Blockers المتبقية

1. **أسرار Firebase/FCM** على السيرفر والعميل — بدونها لا يوجد توصيل حقيقي.
2. **هاتف + ADB** للتحقق من الخلفية/الإغلاق.
3. **iOS / Apple** إذا لزم الإطلاق على iPhone.
4. Worker push — خارج النطاق عمدًا.

Push **جاهز في الكود** على المسار:

Token (عند توفّر Firebase) → Laravel `/devices` → inbound job → FCM client → الهاتف (بعد الأسرار والجهاز).

لا يُعلن اكتمال التسليم للهاتف قبل UAT أعلاه.
