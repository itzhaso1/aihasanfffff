# Hasim Chat — Facebook OAuth CORS + Employee Inbox Final Fix

**التاريخ:** 2026-10-06  
**النطاق:** Hasim Chat فقط. Independent Worker يبقى خارج `workspace_users`. لا AI، لا Push، لا Local-First architecture، لا endpoint جديد.

Checkpoint: One Inbox + Worker Authorization عبر Engagement capabilities.

---

## Facebook

### Root cause

الرسالة `تعذر تحديث هذا الجزء. حاول مرة أخرى.` ليست من Flutter. مصدرها Laravel Hotwire في `resources/js/page-nav.js`.

المسار الفعلي على قنوات الويب:

1. المستخدم يضغط **ربط Facebook** (`<a href="/workspace/.../channels/facebook/connect">`).
2. الرابط نفس الـ origin، لذلك `page-nav` يعترضه بـ `fetch`.
3. Laravel يرجع **HTTP 302** إلى `https://www.facebook.com/.../dialog/oauth`.
4. المتصفح يتبع الـ 302 داخل `fetch` → طلب cross-origin إلى Facebook → **CORS**.
5. `catch` يعرض رسالة الخطأ ويبقي الجزء مشغولاً.

هذا **بدء OAuth عبر fetch**، وليس فشل callback. Callback Laravel ما زال يحفظ الصفحة عند إكمال التدفق من تنقّل كامل للصفحة.

مسار Flutter الأصلي صحيح ولم يكن سبب CORS:

`POST /api/mobile/v1/channels/facebook_messenger/connect` → JSON `authorization_url` → `launchUrl` (متصفح النظام) → callback → `hasim://channels/oauth`.

### Evidence

- النص العربي مطابق حرفياً لـ `showLocalError` في `page-nav.js`.
- Flutter لا يملك هذه الجملة؛ أخطاء الربط هناك: `تعذر بدء ربط القناة.` / `تعذر فتح صفحة الربط.`
- `GET workspace.channels.facebook.connect` يرجع 302 إلى Facebook (مثبت بالاختبار).
- Instagram وTikTok كان لهما نفس الاعتراض.

### Fix

لم يُنشأ OAuth جديد. التدفق الصحيح الموجود هو **تنقّل أعلى الصفحة**، مثل WhatsApp الذي لا يستخدم رابط GET يعترضه Hotwire.

- روابط Facebook / Instagram / TikTok: `data-hs-nav="document"` حتى لا يعترضها `page-nav`.
- `page-nav.js` يتجاهل أيضاً مسار `/channels/(facebook|instagram|tiktok)/connect`.

النتيجة: Connect → تنقّل كامل → Facebook → callback Laravel → قناة متصلة. لا fetch إلى Facebook، لا CORS.

`data-hs-nav` يعمل فور إعادة تحميل Blade حتى بدون إعادة بناء Vite. استثناء المسار في JS يحتاج `npm run dev` أو `npm run build` ليصل للمتصفح.

### Files

- `resources/js/page-nav.js`
- `resources/views/workspace/channels/index.blade.php`
- `tests/Feature/Feature/Meta/FacebookChannelConnectTest.php`
- `tests/Feature/Feature/Workspace/ChannelsPageTest.php`
- `apps/hasim/test/channels_connection_test.dart`

لم تُغيَّر credentials أو تخزين التوكن.

### Tests (code verified)

- Laravel: Facebook connect 302 إلى `facebook.com`؛ الصفحة تعرض `data-hs-nav="document"`؛ callback بحالة غير صالحة لا يحفظ اتصالاً؛ عقد الموبايل `POST .../facebook_messenger/connect` يبقى JSON 200.
- Flutter: Connect Facebook يفتح `https://www.facebook.com/.../dialog/oauth` عبر launcher، ولا تظهر رسالة CORS.

Mobile channel contract: ضمن 34 اختباراً ناجحاً مع الصفحة وFacebook connect وتعيين الموظف.

### Real browser UAT

**لم يُنفَّذ. ليس Fixed على الجهاز.**

لا توجد جلسة متصفح مسجّلة، ولا إكمال OAuth حي مع Meta في هذه الجلسة. المتصفح المدمج كان بلا تبويب لتطبيق يعمل.

بعد سحب التعديل: افتح قنوات الويب → ربط Facebook → يجب أن تنتقل الصفحة كاملة إلى Facebook بدون رسالة CORS.

---

## Employee Inbox

### Root cause

الطبقة المكسورة هي **التعيين من واجهة الشركة في Flutter**، ثم قائمة العامل التي لا تُظهر إلا محادثة مسندة.

ليست صلاحية ناقصة، وليست Drift يخفي الصفوف، وليست استعلام Laravel يتجاهل محادثة مسندة.

Laravel كان يسمح أصلاً `none → assigned`. Flutter أخفى **تعيين المحادثة** إلا إذا كانت الحالة `requested` أو `queued`. المحادثة المفتوحة (`handoff_status = none`) لا يمكن تعيينها من التطبيق، فتبقى:

- `assigned_user_id = null`
- `assigned_worker_engagement_id = null`

قائمة العامل (`MobileInboxActor::constrain`) تطلب الحقلين مع `inbox.messages.read`. النتيجة `data: []`.

### Actual IDs / state before this fix (live DB)

Workspace **2** — العضوية الوحيدة: `user_id = 2` (owner). العامل ليس عضواً.

| كيان | ID | حالة |
| --- | --- | --- |
| Worker profile | 2 | `user_id = 4`، الاسم الظاهر «حسن الحسينات» |
| Engagement | 6 | `workspace_id = 2`، `status = active` |
| Capabilities على 6 | — | `inbox.messages.read` و`inbox.messages.send` ممنوحتان |
| Conversation المفتوحة | **4** | `workspace_id = 2`، `status = open`، `handoff_status = none`، **كلا حقلي الإسناد null** |

محادثات مسندة موجودة لمساحات أخرى (مثلاً conversation 1 على workspace 3، conversation 2 على workspace 4) لعامل UAT آخر (`user_id = 7`). تلك ليست محادثة الشركة 2.

لذلك GET العامل على workspace 2 يرجع قائمة فارغة حتى لو الصلاحيات كاملة.

### API response

بعد التعيين الصحيح عبر العقد الحالي:

`POST /api/mobile/v1/conversations/{id}/assign`  
`{"worker_engagement_id": <engagement id>}`

Laravel يكتب:

- `assigned_user_id` = `worker_profiles.user_id` للارتباط
- `assigned_worker_engagement_id` = معرف الارتباط
- `handoff_status` = `assigned`

ثم:

- عامل A + `X-Workspace-Id` الصحيح → المحادثة في `GET /conversations` والرسائل في `GET .../messages` والرد عبر `POST .../messages` إذا وُجدت `inbox.messages.send`.
- عامل B → `data: []`
- Workspace آخر → 403
- محادثة غير مسندة → لا تظهر للعامل

Flutter يحفظ ما أعاده السيرفر في Drift حسب `workspace_id` فقط؛ لا يفلتر بـ engagement في SQLite. القائمة الفارغة تعني أن الـ API أعاد فارغاً.

### Fix

- `ConversationHandoffValues.assignableStatuses` يشمل `none` ليتوافق مع `ConversationHandoffService` (`none => requested, assigned`).
- قائمة المزيد تعرض **تعيين المحادثة** على المحادثة المفتوحة.
- الإرسال ما زال `worker_engagement_id` للعامل، و`user_id` لموظف الشركة العضو.
- لم يُحوَّل العامل إلى عضو مساحة، ولم يُنشأ Inbox ثانٍ.

صفوف قاعدة البيانات الحالية غير المسندة **لن تظهر وحدها**. يجب تعيين Conversation 4 إلى Engagement 6 من شاشة المحادثة بعد التحديث.

### Files

- `apps/hasim/lib/features/conversations/data/conversation_handoff.dart`
- `apps/hasim/test/inbox_actions_test.dart`
- `tests/Feature/Mobile/EmployeeInboxAssignmentTest.php`

### Tests (code verified)

Laravel `EmployeeInboxAssignmentTest`:

- محادثة مفتوحة غير مسندة → العامل يرى `[]`.
- `POST /assign` بـ `worker_engagement_id` بدون طلب تحويل سابق → الحقلان يُحفظان.
- العامل A يرى المحادثة والرسالة ويرسل رداً.
- العامل B لا يراها.
- مساحة أخرى 403.

Flutter:

- `canAssign` صحيح لـ `none`.
- قائمة المزيد تسمح بالتعيين وإرسال `worker_engagement_id`.
- Drift يعزل حسب `workspace_id` بعد الإسناد.

Worker inbox + handoff V2: 17 نجحت. المجموع مع اختبارات Facebook/الموبايل أعلاه: 34 + 17.

`flutter analyze`: No issues found.  
Flutter المرتبطة: **48 passed**.

### Real device UAT

**لم يُنفَّذ. ليس Fixed على الهاتف.**

- `adb` غير متاح في هذه الجلسة.
- قاعدة البيانات الحية ما زالت كما في الجدول أعلاه: Conversation 4 غير مسندة. قائمة الموظف ستبقى فارغة حتى يتم التعيين من النسخة المحدّثة.

بعد التحديث:

1. الشركة تفتح Conversation 4 → المزيد → تعيين المحادثة → اختيار ارتباط العامل 6.
2. تحقق في DB: `assigned_user_id = 4` و`assigned_worker_engagement_id = 6`.
3. تسجيل دخول المستخدم 4 → المحادثة تظهر → فتحها → الرسالة → إرسال.

---

## Final status

| الموضوع | Code verified | Real UAT verified | الحالة |
| --- | --- | --- | --- |
| Facebook CORS / OAuth start | نعم | لا | **Blocked على UAT الحي** — الإصلاح في الكود |
| Employee inbox visibility | نعم | لا | **Blocked على UAT الحي** — السبب طبقة التعيين؛ البيانات الحية ما زالت غير مسندة |

لا يُكتب Fixed اعتماداً على unit tests فقط.

### Remaining blockers

1. إكمال Facebook OAuth في متصفح مسجّل مع حساب Meta (وربط Vite إن لزم لاستثناء `page-nav` الإضافي).
2. تعيين Conversation 4 → Engagement 6 من واجهة الشركة بعد التحديث، ثم دخول العامل 4.
3. جهاز Android / ADB للتحقق من قائمة الموظف على الهاتف.

`inner_chrome_corner_test.dart` لم يُمس؛ الفشل القديم 5×4 مقابل 4×4 ليس جزءاً من هذا الإصلاح.
