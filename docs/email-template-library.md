# مكتبة قوالب البريد

مكتبة تصاميم مستقلة عن إرسال البريد الحالي. لا تغيّر `CentralEmailService` ولا قالب مساحة العمل الحي.

## أين ماذا؟

| الغرض | المكان |
| --- | --- |
| سجل التصنيفات والقوالب | `config/email_template_library.php` (يُحمَّل من `config/email_templates.php` تحت المفتاح `library`) |
| قراءة السجل في الكود | `App\Support\Email\EmailTemplateLibrary` |
| ملفات القوالب الجديدة | `resources/views/emails/library/{category}/{template}.blade.php` |
| قالب مساحة العمل الحالي | `resources/views/emails/workspace-branded.blade.php` |
| صور / أيقونات / CSS / JS | `public/email-library/{category}/{template}/images` أو `icons` أو `css` أو `js` — أنشئ المجلد فقط عند الحاجة |
| صورة المعاينة | `public/email-library/{category}/{template}/preview.png` ثم اترك مسار `preview` في السجل كما هو |
| قوالب النظام المركزية (ترحيب المنصة، الفاتورة…) | `resources/views/emails/templates/` + مفتاح `templates` في `config/email_templates.php` — لا تخلطها مع المكتبة |

## تسمية موحّدة

- التصنيف: `kebab-case` إنجليزي (`real-estate`, `buy-sell`)
- ملف القالب: `kebab-case` (`welcome.blade.php`, `order-confirmation.blade.php`)
- مفتاح السجل: `{category_underscored}_{template_underscored}` (`real_estate_welcome`)
- مسار الـ view: `emails.library.{category}.{template}` (`emails.library.real-estate.welcome`)
- الأصول: `public/email-library/{category}/{template}/...`

## كيف تضيف قالبًا جديدًا؟

1. أنشئ الملف:

```
resources/views/emails/library/construction/site-visit.blade.php
```

2. أضف المدخل داخل تصنيف `construction` في `config/email_template_library.php`:

```php
'site-visit' => [
    'name' => 'زيارة موقع',
    'name_en' => 'Site Visit',
    'description' => 'تأكيد زيارة موقع',
    'variables' => [
        'companyName',
        'customerName',
        'subject',
        'body',
        'ctaLabel',
        'ctaUrl',
        'logoUrl',
        'primaryColor',
        'secondaryColor',
        'backgroundColor',
        'textColor',
    ],
],
```

المفتاح والـ view ومسار المعاينة يُولَّدون تلقائيًا: `construction_site_visit` → `emails.library.construction.site-visit`.

3. اكتب التصميم داخل ملف الـ Blade فقط. لا تعدّل قوالب تصنيف آخر.

4. المتغيرات المتاحة هي ما كتبته في `variables`. استخدمها في الـ Blade مثل `{{ $companyName }}`.

## ألوان الهوية

قوالب `emails.library.*` تقرأ نفس ألوان صفحة «ألوان الهوية». لا يوجد نظام ألوان ثانٍ.

المصدر: صف `email_brandings` لمساحة العمل الحالية.

| في الصفحة | العمود | في Blade |
| --- | --- | --- |
| اللون الرئيسي | `primary_color` | `$primaryColor` |
| اللون الثانوي | `secondary_color` | `$secondaryColor` |
| لون الخلفية | `background_color` | `$backgroundColor` |
| لون النص | `text_color` | `$textColor` |

تصل إلى القالب عبر `EmailLibraryBrandingComposer` (مسجّل على `emails.library.*` فقط). الإرسال الحالي و`emails.workspace-branded` لا يستخدمان هذا الـ composer.

الاستخدام:

```blade
<body style="background: {{ $backgroundColor }}; color: {{ $textColor }};">
    <td style="background: {{ $primaryColor }};">هيدر</td>
    <td style="background: {{ $secondaryColor }};">زر</td>
</body>
```

عند إضافة قالب جديد في `resources/views/emails/library/...` تظهر الألوان تلقائيًا ما دام المسار تحت `emails.library`. أضف أسماء المتغيرات في السجل حتى تبقى واضحة، والقيم نفسها تُحقَن من الهوية حتى لو نسيت السجل.

القيم الافتراضية إن لم توجد هوية: `#0F172A` و`#2563EB` و`#F4F6FB` و`#111827` (نفس افتراضات `EmailBrandingHtmlBuilder`).

## كيف تضيف تصنيفًا جديدًا؟

1. أضف التصنيف في مصفوفة `$categories` داخل `config/email_template_library.php`.
2. أضف مجموعة قوالبه في `$catalog`.
3. أنشئ المجلد `resources/views/emails/library/{category}/` وضع ملفات الـ Blade.
4. لا حاجة لتغيير خدمة الإرسال.

## القالب الحالي

- المفتاح في المكتبة: `generic_workspace_branded`
- الحالة: `active`
- الـ view يبقى `emails.workspace-branded`
- `WorkspaceBrandedEmail` و`EmailBrandingHtmlBuilder` يستمران على نفس الملف ونفس المتغيرات

## واجهة العميل

- الصفحة: `/workspace/emails/templates` — `workspace.emails.templates.index`
- Controller: `App\Http\Controllers\Workspace\Email\EmailTemplateLibraryController`
- Views: `resources/views/workspace/emails/templates/`
- حفظ الاختيار: جدول `email_template_preferences` (`template_key` + `settings` JSON). لا يُحفظ HTML.

`draft` يظهر ويُعاين فقط. `active` يمكن اختياره وتخصيصه. الإرسال الحالي لا يستخدم هذا الاختيار بعد.

القوالب الجديدة حالتها `draft` حتى تجعل `status` = `active` في السجل. ظهور القالب في القائمة يتم بمجرد وجوده في السجل.
