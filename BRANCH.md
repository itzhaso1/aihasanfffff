# الفرع الموحّد — اقرأ هذا أولًا

**استخدم فرعًا واحدًا فقط:**

```text
cursor/merchant-payments-plans-757c
```

PR: https://github.com/itzhaso1/ai-haso/pull/5

هذا الفرع يجمع كل ما طُلب في مسار تسليم واحد:

| المحتوى | أين |
|---------|-----|
| الخطط الرسمية + التحقق التجاري + فصل الأموال | Laravel + `docs/plans.md` + `docs/merchant-*.md` |
| Mobile API v1 `/api/mobile/v1` | Laravel + `docs/mobile-api.md` |
| تطبيق حاسم Flutter (عربي RTL) | `apps/hasim/` فقط |

## لا تستخدم هذه الفروع للعمل اليومي
كانت فروع عمل مؤقتة وأُدمجت هنا:

- `cursor/hasim-mobile-api-757c` (PR #6)
- `cursor/hasim-flutter-app-757c` (PR #7)

## تشغيل سريع
```bash
# Laravel
composer install && php artisan migrate && php artisan serve

# Flutter
cd apps/hasim
flutter pub get
flutter run --dart-define=API_BASE=http://10.0.2.2:8000
```
