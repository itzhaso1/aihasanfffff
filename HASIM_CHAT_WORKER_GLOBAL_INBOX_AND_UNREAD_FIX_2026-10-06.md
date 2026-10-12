# Hasim Chat — Worker Global Inbox and Unread Fix

**التاريخ:** 2026-10-06  
**النطاق:** Hasim Chat فقط (Laravel Inbox/Worker authorization + Flutter Local-First unread merge).  
لم يُمس: AI، Push، Facebook، POS، Finance، Cashier، ZATCA، Catalog، Product types.  
لم تُضف صلاحية جديدة. لم يُنشأ نظام authorization جديد. العامل ليس `workspace_users` member ولا Owner.

---

### Worker visibility

#### السلوك القديم

رؤية محادثات Inbox للعامل المستقل كانت مشروطة بالتعيين:

- `assigned_user_id` = المستخدم الحالي
- `assigned_worker_engagement_id` ضمن ارتباطات القراءة النشطة
- المحادثة غير مؤرشفة

النتيجة:

- محادثة `unassigned` / `handoff_status = none` → لا تظهر رغم `inbox.messages.read`
- محادثة مسندة لعامل آخر في نفس الشركة → لا تظهر
- `MobileInboxActor::constrain` و`WorkerInboxService::conversations` و`resolveConversation` كلها كانت تفلتر بالتعيين
- `canReply` / `assertSendable` كانت تفحص صلاحية الإرسال على `assigned_worker_engagement_id`، فالمحادثة غير المسندة لا تُرد عليها حتى مع `inbox.messages.send`

#### السلوك الجديد

**Capability = نطاق الوصول.**  
ارتباط نشط + `inbox.messages.read` → قراءة كل محادثات الشركة غير المؤرشفة داخل workspace ذلك الارتباط.

**Assignment = تنظيم وتوجيه فقط.**  
لا شرط ظهور. لا شرط رد.

| الحالة | `inbox.messages.read` | النتيجة |
| --- | --- | --- |
| Unassigned في الشركة المسموحة | نعم | تظهر |
| مسندة لعامل آخر في نفس الشركة | نعم | تظهر |
| أي محادثة في الشركة المسموحة | لا | لا Inbox (403 على البوابة) |
| محادثة شركة أخرى | نعم على شركته فقط | 403 على workspace الآخر / 404 على المعرف الأجنبي |
| ارتباط متوقف (paused) | حتى لو الصلاحية موجودة | لا وصول |

`inbox.messages.send` مستقل: الرد مسموح على المحادثات التي يملك قراءتها، سواء كانت مسندة له أو لغيره أو غير مسندة. الإرسال يُنسب لأول ارتباط إرسال نشط في نفس الـ workspace.

العامل لا يُضاف إلى `workspace_users`. لا Inbox ثانٍ. نفس `GET /api/mobile/v1/conversations`.

#### capability logic

- `inbox.messages.read` عبر `WorkerInboxService::readEngagementIds` / `workerCanInWorkspace`
- قائمة Inbox: إن وُجد ارتباط قراءة نشط في الـ workspace الحالي → كل المحادثات غير المؤرشفة في ذلك الـ workspace
- إن لم يوجد → `constrain` يضيف `0 = 1`، و`AuthorizeMobileInboxWorkspace` يمنع الدخول (403)
- `inbox.messages.send` عبر `workerCanInWorkspace(SEND)` بعد إثبات القراءة، وليس عبر engagement المسند على الصف

#### authorization/query changes

- `MobileInboxActor::constrain` — بدون `assigned_user_id` / `assigned_worker_engagement_id`
- `MobileInboxActor::workerCanRead` — نفس الـ workspace + ارتباط قراءة + ليست مؤرشفة
- `MobileInboxActor::canReply` / `assertSendable` — `workerCanInWorkspace(SEND)`
- `WorkerInboxService::conversations` / `resolveConversation` — نطاق workspace وليس التعيين
- عزل الشركات: `workspace_id` على الاستعلام + `X-Workspace-Id` + ارتباط القراءة في ذلك الـ workspace فقط. التعيين ليس أداة عزل.

#### assignment behavior

التعيين ما زال يعمل كما هو (`POST /conversations/{id}/assign`، handoff، takeover).  
يغيّر `assigned_user_id` و`assigned_worker_engagement_id` و`handoff_status` للتنظيم.  
لا يخفي المحادثة عن عامل آخر يملك `inbox.messages.read` على نفس الشركة.

---

### Unread

#### السبب الحقيقي

مساران، وليسا Flutter-only:

1. **المصدر الأساسي (عمال مستقلون):**  
   `POST /api/mobile/v1/conversations/{id}/read` كان داخل مجموعة `workspace.member`. العامل ليس عضواً → **403 قبل** `ConversationController::read`.  
   حتى بعد نقل التحقق داخل الـ controller من `authorize('view')` (عضوية فقط) إلى `inboxActor.assertReadable`، المسار نفسه كان مرفوضاً من الـ middleware.  
   Flutter `ChatController.refresh` يستدعي `markRead` ثم يصفّر Drift فقط بعد نجاح الـ API. الفشل يُبتلع: Drift يبقى `unread_count` القديم. العودة للقائمة / background refresh يعيد العدد من السيرفر لأنه لم يُكتب `conversation_user_states`.

2. **دمج Local-First:**  
   حتى بعد نجاح mark-as-read، `_upsertConversation` كان يعتمد مقارنة `last_message_at` مع `local_read_last_message_at`. فروقات ملي ثانية أو `getById` متأخر يحمل `unread_count` قديماً مع نفس آخر رسالة كان يمكن أن يعيد العداد. القائمة تقرأ Drift بعد الـ upsert، لا UI فقط.

#### أين كان العداد يعود

```
قائمة → unread_count > 0
فتح المحادثة → POST /read
  عامل: 403 (workspace.member) → Drift لا يُحدَّث
  عضو شركة: 200 ويُكتب last_read_message_id
العودة للقائمة → GET /conversations
  عامل: السيرفر ما زال يعيد العدد القديم → Drift يعيد عرضه
  عضو: إن نجح السيرفر بـ 0 يبقى 0؛ إن وصلت حمولة قديمة مع timestamp أحدث يُستعاد العدد
```

لم يكن mark-as-read «لا يُرسل». كان يُرسل ويفشل للعمال. الرسائل نفسها لم تُعلَّم في `conversation_user_states`. لم يكن هناك mismatch وهمي في UI فقط.

#### server/local state

- السيرفر: `ConversationInboxService::unreadCountForConversation` يعد inbound حيث `id > last_read_message_id` في `conversation_user_states` لكل `user_id` داخل `workspace_id`.
- بعد mark-as-read الناجح: `last_read_message_id` = آخر رسالة، والقائمة تعيد `unread_count = 0`.
- الـ endpoint يعيد الآن `data.unread_count` من نفس المصدر (ليس صفراً شكلياً).
- العزل: حالة القراءة لكل مستخدم. قراءة عامل أ لا تمسح عداد عامل ب، ولا عبر الشركات.

#### mark-as-read flow

1. المسار نُقل إلى مجموعة `inbox.actor` (نفس بوابة القائمة والرسائل).
2. `ConversationController::read` يستدعي `assertReadable` ثم `markRead` ثم يحسب `unreadCountForConversation`.
3. Flutter يكتب Drift `unread_count = 0` و`local_read_at` فقط بعد 2xx.
4. فشل الـ API لا يدّعي قراءة محلية.

#### Drift behavior

`InboxLocalStore._upsertConversation`:

- إن `unread_count` القادم من السيرفر ≤ 0 → العرض 0 (مصدر البيانات، ليس إخفاء UI).
- إن وُجد `local_read_at` والسيرفر ما زال يعيد عدداً قديماً: لا يُستعاد إلا عند إثبات رسالة أحدث (`last_message.id` أكبر، أو timestamp أحدث عند غياب المعرف).
- نفس `last_message.id` مع timestamp أحدث ببضعة ms → يبقى 0.
- رسالة inbound جديدة بمعرف أحدث → يظهر العدد الجديد فقط.
- الصفوف تُحفظ تحت `workspace_id` الصحيح. لا فلتر Flutter يخفي المحادثات غير المسندة.

---

### Tests

#### الاختبارات الجديدة / المحدَّثة

Laravel — `tests/Feature/Mobile/WorkerGlobalInboxAndUnreadTest.php`:

1. عامل بـ read يرى محادثة غير مسندة.
2. عامل بـ read يرى محادثة مسندة لعامل آخر.
3. عامل بلا read → 403.
4. عزل الشركات (403 / 404).
5. ارتباط paused لا يرى المحادثات.
6. فتح المحادثة → mark-as-read → `conversation_user_states` + قائمة `unread_count = 0`.
7. GET لاحق لا يعيد العدد القديم.
8. inbound جديد → `unread_count = 1` ثم الفتح يصفره.
9. عدادات معزولة بين عامل/شركة.
10. `inbox.messages.send` يعمل على غير المسندة ومستقل عن التعيين.
11. read بدون send → قائمة ظاهرة و`can_reply = false` وPOST 403.

عُكست توقعات التعيين-كشرط-ظهور في:

- `EmployeeInboxAssignmentTest`
- `MobileWorkerInboxContractTest`
- `WorkerInboxTest` (ويب العامل: غير المسندة ومسندة لآخر تظهر مع read)

Flutter — `apps/hasim/test/inbox_unread_sync_test.dart`:

- السيرفر `unread = 0` يبقى 0 حتى لو `last_message_at` أحدث قليلاً.
- نفس `last_message.id` لا يستعيد العدد القديم.
- inbound بمعرف أحدث يعيد العدد.
- محادثة غير مسندة تُحفظ تحت `workspace_id` الصحيح ولا تختلط بشركة أخرى.

#### Laravel result

- Inbox/Worker/Mobile ذات الصلة: **265 passed** (`tests/Feature/Worker` + عقود Inbox المتنقلة).
- `WorkerGlobalInboxAndUnreadTest` + assignment + conversation API ضمنها.
- `tests/Feature/Feature/Inbox` + Omnichannel: **14 passed**.
- المجموعة الكاملة: **1680 passed / 1701** — الإخفاقات المتبقية خارج النطاق (AI Core/Preview، Meta webhook constructor، backup restore drill، queue after_commit، JobRetryPolicy). ليست رجوعاً لهذا الإصلاح.

#### Flutter result

- اختبارات Inbox / Conversation / Drift tenancy ذات الصلة: **115 passed**.
- المجموعة الكاملة: **528 passed**, **1 failed**: `inner_chrome_corner_test.dart` (سبب قديم 5×4 مقابل 4×4) — ليس رجوعاً حسب تعليمات المهمة.

#### analyze

`flutter analyze` في `apps/hasim`: **No issues found.**  
Pint على ملفات PHP المعدّلة: **passed.**

---

### Final behavior

**Capability = access scope.**  
ارتباط نشط + `inbox.messages.read` = كل محادثات تلك الشركة داخل الـ workspace المسموح. بلا عضوية، بلا Owner، بلا Inbox جديد.

**Assignment = organization/routing.**  
يسجّل من يتابع المحادثة. ليس شرط ظهور ولا شرط رد.

**Unread = يختفي بعد القراءة ولا يعود إلا عند وصول رسالة جديدة.**  
يُكتب في `conversation_user_states` على السيرفر، ثم Drift يطابق ذلك بعد نجاح mark-as-read. refresh / merge لا يعيد العدد القديم. رسالة inbound لاحقة تظهر `unread_count = 1` حتى تُقرأ مرة أخرى.

إثبات مصدر البيانات (وليس إخفاء UI):

- بعد الفتح: صف `conversation_user_states` بـ `last_read_message_id` الفعلي واستجابة `unread_count = 0`.
- GET القائمة بعد ذلك: `unread_count = 0`.
- GET متكرر (refresh): ما زال 0.
- inbound جديد: العدد 1 من عدّ الرسائل على السيرفر، ثم 0 بعد القراءة التالية.
- Drift: `unread_count` المعروض يتبع السيرفر 0 والقفل المحلي ضد الحمولة القديمة؛ لا يُصفَّر في الـ widget وحده.
