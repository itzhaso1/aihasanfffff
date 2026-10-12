# Hasim Chat — Employee Assignment UI Fix

**التاريخ:** 2026-10-06  
**النطاق:** Flutter فقط. Independent Worker يبقى خارج `workspace_users`. لم يُمس Laravel، ولا authorization، ولا Facebook، ولا Push، ولا AI.

---

## سبب المشكلة

قائمة العامل فارغة لأن المحادثة المفتوحة لم تُسند. صلاحيات Engagement (`inbox.messages.read` / `send`) لا تُظهر المحادثات وحدها.

Laravel يسمح بالانتقال:

`handoff_status = none` → `assigned`

مع حفظ:

- `assigned_user_id` = user id للعامل
- `assigned_worker_engagement_id` = معرف الارتباط

Flutter كان يمنع الشركة من تنفيذ هذا الانتقال من التطبيق.

---

## الشرط الذي كان يخفي التعيين

`ConversationHandoffValues.assignableStatuses` كان:

`requested`, `queued`

`chat_screen.dart` يعرض عنصر المزيد **تعيين المحادثة** فقط إذا:

- الحساب شركة (`isCompanyUser`)
- و `ConversationHandoffValues.canAssign(conversation)`

المحادثة المفتوحة (`handoff_status = none`) تفشل الشرط، فيختفي الخيار. الـ Sheet نفسه لا يفحص الحالة؛ الاختفاء من القائمة فقط.

العامل المستقل لا يرى خيار التعيين (كما يجب).

---

## الإصلاح

`assignableStatuses` أصبح:

`none`, `requested`, `queued`

ليطابق ما يسمح به Laravel. لا endpoint جديد. التعيين للعامل ما زال:

`POST /conversations/{id}/assign`  
`{"worker_engagement_id": ...}`

بعد نجاح الاستجابة الحالية:

- `ChatController` يحدّث حالة المحادثة من الـ response (`applyingServerWrites`).
- يُحفظ الصف في Drift عبر `persistConversations`.
- تُحدَّث قائمة الوارد عبر `applyRemoteConversation`.

عند الفشل:

- لا تُطبَّق استجابة النجاح.
- تبقى `handoff_status` السابقة (مثل `none`).
- يُعرض خطأ الـ Sheet / الرسالة القادمة من الـ API.
- لا تظهر «تم تعيين المحادثة».

لم يُحوَّل العامل إلى عضو مساحة.

---

## الملفات المعدلة

- `apps/hasim/lib/features/conversations/data/conversation_handoff.dart`
- `apps/hasim/test/inbox_actions_test.dart`

لم يُغيَّر Laravel.

---

## الاختبارات

- خيار التعيين ظاهر عندما `handoff_status = none`.
- اختيار Worker Engagement يرسل `worker_engagement_id` فقط.
- بعد النجاح: `assigned` في الشات والقائمة وDrift لمساحة العمل الحالية فقط (مساحة 2 لا ترى الصف).
- فشل التعيين من `none` يُبقي الحالة `none` ويعرض الخطأ ولا يعرض نجاحاً.
- العامل المستقل لا يحصل على أزرار التعيين.
- عزل Drift للمحادثة المسندة حسب `workspace_id` (موجود مسبقاً في `inbox_store_tenancy_test`).

الاختبارات المرتبطة: **51 passed**.

Flutter الكامل: **524 passed, 1 failed**.

الفشل الوحيد: `inner_chrome_corner_test.dart` (حجم overlay 5×4 مقابل 4×4) — قديم وغير مرتبط بالتعيين.

---

## نتيجة analyze

`flutter analyze`: **No issues found.**

---

## Blocker متبقٍ

بيانات التطبيق المحلية قبل هذا الإصلاح ما زالت تحتوي محادثات مفتوحة غير مسندة. لن تظهر للعامل حتى تعيّنها الشركة من **المزيد → تعيين المحادثة** بعد التحديث.

لم يُنفَّذ UAT على جهاز حقيقي في هذه الجلسة (`adb` غير متاح). نجاح الاختبارات لا يغني عن تعيين Conversation المفتوحة ثم دخول العامل.
