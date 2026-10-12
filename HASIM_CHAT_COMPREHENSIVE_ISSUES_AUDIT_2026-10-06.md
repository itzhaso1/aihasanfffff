# Hasim Chat — Comprehensive Product & Technical Issues Audit

**التاريخ:** 2026-10-06  
**النطاق:** Hasim Chat (`apps/hasim` + `routes/mobile.php` + Drift local-first + AI inbox + Worker/Employee flows)  
**النوع:** تدقيق وتشخيص فقط — **لم يُعدَّل أي كود تطبيق** في هذه المرحلة (هذا الملف هو المخرج الوحيد).

**Checkpoint:** `Independent_Worker_Project_Checkpoint_2026-09-28.md` — Independent Worker خارج `workspace_users`؛ Inbox واحد؛ Backend صاحب قرار Authorization.

---

## Executive Summary

| المقياس | العدد |
| --- | ---: |
| **P0** (حرج) | **0** |
| **P1** (أساسي مكسور / غير موثوق) | **2** |
| **P2** (مهم، لا يمنع الاستخدام الأساسي بالكامل) | **5** |
| **P3** (UX / تنظيف / ديون تقنية) | **3** |
| **Backend contract gaps** | **3** |
| **Infrastructure / Environment** | **3** |
| **Verified fixed** (كانت مشكلة، لم تعد في الكود الحالي) | **8** |

**اختبارات نُفّذت أثناء التدقيق (بدون تعديل كود):**

| Suite | النتيجة |
| --- | --- |
| `php artisan test tests/Feature/Mobile` | **78 passed** |
| `php artisan test` — Handoff + Employee inbox + Worker inbox (13 tests) | **13 passed** |
| `php artisan test` — `MobileChannelConnectTest` | **21 passed** |
| `php artisan test` — `CrossWorkspaceIdorTest` | **passed** (ضمن 12 tests مع 1 unit failure منفصل) |
| `flutter analyze` (من التقارير الأخيرة + suites) | **No issues found** |
| `flutter test` (كامل) | **506 passed, 1 failed** (`inner_chrome_corner_test.dart`) |
| Flutter مستهدف: channels + catalog + local-first G | **All passed** |

---

## Issue Table

| ID | Priority | Domain | المشكلة | السبب المحتمل | الطبقة | Blocker | الحل المقترح |
| --- | --- | --- | --- | --- | --- | --- | --- |
| HC-001 | P1 | Notifications | لا push حقيقي على الجهاز للشركة | `UnconfiguredNativePushTokenSource` يعيد token فارغ؛ لا FCM/APNs SDK | Flutter + Infrastructure | نعم للخلفية | دمج FCM/APNs + `CompanyDevicePushService` الحالي |
| HC-002 | P1 | Email | مرفقات البريد غير قابلة للعرض/التنزيل في Flutter | `EmailMessageResource` لا يُرجع `download_url`/اسم ملف؛ `EmailMessageModel` لا ي parse `attachments` | API + Flutter | جزئي | عقد mobile مثل chat attachments ثم UI |
| HC-003 | P2 | Notifications | Independent Worker بلا push | `POST /devices` خلف `workspace.member` | API | لا (in-app يعمل) | مسار devices للعامل أو قرار منتج صريح |
| HC-004 | P2 | Realtime | تأخر تحديث المحادثات | `HybridRealtimeService` يقرأ config لكن **لا يشترك** في WebSocket؛ polling 4–12 ثانية | Flutter + Integration | لا | Echo/Reverb subscription أو الاعتماد على push |
| HC-005 | P2 | Inbox / Worker | صلاحية inbox وحدها لا تُظهر محادثات | تصميم: `assigned_user_id` + `assigned_worker_engagement_id` مطلوبان | Laravel + Product | لا | تعيين من الشركة + توعية UX |
| HC-006 | P2 | Catalog | قوالب المنتجات غير موجودة في Flutter | لا UI/Caller لـ `/catalog/product-templates*` | Flutter | لا | شاشة اختياريّة أو تأجيل |
| HC-007 | P2 | Channels | قناة Email `web_only` | `ChannelController` يفرض `connectionMode: web_only` | Laravel + Flutter | لا | `manageUrl` أو عقد mobile لاحقًا |
| HC-008 | P3 | Tests | `inner_chrome_corner_test` يفشل | توقع `Size(4,4)` vs فعلي `5×4` (Flutter test/layout) | Flutter test | لا | تحديث expectation أو widget |
| HC-009 | P3 | AI architecture | `CoreArchitectureBoundaryTest` يفشل | `ContextPayload` يستورد `App\AI\Context\AiContext` | Laravel test / AI | لا | فصل DTO أو استثناء في boundary test |
| HC-010 | P3 | AI settings | لا picker لـ `GET /ai/skills` | Presets تغطي المسار الرئيسي | Flutter | لا | اختياري |

---

## P0 — Critical

**لا توجد مشكلة P0 مثبتة** في الكود أو الاختبارات الحالية:

- عزل Workspace: `CrossWorkspaceIdorTest` ناجح.
- Mobile API المستخدم من Flutter: **78** اختبار Feature Mobile ناجح؛ لا مسار Flutter يستدعي route غير موجود (تأكيد من تقارير parity + فحص repositories).
- لا regression مثبت لـ thought leak أو Facebook busy loading أو فصل normal/digital في الاختبارات المستهدفة.

---

## P1 — Core Broken / Unreliable

### HC-001 — Push notifications (company staff)

| Field | Detail |
| --- | --- |
| **Domain** | Notifications / Device |
| **Screen/Flow** | Login → workspace → background app → رسالة واردة |
| **الوصف** | التطبيق يستدعي `CompanyDevicePushService.initializeAndRegister()` بعد الدخول، لكن **لا يُرسل token** إلى Laravel في build الحالي. |
| **Expected** | تسجيل جهاز عبر `POST /api/mobile/v1/devices` واستلام push عند FCM/APNs. |
| **Actual** | `_status` يبقى «لا يوجد رمز جهاز حقيقي. لم يُرسل شيء إلى الخادم». |
| **Root cause** | `pushTokenSourceProvider` = `UnconfiguredNativePushTokenSource` → `currentToken()` دائمًا `null`. |
| **الطبقة** | Flutter + Infrastructure |
| **دليل** | `apps/hasim/lib/push/push_token_source.dart`, `apps/hasim/lib/core/di/providers.dart`, `apps/hasim/lib/push/push_service.dart` |
| **Blocker** | نعم لـ **UAT push في الخلفية** |
| **الإصلاح المقترح** | إضافة Firebase/APNs native، token source حقيقي، الإبقاء على `DeviceRepository` الحالي. |
| **Laravel؟** | لا (العقد موجود) |
| **Mobile contract؟** | لا |
| **Real device؟** | **نعم** للتحقق النهائي |

---

### HC-002 — Email attachments

| Field | Detail |
| --- | --- |
| **Domain** | Email |
| **Screen/Flow** | Email detail / inbox message with attachments |
| **الوصف** | Laravel يوفّر `GET /emails/attachments/{id}/download` لكن payload التفصيلي للرسالة لا يعطي Flutter رابطًا جاهزًا؛ الواجهة لا تعرض قائمة مرفقات. |
| **Expected** | عرض المرفقات وفتح/تنزيل مثل chat `download_url`. |
| **Actual** | `EmailMessageModel` بدون حقل attachments؛ المستخدم يرى body/npreview فقط. |
| **Root cause** | `EmailMessageResource` (detailed) يُرجع `id`, `file_type`, `file_size` فقط — **بدون** `download_url` أو `file_name`. |
| **الطبقة** | API + Flutter |
| **دليل** | `app/Http/Resources/Mobile/EmailMessageResource.php` (سطر attachments map); `apps/hasim/lib/core/models/models.dart` (`EmailMessageModel`) |
| **Blocker** | جزئي — البريد النصي يعمل؛ المرفقات لا |
| **الإصلاح المقترح** | Laravel: signed `download_url` + اسم العرض في resource؛ Flutter: model + أزرار في `email_detail_screen`. |
| **Laravel؟** | **نعم** |
| **Mobile contract؟** | **نعم** (توسيع resource) |
| **Real device؟** | مفيد |

---

## P2 — Important

### HC-003 — Worker push registration

| Field | Detail |
| --- | --- |
| **Domain** | Notifications / Independent Worker |
| **Screen/Flow** | Worker login + background |
| **الوصف** | العامل يستخدم in-app `/notifications` لكن لا مسار آمن لتسجيل الجهاز مثل الشركة. |
| **Root cause** | `POST /devices` ضمن `workspace.member`; العامل ليس member بالتصميم. |
| **الطبقة** | API |
| **دليل** | `HASIM_CHAT_REMAINING_FLUTTER_GAPS_2026-10-05.md` (C3); `CompanyDevicePushService` يتخطى non-company workspace |
| **Blocker** | لا |
| **الإصلاح** | مسار devices scoped بالـ engagement **أو** توثيق «in-app only» للعامل. |
| **Laravel؟** | نعم إذا wanted |
| **Mobile contract؟** | نعم للعامل |

---

### HC-004 — Realtime latency (polling-only)

| Field | Detail |
| --- | --- |
| **Domain** | Conversations / Chat |
| **Screen/Flow** | Inbox list + open chat |
| **الوصف** | تحديث القائمة كل ~12s؛ الرسائل داخل المحادثة كل ~4s ما لم يرسل المستخدم أو يحدث refresh يدوي. |
| **Root cause** | `HybridRealtimeService` يمرّر streams من `PollingRealtimeService` فقط؛ `_loadRemoteConfig` لا يفعّل subscription. |
| **الطبقة** | Flutter + Integration |
| **دليل** | `apps/hasim/lib/realtime/hybrid_realtime_service.dart`, `realtime_service.dart` |
| **Blocker** | لا (مع push أو سحب يدوي) |
| **الإصلاح** | Echo client + channels أو تقليل interval + push (HC-001) |
| **Laravel؟** | ربما (broadcast config) |
| **Real device؟** | مفيد |

---

### HC-005 — Worker/Employee inbox visibility requires assignment

| Field | Detail |
| --- | --- |
| **Domain** | Inbox / Assignment |
| **Screen/Flow** | Company grants `inbox.messages.read` → Worker opens inbox → empty |
| **الوصف** | **ليس bug في Drift أو Flutter list** إذا API يرجع `[]`: المحادثات المفتوحة غالبًا `assigned_* = null`. |
| **Expected** | بعد منح الصلاحية **وتعيين** محادثة، يرى العامل/الموظف (non-member worker) المحادثة ويرسل. |
| **Actual** | بدون assign صريح تبقى القائمة فارغة (by design). |
| **Root cause** | `MobileInboxActor::constrain` للnon-member: `assigned_user_id` + `assigned_worker_engagement_id` + engagement ids. |
| **الطبقة** | Laravel (authorization) + Product UX |
| **دليل** | `app/Services/Mobile/MobileInboxActor.php`; `HASIM_CHAT_EMPLOYEE_INBOX_MESSAGES_DIAGNOSIS_FIX_2026-10-06.md` |
| **Blocker** | لا بعد إصلاح assign من `none` → `assigned` |
| **الإصلاح** | عملية تشغيل: assign من sheet؛ Flutter يرسل `worker_engagement_id` (مُنفَّذ). تحسين: empty state يوضح «لا محادثات **مسندة**». |
| **Laravel؟** | لا (سلوك مقصود) |
| **Real device؟** | UAT assign → send |

**إعادة التحقق B (Employee/Worker Inbox):**

- `ConversationHandoffService`: `'none' => ['requested', 'assigned']` — **موجود**.
- Mobile assign يقبل `worker_engagement_id` — **موجود** (`ConversationHandoffController`).
- Flutter assign sheet + repository — **موجود**.
- Laravel tests: `EmployeeInboxAssignmentTest`, `MobileWorkerInboxContractTest` — **ناجحة**.

---

### HC-006 — Product templates (optional merchant)

| Field | Detail |
| --- | --- |
| **Domain** | Catalog |
| **الوصف** | API templates موجود؛ لا شاشة Flutter. |
| **الطبقة** | Flutter |
| **Priority** | P2 (تسريع إنشاء منتج، ليس CRUD أساسي) |
| **Laravel؟** | لا |

---

### HC-007 — Email channel connect web-only

| Field | Detail |
| --- | --- |
| **Domain** | Channels |
| **الوصف** | قناة البريد في mobile list `web_only`؛ Flutter يعرض «إدارة عبر Web» عند `!canConnectInApp`. |
| **الطبقة** | Laravel + Flutter |
| **Blocker** | لا لباقي القنوات |
| **دليل** | `ChannelController` connectionMode |

---

## P3 — UX / Cleanup

### HC-008 — `inner_chrome_corner_test` failure

| Field | Detail |
| --- | --- |
| **Actual failure** | `Expected Size(4,4) Actual Size(5,4)` |
| **الطبقة** | Flutter test |
| **Blocker** | لا |
| **ملاحظة** | `app_config_test.dart` **نجح** في تشغيل suite 2026-10-06 (تقرير reconciliation القديم ذكر فشل HTTPS — **لم يُعاد** في التشغيل الحالي). |

---

### HC-009 — AI Core architecture boundary test

| Field | Detail |
| --- | --- |
| **Test** | `CoreArchitectureBoundaryTest::test_core_depends_only_on_itself_and_php` |
| **Failure** | `ContextPayload.php imports App\AI\Context\AiContext` |
| **الطبقة** | Laravel / AI (ديون معمارية، لا يؤثر على runtime inbox مباشرة) |
| **Blocker** | لا |

---

### HC-010 — AI skills picker unused

| Field | Detail |
| --- | --- |
| **Domain** | AI Employees |
| **الوصف** | `GET /ai/skills` غير مستخدم؛ presets + employee payload كافيان للمسار الرئيسي. |
| **Blocker** | لا |

---

## Known / Fixed (verified in current code)

| Topic | الحالة | دليل |
| --- | --- | --- |
| **A) Facebook Messenger loading** | **Fixed** | `channels_controller.dart` يصفّر `busyChannel` بعد connect/selectPage/callback/reload؛ `channels_connection_test`: «Facebook page selection refreshes and clears the busy state» |
| **A) «تعذر تحديث هذا الجزء»** | **Not Flutter** | النص من `resources/js/page-nav.js` (Hotwire partial reload على **Web**)؛ Flutter يستخدم رسائل مختلفة. إن ظهر في Web بعد OAuth، راجع callback Laravel/Meta. |
| **B) Assign من `handoff_status=none`** | **Fixed** | `ConversationHandoffService::TRANSITIONS`; tests 13 passed |
| **B) Assign worker عبر mobile** | **Fixed** | `worker_engagement_id` في assign + Flutter sheet |
| **C) AI thought/reasoning leak** | **Fixed** | `GeminiResponseNormalizer`, `includeThoughts: false`, `AiCustomerFacingText`, `shouldApplyAiSuggestion` |
| **C) AI hardening (context/grounding/composer)** | **Fixed** | `HASIM_CHAT_AI_HARDENING_FULL_REPAIR_2026-10-06.md`; targeted AI tests passed in that report |
| **D) Product type separation** | **No regression in tests** | `catalog_form_ui_test`: normal vs digital forms؛ payload في `chat_catalog_repository.dart` |
| **E) Local-First B–G** | **No new regression** | `local_first_phase_g_test` passed؛ outbox workspace scope in same run |
| **`can_connect_in_app` unused** | **False positive (قديم)** | `channels_screen.dart` يستخدم `canConnectInApp` للربط في التطبيق |

---

## Backend Contract Gaps

| ID | Gap | Impact | Flutter-only fix? |
| --- | --- | --- | --- |
| BC-001 | **Email attachments** — resource بدون `download_url` / filename | HC-002 | **لا** |
| BC-002 | **Worker device push** — لا route بديل عن `workspace.member` | HC-003 | **لا** |
| BC-003 | **Independent Worker registration** — `POST /auth/register` للشركة فقط؛ Worker `/workers/register` Web | لا app signup للعامل | **لا** — توثيق فقط |

---

## Infrastructure / Environment

| ID | Issue | Notes |
| --- | --- | --- |
| INF-001 | **FCM/APNs credentials & native SDK** | مطلوب لـ HC-001 |
| INF-002 | **Real device USB/ADB** | `HASIM_CHAT_PHONE_STARTUP_DIAGNOSIS_2026-10-05.md` — الجهاز بدون ADB interface؛ **ليس bug تطبيق** |
| INF-003 | **Live OAuth providers** | `HASIM_CHAT_NATIVE_CHANNEL_CONNECTION_2026-10-05.md` — Meta/TikTok/Telegram live OAuth **not verified** in CI; Laravel/Flutter contract tests **pass** |

---

## False Positives / Not Bugs

- **Dedicated worker inbox API** (`/worker/.../inbox`) غير مستخدم — **by design** (One Inbox).
- **Commerce cart/checkout في Flutter** — rollback مقصود (E في parity triage).
- **Branding GET unused** — Hasim-branded app.
- **Workspace member يرى كل محادثات workspace** — مقصود؛ التقييد للIndependent Worker non-member فقط.
- **Capability لا تمنح membership** — مقصود معماريًا.
- **عدم ظهور الهاتف في `flutter devices`** — بيئة USB، لا startup crash في التطبيق.

---

## Domain Notes (Flutter + Laravel audit)

### Authentication / Workspace / Account

- Company registration/login/logout/switch: mobile routes موجودة؛ local-first يمسح على logout (Phase G).
- Worker account: login mobile؛ registration **Web-only** (BC-003).

### Conversations / Chat / Messaging

- Send text: outbox `send_text` + idempotency؛ tests في local-first G.
- Handoff/classification/archive: server-confirmed (لا outbox) — كما في reconciliation.
- Polling errors في chat poll **تُبتلع** (`catch (_) {}`) — transient only؛ قد يؤخر HC-004.

### AI

- Suggest reply / auto reply / guard / facts: hardened؛ لا regression thought في الكود.
- `CoreArchitectureBoundaryTest`: HC-009 (test debt).

### Channels

- WhatsApp/Meta/Telegram/TikTok: mobile connect contract + tests 21 passed.
- Facebook busy state: fixed (see Known/Fixed).

### Products

- Normal/digital UX separated؛ tests green.
- Templates: HC-006 optional.

### Email

- Folder/list/detail/send: works for members + workers with `email.view` (`EnsureWorkspaceCapability` + `EnsureFeatureAccess`).
- Worker compose hidden (`canCompose` false for independent worker) — product choice.
- Attachments: HC-002.

### Notifications

- In-app list: local-first + API.
- Push: HC-001, HC-003.

### Worker capabilities

- Company manages via `company/worker-engagements/{id}/capabilities` — implemented 2026-10-06.
- Worker cannot self-grant — `workspace.member` on company routes.

### Workspace isolation

- `CrossWorkspaceIdorTest` passed.
- Drift tenancy tests (`inbox_store_tenancy_test`, phase G) passed in targeted runs.

### Drift / Database

- Schema v8؛ no duplicate table issue found in reconciliation.
- **No cleanup executed** in this audit (per instructions).

---

## Recommended Fix Order

1. **HC-001** — Push company (FCM/APNs + token source) — يفتح UAT للرسائل في الخلفية ويخفف HC-004.
2. **HC-002 / BC-001** — Email attachment contract ثم Flutter UI.
3. **HC-005** — Empty-state/copy تشغيلي + UAT assign→worker send (code path already fixed).
4. **HC-003 / BC-002** — قرار منتج: worker push أو in-app only.
5. **HC-004** — WebSocket subscription أو accept polling + push.
6. **HC-007** — إن طُلب email channel in-app: mobile contract.
7. **HC-006, HC-010** — optional product.
8. **HC-008, HC-009** — test hygiene.

---

## Checkpoint alignment

Independent Worker: **ليس** workspace member؛ Inbox واحد؛ assign + capabilities على engagement؛ Flutter لا يوسّع authorization. هذا التدقيق **لم يكسر** تلك القواعد ولم يقترح Worker membership أو inbox منفصل.

---

*End of audit. No Laravel, Flutter, or database files were modified during this phase.*
