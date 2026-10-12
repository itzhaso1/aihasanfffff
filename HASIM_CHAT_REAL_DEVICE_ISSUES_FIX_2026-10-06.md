# Hasim Chat — Real Device Issues Fix

**التاريخ:** 2026-10-06  
**النطاق:** إصلاح مشكلة Facebook Messenger ومشكلة ظهور الرسائل للـ Independent Worker داخل Hasim Chat.

## 1. مشكلة Facebook Messenger

### المسار الذي تم تدقيقه

Flutter يستخدم العقد الحالي:

- `POST /api/mobile/v1/channels/facebook_messenger/connect`
- callback Laravel على `GET /workspace/channels/facebook/callback`
- redirect إلى `hasim://channels/oauth`
- عند تعدد الصفحات:
  - `GET /api/mobile/v1/channels/facebook_messenger/pages`
  - `POST /api/mobile/v1/channels/facebook_messenger/pages`
- بعد النجاح:
  - `GET /api/mobile/v1/channels`

### السبب المثبت

بعد اختيار صفحة Facebook، كان `ChannelsController.selectFacebookPage()` ينفذ العملية ويعيد تحميل القنوات، لكن الحالة النهائية لم تكن تصفر `busyChannel` بشكل صريح. لذلك كان زر القناة يستطيع البقاء في حالة التحميل إذا تداخلت نتيجة reload أو callback مع الحالة السابقة.

الربط نفسه وLaravel Facebook contract كانا ناجحين؛ اختبارات Laravel الحالية أثبتت نجاح callback والصفحة الواحدة وتدفق اختيار الصفحة المتعددة. لم يتم تغيير Facebook OAuth أو إنشاء endpoint جديد.

رسالة:

`تعذر تحديث هذا الجزء. حاول مرة أخرى.`

ليست نصًا ثابتًا من Flutter الحالي. إذا ظهرت بعد الإصلاح، فهي من callback/واجهة Meta أو من استجابة البيئة المستضافة، ويجب مراجعة سجل callback في Laravel. إعدادات البيئة المحلية وقت التدقيق كانت تحتوي على App ID وApp Secret وScopes وRedirect URI.

### الإصلاح

- تصفير `busyChannel` صراحة بعد:
  - نجاح اختيار صفحة Facebook.
  - نجاح connect المباشر.
- تصفير حالة pending والصفحات بعد اكتمال التدفق.
- لم يتغير عقد Facebook أو التخزين السري.

## 2. مشكلة رسائل الموظف

### السبب المثبت

منطق Worker Inbox لا يعرض كل محادثات Workspace للعامل. المحادثة يجب أن تحتوي على كلا الحقلين:

- `assigned_user_id`
- `assigned_worker_engagement_id`

والـ Engagement يجب أن يمنح `inbox.messages.read`.

فحص البيانات الحالية أظهر أن الـ Engagement النشط والصلاحيات موجودة، لكن المحادثات الحالية كانت تحمل:

- `assigned_user_id = null`
- `assigned_worker_engagement_id = null`

إذًا منح الصلاحيات وحده لا يعيّن المحادثة للعامل. كما أن Flutter كان يرسل `user_id` فقط من شاشة تعيين المحادثة، ولا يعرض Engagement العامل، رغم أن Laravel Web كان يدعم تعيين `worker_engagement_id`.

### الإصلاح

تم توسيع endpoint الحالي فقط، بدون endpoint جديد:

`POST /api/mobile/v1/conversations/{conversation}/assign`

ليقبل أحد الخيارين:

```json
{"user_id": 9}
```

أو:

```json
{"worker_engagement_id": 22}
```

التعيين يعيد استخدام:

- `AssignConversationRequest`.
- `ConversationHandoffService`.
- نفس التحقق من Workspace والـ Engagement وحالة العامل.
- نفس capability `inbox.messages.read`.

في Flutter:

- شاشة تعيين المحادثة تحمل الـ active Worker Engagements من العقد الحالي.
- تعرض العامل كهدف قابل للتعيين.
- ترسل `worker_engagement_id`.
- بعد التعيين، تظهر المحادثة للعامل من خلال الـ Inbox الموجود نفسه.

لم تتم إضافة Workspace membership للعامل، ولم يتم إنشاء Worker Inbox جديد.

## 3. Local-First وDrift

لم يتغير schema Drift أو قاعدة البيانات. Read model الحالي كان يحتوي أصلًا على:

- `assigned_worker_engagement_id`
- `assigned_participant_type`
- `worker_engagement_id`
- `participant_type`

أضيف اختبار يثبت أن:

- Conversation العامل تحفظ داخل Workspace الصحيح.
- Message العامل تحفظ مع Engagement الصحيح.
- تحديث نفس الرسالة لا ينشئ duplicate.
- Workspace آخر لا يقرأ الصفوف.

## 4. الملفات المعدلة

### Laravel

- `app/Http/Controllers/Api/Mobile/V1/ConversationHandoffController.php`
- `tests/Feature/Mobile/MobileWorkerInboxContractTest.php`

### Flutter

- `apps/hasim/lib/features/settings/providers/channels_controller.dart`
- `apps/hasim/lib/features/conversations/data/conversation_repository.dart`
- `apps/hasim/lib/features/conversations/providers/conversations_controller.dart`
- `apps/hasim/lib/features/conversations/presentation/chat_screen.dart`
- `apps/hasim/lib/features/conversations/presentation/widgets/conversation_handoff_sheets.dart`
- `apps/hasim/test/channels_connection_test.dart`
- `apps/hasim/test/inbox_actions_repository_test.dart`
- `apps/hasim/test/inbox_actions_test.dart`
- `apps/hasim/test/local_first_phase_g_test.dart`

## 5. Regression tests

### Laravel

- Facebook وMobile Channel contract: **21 passed**.
- Worker Inbox + assignment contract: **25 passed، 202 assertions**.
- اختبار جديد يعيّن Conversation إلى Worker Engagement ثم يتأكد أن العامل يرى Conversation والرسالة.

### Flutter

- Facebook page selection ينهي حالة busy ويثبت `connected`: **12 passed** ضمن اختبار القنوات.
- Repository assignment إلى Worker Engagement.
- واجهة تعيين العامل من المحادثة.
- Local Drift worker message scope وdeduplication.
- الاختبارات المركزة الجديدة: ناجحة.

الاختبارات الكاملة:

- `flutter analyze`: **No issues found**.
- Flutter الكامل: **490 passed، 1 failed**.

الفشل المتبقي قديم وغير مرتبط بهذا الإصلاح:

- `inner_chrome_corner_test.dart`: الاختبار يتوقع `4×4` بينما الحجم الفعلي `5×4`.

لا توجد Regression جديدة مرتبطة بـ Facebook أو Worker Inbox.

## 6. إمكانية إعادة الاختبار على الهاتف

نعم، يمكن إعادة الاختبار الآن بعد بناء وتشغيل النسخة الجديدة:

1. Facebook:
   - Connect.
   - إكمال OAuth.
   - اختيار الصفحة عند ظهور أكثر من صفحة.
   - التأكد أن spinner ينتهي وتظهر `متصل`.
   - تنفيذ disconnect ثم reconnect.

2. Worker:
   - طلب تحويل المحادثة.
   - فتح `تعيين المحادثة`.
   - اختيار العامل من قائمة Engagements.
   - إرسال رسالة اختبار من القناة.
   - التأكد أن العامل يراها داخل `/conversations`.

يجب أن يبقى العامل خارج `workspace_users`; الإصلاح يعتمد على Engagement assignment والصلاحيات فقط.
