# تقرير تسليم مشروع Independent Worker
## الحالة الحالية — 2026-09-28

هذا الملف هو Checkpoint للمشروع حتى نكمل لاحقًا من نفس النقطة بدون إعادة تنفيذ المراحل المكتملة.

---

# 1. الرؤية المعمارية الأساسية

الـ Independent Worker حساب مستقل عن `workspace_users`.

يعني:
- Independent Worker ليس Workspace Member.
- Independent Worker ليس AI Employee.
- Independent Worker ليس Company Admin.
- Independent Worker ليس مستخدم Finance/POS.
- صلاحياته مرتبطة بالـ Engagement والـ Capabilities.
- الـ Backend هو صاحب القرار في Authorization.
- Flutter مسؤول عن تجربة الاستخدام، وليس عن الأمان.

أهم قرار معماري:

> One Inbox + One Flutter Inbox + One Web Inbox + Worker Authorization

وليس:

> Main Inbox + Worker Inbox

---

# 2. Foundation — ✅ مكتمل

تم إنجاز:
- تسجيل دخول Independent Worker بدون `workspace_users`.
- التعرف على نوع الحساب.
- Worker routing.
- Worker shell الأساسي.
- `WorkerRepository`.
- قراءة الارتباطات.
- قبول/رفض الارتباطات.
- capabilities.
- الحفاظ على فصل Worker عن workspace membership.

الحالة: **✅ مكتمل**

---

# 3. Profile + Verification — ✅ مكتمل

## Profile
- الاسم الظاهر.
- الصورة.
- الدولة.
- الجنسية.
- الملخص المهني.
- مستوى خبرة المبيعات.
- حالة التوفر.

## Skills
- عرض المهارات.
- تعديل المهارات.

## Languages
- عرض/تعديل اللغات.

## Experiences
- إضافة.
- تعديل.
- حذف.

## Identity
- الاسم القانوني.
- تاريخ الميلاد.
- رقم الهوية.
- العنوان.

## Documents
- عرض/رفع الوثائق.

## Verification
- إرسال طلب التحقق.
- عرض حالة التحقق.
- إشعارات الرفض / طلب الوثائق.
- عدم كشف `review_notes` الداخلية للـ Worker.

الحالة: **✅ مكتمل**

---

# 4. Offers + Engagements — ✅ مكتمل

تدفق Worker:

العروض → فتح العرض → قبول/رفض → Engagement → تفاصيل الارتباط.

حالات Engagement المعتمدة:
- `draft`
- `offered`
- `accepted`
- `rejected`
- `active`
- `paused`
- `completed`
- `terminated`

كما تم دعم capabilities على مستوى Engagement.

الحالة: **✅ مكتمل**

---

# 5. Inbox — ✅ مكتمل معماريًا

## Web

Worker يستخدم نفس Inbox الأساسي بدل صفحة Worker منفصلة.

تم توحيد:
- communication layout.
- sidebar.
- conversation list.
- search.
- filters.
- conversation detail.
- message list.
- composer.
- empty state.
- communication navigation.

المسار Worker:

`/workers/inbox`

ويستخدم نفس تجربة:

`/workspace/conversations`

مع اختلاف البيانات والصلاحيات فقط.

## Flutter

Worker يستخدم نفس Inbox الأساسي:

`/conversations`

مع:
- `ConversationsScreen`
- `ChatScreen`
- `ChatComposer`
- `ConversationRepository`
- `ConversationsController`
- search
- all/unread filters
- canonical shell
- `HasimAdaptiveScaffold`

تم إزالة فكرة Worker Inbox منفصل.

## Authorization

تم توسيع Mobile Inbox Contract ليخدم:
1. Workspace Member
2. Independent Worker صاحب Engagement مصرح له

Capabilities الأساسية:
- `inbox.messages.read`
- `inbox.messages.send`

Worker لا يحصل على `workspace_users`.

## Human attribution
رسائل Worker:
- Human message.
- `ai_generated = false`.
- `worker_engagement_id` موجود.
- لا تدخل `AiRuntime`.

## Inbox Settings / Shell
تم التأكد أن Worker يدخل نفس Inbox shell، وليس Worker shell مغلفًا حوله.

الحالة: **✅ مكتمل**

---

# 6. Inbox Known Limitations — ليست ضمن المرحلة الحالية

ما زالت بعض عمليات Inbox membership-only:
- mark-read
- archive
- mute
- handoff
- attachments
- realtime/live updates

هذه ليست سببًا لإعادة بناء Inbox، ولا نعمل لها Worker APIs منفصلة الآن.

الحالة: **⚠️ Gaps مستقبلية**

---

# 7. Worker Settings — ✅ مكتمل

تم السماح للـ Worker بفتح:

`/settings`

باستخدام نفس `SettingsScreen`.

لا يوجد:

`WorkerSettingsScreen`

## ما يظهر للـ Worker
- ملفي الشخصي.
- المظهر والسمات.
- Dark Mode / Light Mode.
- الإعدادات المحلية/الشخصية الموجودة أصلًا والمناسبة له.
- تسجيل الخروج.

## ما لا يظهر
- Channels.
- Catalog.
- AI Settings.
- Company Profile.
- Workspace administration.
- Company-only settings.

## ملفي الشخصي
يذهب إلى Worker Account/Profile وليس Company Profile.

## Themes
إعادة استخدام:
- `ChatThemesScreen`
- `DisplayAppearanceSettings`
- `PrefsStore`
- نظام Dark/Light الحالي.

الحالة: **✅ مكتمل**

---

# 8. Work Sessions — Backend ✅ مكتمل

الـ API الموجود:
- `GET /api/mobile/v1/worker/engagements/{engagement}/session`
- `POST /api/mobile/v1/worker/engagements/{engagement}/session/start`
- `POST /api/mobile/v1/worker/engagements/{engagement}/session/break`
- `POST /api/mobile/v1/worker/engagements/{engagement}/session/resume`
- `POST /api/mobile/v1/worker/engagements/{engagement}/session/end`

## Session Status
- `started`
- `ended`

## Segment Types
- `active`
- `break`
- `idle`

Backend هو صاحب القرار في:
- Worker ownership.
- Verification.
- Lifecycle.
- Engagement status.
- Duplicate sessions.
- timestamps.
- duration.

الحالة: **✅ مكتمل**

---

# 9. Work Sessions — Flutter ✅ مكتمل

مكانها:

الأعمال → الارتباط → جلسة العمل

تم تنفيذ:
- `WorkerWorkSession` model.
- `WorkerWorkSessionSegment` model.
- Methods داخل `WorkerRepository`.
- Riverpod controller.
- UI.
- Start.
- Break.
- Resume.
- End.
- End confirmation.
- Session history.
- Live display timer.
- Refresh عند العودة من background.
- Duplicate action protection.

## قاعدة الوقت
Flutter لا يخزن الوقت كحقيقة.

Laravel يعيد:
- `started_at`
- `ended_at`
- `duration_seconds`
- `segments`

والـ Flutter يعرضها.

الحالة: **✅ مكتمل**

---

# 10. Work Sessions Known Limitations

لا يوجد Mobile API عام لـ Idle transition.

لذلك:
- لا يوجد Idle button.
- إذا رجع `idle` من backend يتم عرضه فقط.
- لا نخترع endpoint جديد.

كذلك توجد بعض Domain 422 messages ما زالت بالإنجليزية.

هذه تحسينات لاحقة وليست سببًا لإعادة بناء Work Sessions.

---

# 11. Earnings + Payouts + Disputes — Backend Discovery والـ Contract

تم اكتشاف أن التعويضات هي Dedicated Worker Domain وليست Finance/POS/GL.

المصادر:
- `worker_earning_periods`
- `worker_earning_lines`
- `worker_earning_approvals`
- `worker_payout_records`
- `worker_disputes`

---

# 12. Earnings — أهم قاعدة

الأرباح ليست:

`duration_seconds × rate`

وليست مشتقة تلقائيًا من Orders أو Invoices.

الأرباح عبارة عن Company-authored earning periods تحتوي manual earning lines.

مصادر السطر الممكنة:
- `engagement_terms`
- `work_session`
- `activity_event`
- `adjustment`

لكن قيمة السطر نفسها Manual Amount.

العلاقة مع Work Session اختيارية، وليست حسابًا آليًا للأجر.

---

# 13. Earning Period

الجدول:

`worker_earning_periods`

ويحتوي على بيانات مثل:
- `id`
- `workspace_id`
- `worker_engagement_id`
- `status`
- `starts_at`
- `ends_at`
- `created_by_user_id`
- `approved_by_user_id`
- `approved_at`
- `paid_at`

لا يوجد `worker_profile_id` مباشرة على period؛ الوصول للـ Worker عبر Engagement.

---

# 14. Earning Lines

الجدول:

`worker_earning_lines`

المهم فيه:
- `id`
- `workspace_id`
- `worker_engagement_id`
- `worker_earning_period_id`
- `line_type`
- `source_type`
- `source_id`
- `source_reference`
- `amount_minor`
- `currency`
- `description`
- `adjustment_reason`

Money:

`amount_minor + currency`

ولا تستخدم floating point.

---

# 15. Earnings Statuses

الحالات:
- `draft`
- `pending_approval`
- `approved`
- `disputed`
- `paid`

الانتقالات:
- draft → pending_approval
- pending_approval → approved / disputed
- approved → disputed / paid
- disputed → pending_approval
- paid → terminal

Worker Mobile يستبعد `draft`.

Worker Mobile يرى:
- `pending_approval`
- `approved`
- `disputed`
- `paid`

---

# 16. Earnings Mobile API — جاهز

المسار canonical:

`GET /api/mobile/v1/worker/engagements/{engagement}/earnings`

لا يوجد Global:

`GET /worker/earnings`

الاستجابة تتضمن:
- `id`
- `worker_engagement_id`
- `status`
- `starts_at`
- `ends_at`
- `totals_minor`
- `lines[]`

والـ lines تحتوي:
- `id`
- `line_type`
- `source_type`
- `source_id`
- `amount_minor`
- `currency`
- `description`

Ownership backend-authoritative.

Foreign engagement → 404.

---

# 17. Payouts — Backend جاهز

المصدر:

`worker_payout_records`

لا يوجد Settlement entity منفصل.

Mobile endpoint:

`GET /api/mobile/v1/worker/payouts`

Read-only.

Fields:
- `id`
- `worker_engagement_id`
- `worker_earning_period_id`
- `amount_minor`
- `currency`
- `status`
- `requested_at`
- `processing_at`
- `paid_at`
- `failed_at`
- `cancelled_at`
- `failure_reason`

Statuses:
- `pending`
- `processing`
- `paid`
- `failed`
- `cancelled`

## غير موجود عمدًا
- request payout
- bank account
- IBAN
- withdrawal
- Worker cancel payout
- fake balance

لا نخترع هذه الميزات.

---

# 18. Disputes — نوعان مختلفان

## A. WorkerDispute

قضية مستقلة مرتبطة بالـ Engagement.

Mobile:
- `GET /api/mobile/v1/worker/disputes`
- `GET /api/mobile/v1/worker/disputes/{dispute}`
- `POST /api/mobile/v1/worker/engagements/{engagement}/disputes`

Statuses:
- `open`
- `under_review`
- `resolved`
- `closed`

Worker يستطيع إنشاء القضية.

Worker لا يستطيع resolve أو update.

لا توجد attachments أو comment thread حاليًا.

## B. Earning-Period Dispute

اعتراض على فترة أرباح محددة.

Mobile:

`POST /api/mobile/v1/worker/engagements/{engagement}/earnings/{period}/dispute`

Body:

`reason` اختياري بحد أقصى 2000.

Allowed:
- pending_approval
- approved

Blocked:
- paid
- open pending payout
- open processing payout

النتيجة:

`period.status = disputed`

ولا يتم إنشاء `worker_disputes`.

هذا المساران يجب أن يبقيا منفصلين في Flutter والـ Backend.

---

# 19. Compensation Backend Tests — ✅

آخر Backend Compensation implementation نجح في:

`37 passed`

في مجموعة:
- `WorkerMobileCompensationTest`
- `WorkerMobileApiTest`
- `WorkerEarningsTest`
- `WorkerPayoutTest`
- `WorkerComplianceTest`

كما نجحت مجموعة أخرى:

`22 passed`

مرتبطة بـ Work Sessions / operational / workforce.

و:

`Pint --dirty` → passed.

---

# 20. المتبقي الآن — Feature Work

## المرحلة الوحيدة المتبقية قبل الاختبار النهائي:

### Flutter Compensation

يجب تنفيذ:

### A. Earnings
- قائمة فترات الأرباح.
- حالة الفترة.
- totals لكل currency.
- تفاصيل الفترة.
- earning lines.
- اعتراض على earning period عندما يكون مسموحًا.

### B. Payouts
- سجل الدفعات.
- عرض status.
- عرض التواريخ.
- failure reason عند وجوده.

Read-only فقط.

### C. WorkerDisputes
- قائمة.
- pagination.
- filters.
- detail.
- إنشاء dispute.
- لا يوجد update/resolve.

### D. Earning-period dispute
مسار مستقل عن WorkerDispute.

---

# 21. مكان Flutter Compensation المقترح

استخدم بنية Worker الحالية.

الأفضل أن تكون داخل مسار:

الأعمال → الارتباط → تفاصيل الارتباط

ثم:
- جلسة العمل
- الأرباح
- الدفعات
- الاعتراضات

لا تضف bottom-nav item جديد إلا إذا احتاجت البنية الحالية ذلك فعلًا.

لا تبنِ Finance/Wallet architecture جديدة.

---

# 22. ما لا يجب بناءه

ممنوع اختراع:
- Worker Wallet.
- Running Balance.
- Withdraw.
- Bank Account.
- IBAN.
- Auto payroll.
- Hours × rate calculator.
- Finance ledger.
- POS integration.
- Order-based salary calculation.

الموجود فعليًا هو Worker compensation domain.

---

# 23. بعد Flutter Compensation — Final UAT

بعد إنهاء Flutter Compensation فقط، نبدأ:

`Final UAT`

والهدف اختبار Worker من البداية للنهاية:

1. Login.
2. Profile.
3. Verification.
4. Offers.
5. Engagements.
6. Inbox.
7. Settings.
8. Work Sessions.
9. Earnings.
10. Payouts.
11. Disputes.

---

# 24. Final UAT — Security

يجب اختبار أن Worker A لا يستطيع الوصول إلى بيانات Worker B:
- earnings.
- payouts.
- disputes.
- engagement data.

ويجب التأكد أنه لا يدخل:
- Finance.
- POS.
- Workspace administration.
- AI administration.

حتى لو تم تغيير IDs يدويًا.

---

# 25. Final UAT — Inbox

يجب التأكد بصريًا ووظيفيًا أن Worker يرى:
- نفس Inbox shell.
- نفس navigation.
- نفس sidebar.
- نفس search.
- نفس filters.
- نفس conversations.
- نفس Chat.
- نفس composer.

والاختلاف يكون في:
- data scope.
- capabilities.
- allowed actions.

وليس في Inbox implementation.

---

# 26. Final UAT — Settings

Worker يجب أن يرى:
- ملفه الشخصي.
- المظهر.
- themes.
- Dark/Light.
- personal/local settings.
- Logout.

ولا يرى:
- Company Profile.
- Channels.
- Catalog.
- AI Settings.
- Workspace administration.

---

# 27. Final UAT — Sessions

اختبار:

Start → Break → Resume → End

ثم:
- إغلاق التطبيق.
- إعادة فتحه.
- التأكد من أن server state رجعت بشكل صحيح.
- التأكد من عدم اعتماد timer المحلي كمصدر حقيقة.

---

# 28. Final UAT — Compensation

## Earnings
- pending_approval.
- approved.
- disputed.
- paid.
- multi-currency.
- lines.
- totals.
- draft غير ظاهر.

## Payouts
- pending.
- processing.
- paid.
- failed.
- cancelled.

## WorkerDispute
- create.
- list.
- filters.
- pagination.
- detail.
- status.

## Earning Period Dispute
- create objection.
- period becomes disputed.
- لا يتحول إلى WorkerDispute.

---

# 29. Known Future Gaps — ليست ضمن الإغلاق الحالي

## Inbox
- mark-read
- archive
- mute
- handoff
- attachments
- realtime

## Work Sessions
- public idle transition

## Compensation
- Worker payout request.
- bank destination.
- IBAN.
- running balance.
- automatic payroll.
- auto pay from Work Sessions.

## Disputes
- attachments.
- comment/message thread.

## Web
Worker Web remains Chat-only.

لا نضيف Worker Web Earnings/Disputes في المرحلة الحالية.

---

# 30. Current Checkpoint

| المجال | الحالة |
|---|---|
| Foundation | ✅ |
| Profile | ✅ |
| Verification | ✅ |
| Offers | ✅ |
| Engagements | ✅ |
| Inbox Web | ✅ |
| Inbox Flutter | ✅ |
| Inbox Shell | ✅ |
| Worker Settings | ✅ |
| Work Sessions Backend | ✅ |
| Work Sessions Flutter | ✅ |
| Compensation Backend | ✅ |
| Compensation Flutter | ⏳ |
| Final UAT | ⏳ |

---

# 31. عند توفر Cursor Token — أول خطوة

لا تبدأ بأي refactor.

ابدأ مباشرة بـ:

`PHASE 5C — FLUTTER WORKER EARNINGS + PAYOUTS + DISPUTES`

المطلوب منه:
- Flutter only.
- WorkerRepository.
- existing Worker shell.
- Riverpod الموجود.
- existing design system.
- no new financial ledger.
- no Worker Web compensation.
- no new payout request system.

بعد أن ينتهي Cursor، لا نعتبر المرحلة ناجحة لمجرد أنه قال "done". نراجع:
- الـ screens.
- الـ routes.
- الـ repository.
- الـ providers.
- الـ API calls.
- الـ money handling.
- الـ dispute separation.
- الـ tests.
- `dart analyze`.

ثم:

`FINAL UAT`

---

# 32. الخلاصة

المشروع حاليًا في هذه النقطة:

Foundation ✅
→ Profile ✅
→ Verification ✅
→ Offers ✅
→ Engagements ✅
→ Inbox ✅
→ Settings ✅
→ Work Sessions ✅
→ Backend Compensation ✅
→ **Flutter Compensation ⏳**
→ **Final UAT ⏳**

إذًا المتبقي الأساسي فعليًا:

> Flutter Earnings + Payouts + Disputes
> ثم Final UAT وإغلاق أي bugs حقيقية تظهر أثناء الاختبار.

ولا نعيد بناء أي مرحلة مكتملة إلا إذا ظهر Regression حقيقي.

---

## ملاحظة مصدر

التفاصيل الخاصة بـ Earnings / Payouts / Disputes والـ Mobile API مأخوذة من تقرير Phase 5A/5B الذي تم إنجازه أثناء المشروع. التقرير يؤكد أن الـ Worker compensation domain مستقل عن Finance/POS/GL، وأن الـ Mobile compensation contract أصبح جاهزًا للاستهلاك من Flutter.
