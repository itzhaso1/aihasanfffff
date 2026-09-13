# POS Local Core Testing

تاريخ: 2026-09-02

## أين الاختبارات

| ملف | ماذا يغطي |
|---|---|
| `test/pos_local_core_test.dart` | تسعير، بيع، ضريبة محفوظة، مخزون ذري، double-tap، مرتجع، مسودة بعد إعادة فتح DB، تسلسل أرقام، PIN، تقارير مبالغ، backup/restore، وردية، باركود، جاهزية المتجر الفارغ |
| `test/cart_controller_test.dart` | مجاميع السلة + قناة توصيل لا تتحول لخارجي |
| اختبارات sync الحالية | تبقى Connected-only ولا تُعاد كتابتها |

## أوامر التشغيل

من `apps/hasim_cashier`:

```bash
dart run build_runner build --delete-conflicting-outputs
dart format lib test
dart analyze lib test
flutter test test/pos_local_core_test.dart test/cart_controller_test.dart
flutter test
```

`flutter_test_config.dart` يضبط sqlite3 على Linux.

آخر تشغيل: `flutter test` في `apps/hasim_cashier` — **129 passed, 0 failed**.

## سيناريوهات القبول اليدوية (Standalone بدون شبكة)

1. إعداد متجر + مدير PIN
2. إضافة صنف ومخزون
3. فتح وردية
4. بيع نقد مع tendered/change
5. طباعة اختيارية (الفشل لا يلغي البيع)
6. مرتجع جزئي
7. تقارير يومية من SQLite
8. Backup ثم Restore
9. إغلاق وردية (المتوقع مقابل الفعلي)

## ما لم يُختبر آلياً هنا

- إرسال ESC/POS لجهاز شبكة حقيقي
- Bluetooth/USB native
- UI widget flows على شاشة فعلية
- SyncEngineV2 rewrite (مؤجّل P3)
- 10,000 منتج / 100,000 طلب كـ stress كامل (التقارير تستخدم فلترة يومية وليست SELECT * على الشاشة)
