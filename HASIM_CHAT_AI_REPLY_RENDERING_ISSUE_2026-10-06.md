# Hasim Chat — AI Reply Rendering Issue

**التاريخ:** 2026-10-06  
**النطاق:** مسار اقتراح الرد والرد الآلي من Laravel إلى Composer وBubble في تطبيق Hasim Chat، بدون تغيير معمارية الذكاء الاصطناعي وبدون إخفاء النصوص الإنجليزية بشكل عام.

## السبب الحقيقي

النص الداخلي مثل `Since the user asked` لم يكن قادمًا من Flutter ولا من system prompt ظاهر في الواجهة.

كان يصل لأن طبقة استخراج نص Gemini كانت تعامل **كل** أجزاء `candidates[0].content.parts` التي فيها `text` كنص زبائن، بما فيها أجزاء ملخص التفكير:

```json
{
  "thought": true,
  "text": "Since the user asked …"
}
```

توثيق Gemini يفرّق بين:

- جزء `thought: true` = ملخص تفكير داخلي
- جزء بدون `thought` = الرد النهائي الظاهر

الكود السابق كان يدمج الاثنين بـ `implode("\n", $chunks)` / `pluck('text')`.

لذلك:

1. إذا وُجد جزء تفكير + جزء رد، وصل الاثنان إلى `suggestion`.
2. إذا استُهلكت الميزانية على التفكير وبقي جزء التفكير فقط، وصل `Since the user asked` وحده إلى خانة الكتابة.

هذا يطابق العرض في الاختبار: النص الداخلي ظهر داخل منطقة كتابة/إرسال الرسالة.

## أين حدث الخلط

```
ChatScreen._suggest
  → ChatController.suggestReply
    → AiRepository.suggestReply
      → POST /api/mobile/v1/ai/suggest-reply
        → AiController::suggestReply
          → AIService::generateReply
            → HasimInboxRuntime
              → GeminiAdapter
                → GeminiResponseNormalizer::text()
```

العقد العام صحيح: `{ "success": true, "data": { "suggestion": "<string>", "meta": { "persisted": false } } }`.

الخلل ليس في اسم الحقل. الحقل `suggestion` كان يحمل **سلسلة ملوثة** لأن الاستخراج خلط الفكر مع الرد.

المسار نفسه يغذي الرد الآلي المحفوظ في الرسائل (`ProcessAIResponse` / `generateReply`)، لذلك نفس الخلط كان يمكن أن يظهر داخل فقاعة الرسالة.

مسار الاختبار `POST /ai/probe` كان يعاني نفس الدمج عبر `GoogleAiStudioProvider`.

## Flutter لم يكن المصدر، لكنه كان يمرّر التلوث كما هو

`AiRepository.suggestReply` كان يفعل:

```dart
map?['suggestion']?.toString()
```

ثم `ChatScreen._suggest` يضع الناتج في `_controller.text`.

الإرسال بعد الاقتراح يستخدم `_controller.text` فقط، ولا يخلط الرسالة مع الـ composer. لذلك إذا كانت `suggestion` ملوثة، يُرسل النص الملوث.

مشكلة ثانية في العميل: `toString()` على Map/List كان يمكن أن يفرّغ أسماء الحقول أو JSON الخام في الخانة لو وصل payload غير متوقع. هذا لم يكن سبب عبارة `Since the user asked` في الاختبار، لكنه مسار تسريب منفصل.

## مثال payload / field بدون أسرار

شكل الجزء المسبب (مختصر):

```json
{
  "candidates": [
    {
      "finishReason": "STOP",
      "content": {
        "parts": [
          { "thought": true, "text": "Since the user asked …" },
          { "text": "الرد النهائي الظاهر للعميل" }
        ]
      }
    }
  ]
}
```

قبل الإصلاح، قيمة العقد `data.suggestion` كانت دمج الجزئين.  
بعد الإصلاح، `data.suggestion` هي الجزء الثاني فقط. إذا لم يبقَ إلا جزء تفكير، الحقل يبقى فارغًا ويُرفض الاقتراح بدل عرضه.

لا يُطلب `includeThoughts`. الطلب يصرّح `includeThoughts: false` حتى لا تُعاد ملخصات التفكير أصلًا.

## الإصلاح

لم تُعدَّل الـ prompts، ولم يُحذف أي AI capability، ولم يُضف نظام AI جديد، ولم يُفلتر النص الإنجليزي عامة.

### Laravel — طبقة الاستجابة

- `GeminiResponseNormalizer` يتجاهل أجزاء `thought` وأجزاء الأداة/الكود عند بناء النص الظاهر.
- `GoogleAiStudioProvider` يفعل الشيء نفسه بدل `pluck('text')`.
- `OpenAiResponseNormalizer` يتجاهل أجزاء `reasoning` / `thought` إذا جاء `content` كمصفوفة أجزاء.
- `GeminiInteraction` يتجاهل خطوات/أجزاء التفكير.
- طلبات Gemini ترسل `thinkingConfig.includeThoughts = false`.

### Flutter — طبقة التحليل فقط

- `AiCustomerFacingText` يأخذ حقل العقد (`suggestion` / `reply` / `summary`) كنص نهائي.
- لا يحوّل Map/List إلى `toString()`.
- لا يأخذ `reasoning` / `thought` / `system` / `tool` / `debug`.
- JSON الناقص أو Structured output غير المتوقع لا يدخل الـ composer.
- `_suggest` لا يملأ الخانة إذا كان النص فارغًا.

## الملفات المعدلة

- `app/AI/Models/Mapping/GeminiResponseNormalizer.php`
- `app/AI/Models/Mapping/GeminiPayloadMapper.php`
- `app/AI/Models/Mapping/OpenAiResponseNormalizer.php`
- `app/Services/AI/Providers/GoogleAiStudioProvider.php`
- `app/Services/AI/Gemini/GeminiInteraction.php`
- `apps/hasim/lib/features/settings/data/ai_customer_facing_text.dart` (جديد)
- `apps/hasim/lib/features/settings/data/plan_channel_repository.dart`
- `apps/hasim/lib/features/settings/data/ai_setting_model.dart`
- `apps/hasim/lib/features/settings/data/ai_employee_model.dart`
- `apps/hasim/lib/features/conversations/presentation/chat_screen.dart`

## الاختبارات

Laravel:

- `tests/Unit/AI/Models/GeminiResponseNormalizerTest.php`
- `tests/Unit/AI/Models/OpenAiResponseNormalizerTest.php`
- `tests/Unit/AI/Models/GeminiAdapterTest.php`
- `tests/Unit/AI/GeminiInteractionParsingTest.php`
- `tests/Unit/AI/Providers/GoogleAiStudioToolMappingTest.php`
- `tests/Feature/Feature/Mobile/MobileAiSettingsTest.php` (قائم)

Flutter:

- `apps/hasim/test/ai_customer_facing_text_test.dart`
- `apps/hasim/test/ai_suggest_reply_repository_test.dart`
- `apps/hasim/test/chat_ui_test.dart`

حالات التغطية:

- الرد النهائي يظهر كنص ظاهر فقط.
- الاقتراح يظهر كنص مقترح فقط.
- لا يظهر system / developer / tool / reasoning.
- payload ناقص أو JSON تالف لا يدخل الـ composer.
- فشل AI يرمي خطأ ولا يُرجع بقايا الرد.
- تعديل الاقتراح ثم الإرسال يرسل نص الخانة فقط.
- فقاعة الرسالة لا تختلط مع الـ composer.
- لا يوجد دمج غير مقصود لأجزاء التفكير مع الرد.

## نتائج التشغيل

- `flutter analyze`: **No issues found**
- اختبارات AI/Chat المستهدفة: **36 passed**
- المجموعة الكاملة لـ Flutter: **501 passed، 2 failed**
  - الفشلان قائمان مسبقًا وليسا من هذا الإصلاح: `app_config_test.dart` و`inner_chrome_corner_test.dart`
- Laravel ذات الصلة: **29 passed**

تصحيح اختبار جانبي: `GeminiAdapterTest` كان يتوقع `http_error` على استجابة 429، بينما المحوّل يعيد `rate_limited`. أُبقي اختبار HTTP على حالة 500 حتى يفحص مسار الخطأ العام كما هو مكتوب.

## Regression

- لا تغيير في عقد المسار: ما زال `POST /ai/suggest-reply` يعيد `data.suggestion` كنص.
- لا persist من هذا المسار (`persist: false`).
- لا حساب ضرائب/فواتير في العميل.
- لا مسار AI جديد ولا حذف أدوات.

ما لم يُعالج عمدًا: إذا كتب النموذج استدلالًا داخل جزء الرد النهائي نفسه بدون علامة `thought`. ذلك ليس metadata من المزود، وإخفاؤه بعبارة إنجليزية محددة سيكون التفافًا على المشكلة وليس إصلاح الاستخراج.
