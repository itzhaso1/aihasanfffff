# Hasim Chat — Worker Engagement Capabilities من Flutter

**التاريخ:** 2026-10-06  
**النطاق:** إدارة صلاحيات `Worker Engagement` من تطبيق الشركة في Flutter.  
**خارج النطاق:** إنشاء نظام صلاحيات جديد، عضوية Workspace للعامل، تسجيل العامل، AI، Finance، POS، Commerce.

## الملخص

كان تدفق إدارة الصلاحيات موجودًا في Laravel Web فقط، ولم يكن هناك Mobile contract للشركة. أضيف Mobile contract رفيع يعيد استخدام:

- نفس `WorkerEngagementCapabilityService`.
- نفس Form Requests والتحقق من capability definitions النشطة.
- نفس `manageCapabilities` policy.
- نفس Workspace scoping وauthorization.

لم تتم إضافة capability جديدة، ولم تتم إضافة `workspace_users` للعامل. العامل ما زال Independent Worker خارج عضوية Workspace، والصلاحيات مرتبطة بالـ Engagement نفسه.

## عقد Laravel المستخدم

المسارات الجديدة تحت `auth:sanctum` و`workspace.member` و`workspace.resolve`:

- `GET /api/mobile/v1/company/worker-engagements/{engagement}/capabilities`
- `POST /api/mobile/v1/company/worker-engagements/{engagement}/capabilities`
- `DELETE /api/mobile/v1/company/worker-engagements/{engagement}/capabilities/{capability}`

الاستجابة تستخدم envelope الـ Mobile الحالي:

```json
{
  "success": true,
  "data": {
    "engagement_id": 22,
    "status": "active",
    "capabilities": [
      {
        "capability_key": "inbox.messages.read",
        "display_name": "Read inbox messages",
        "description": "Read workspace inbox messages assigned to the worker.",
        "allowed": true
      }
    ]
  }
}
```

طلب المنح يستخدم payload الموجود في Web:

```json
{
  "capability_key": "inbox.messages.read"
}
```

السحب يستخدم capability key في المسار. كلاهما Server-confirmed ولا يدخل Outbox. طلب المنح يدعم idempotency عبر middleware الحالي، ويعاد تحميل الحالة من الخادم بعد نجاح العملية.

التحقق يشمل:

- المستخدم المصادق عليه.
- Workspace الحالي.
- route model binding وعزل الـ Engagement.
- صلاحية `manageCapabilities`.
- كون الارتباط والعامل مؤهلين حسب نفس قواعد Laravel.
- أن capability موجودة ونشطة في `workforce_capability_definitions`.

العامل نفسه لا يستطيع إدارة صلاحياته. وبسبب `workspace.member` قد تكون النتيجة `403` أو `404` حسب نقطة الرفض، ولا يتم إنشاء عضوية Workspace.

## قائمة الصلاحيات المعروضة

Flutter يعرض فقط القائمة التي يعيدها Laravel، مع تسميات عربية من `WorkerCapabilityPresentation` الحالية:

- `inbox.messages.read`
- `inbox.messages.send`
- `customers.view`
- `customers.phone.view`
- `customers.email.view`
- `catalog.products.view`
- `commerce.orders.view`
- `commerce.orders.create`
- `commerce.payment_links.create`
- `reports.view`
- `email.view`
- `stories.view`

لا توجد capabilities ثابتة إضافية في Flutter غير موجودة في عقد Laravel.

## واجهة Flutter

مكان الواجهة:

`الإعدادات → التعاقد مع موظفين حقيقيين → العقود المفتوحة → إدارة الصلاحيات`

التجربة:

- شاشة بعنوان `صلاحيات الموظف`.
- عرض اسم العامل والارتباط.
- كل capability تظهر بحالة `مفعلة` أو `غير مفعلة`.
- Toggle مستقل لكل صلاحية.
- مؤشر تحميل صغير أثناء الطلب.
- عند النجاح تثبت الحالة التي أعادها الخادم.
- عند الفشل تبقى الحالة السابقة وتظهر رسالة عربية مفهومة.

تم استخدام Riverpod والبنية الحالية. لم تتم إضافة SQLite أو Drift table أو state framework جديد.

حالة capabilities الحالية بقيت Network-only؛ لا يوجد read model محلي مناسب لها في `InboxDatabase` الحالية، كما أن شاشة الإدارة تحتاج حالة Server-confirmed قبل تثبيت أي تغيير. لا توجد mutation في Outbox.

## الاختبارات

### Flutter

الاختبار المضاف:

`apps/hasim/test/company_worker_engagement_capabilities_test.dart`

يغطي:

- عرض capabilities والحالات العربية.
- Grant ثم تحديث الحالة المحلية من استجابة الخادم.
- Revoke ثم تحديث الحالة المحلية.
- ظهور loading حتى اكتمال الطلب.
- فشل الطلب مع بقاء الحالة السابقة وظهور الخطأ.

النتيجة:

- `flutter analyze`: **No issues found**
- الاختبار المركّز: **3 passed**
- المجموعة الكاملة: **484 passed، 2 failed**

الفشلان القديمان ولم ينتجا عن هذه المرحلة:

- `app_config_test.dart`: fallback الحالي `http://127.0.0.1:8001` يفشل تحقق HTTPS الخاص ببيئة الإنتاج.
- `inner_chrome_corner_test.dart`: الاختبار يتوقع حجم `4×4` بينما الحجم الفعلي `5×4`.

اختبار إدارة الصلاحيات الجديد مرّ ضمن المجموعة الكاملة.

### Laravel

`tests/Feature/Worker/WorkerEngagementCapabilityTest.php`:

- **15 passed**
- **99 assertions**

يغطي:

- قراءة القائمة الحالية.
- Grant وRevoke من Mobile.
- payload والتحقق من capability.
- Workspace isolation.
- منع عضو غير مخوّل.
- منع العامل من الإدارة الذاتية.
- بقاء العامل خارج `workspace_users`.
- عدم خلط صلاحيات Engagementين لنفس العامل.

كما تم التحقق من تسجيل المسارات عبر `php artisan route:list`، ومرّ `vendor/bin/pint --dirty --format agent` للملفات ذات الصلة.

## الملفات المعدلة

### Laravel

- `app/Http/Controllers/Api/Mobile/V1/Company/WorkerEngagementCapabilityController.php`
- `routes/mobile.php`
- `tests/Feature/Worker/WorkerEngagementCapabilityTest.php`

### Flutter

- `apps/hasim/lib/features/settings/data/company_worker_engagement_capabilities_repository.dart`
- `apps/hasim/lib/features/settings/providers/company_worker_engagement_capabilities_controller.dart`
- `apps/hasim/lib/features/settings/presentation/company_worker_engagement_capabilities_screen.dart`
- `apps/hasim/lib/features/settings/presentation/company_workers_section.dart`
- `apps/hasim/lib/features/settings/presentation/ai_settings_screen.dart`
- `apps/hasim/lib/router/app_router.dart`
- `apps/hasim/test/company_worker_engagement_capabilities_test.dart`

## القيود المتبقية

- إدارة الصلاحيات تحتاج اتصالًا بالخادم؛ لا يوجد لها cache محلي في هذه المرحلة.
- Worker registration ما زال Web-only كما يفرض العقد الحالي.
- لا توجد أي عملية capability في Outbox، ولا يمكن تعديل صلاحيات Engagement دون authorization الخادمي.
- لم يتغير Laravel خارج إضافة Mobile contract الضروري لهذا التدفق.

**الحالة:** مكتمل ضمن نطاق Worker Engagement Capabilities، مع بقاء فشلَي الاختبارات القديمين الموثقين أعلاه.
