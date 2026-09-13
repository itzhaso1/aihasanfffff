# Production Readiness — كاشير حاسم

Branch: `cursor/hasim-cashier-app-757c`  
تاريخ: 2026-09-01 (جولة الإغلاق النهائية)

Laravel Web POS = Source of Truth للـ Business Logic والـ UX.  
Flutter = تطبيق كاشير مستقل يعكس نفس النظام بشكل أسرع وأنظف.

---

## Feature matrix

| Feature | Laravel Web | API `/api/cashier/v1` | Flutter | Offline | Tested |
|---------|-------------|------------------------|---------|---------|--------|
| Auth login/logout/me | ✅ | ✅ | ✅ | token cache | ✅ PHP |
| Bootstrap + permissions | ✅ | ✅ sound=`pos.new_order_sound` | ✅ | — | ✅ PHP |
| Catalog categories/items | ✅ | ✅ read + item show | ✅ denser grid | Hive cache | ✅ |
| Cart + order types طاولة/خارجي/توصيل | ✅ | POST /orders | ✅ table/customer/notes | queue | ✅ |
| Create order + idempotency | ✅ | client_reference | ✅ | Pending→Synced | ✅ PHP |
| Success dialog print optional | ✅ | invoice POST | ✅ invoice + real print attempt (no fake success) | — | ✅ UI |
| Tables board | ✅ | GET /tables | ✅ board + detail | online-only mutations | ✅ |
| Open/Close/Cancel session | ✅ | ✅ | ✅ + confirmation | blocked offline | ✅ |
| Transfer / Merge wizards | ✅ | ✅ | ✅ 3-step confirm | blocked offline | ✅ |
| Split bill (≥2 groups) | ✅ | ✅ min:2 aligned | ✅ remainder group | blocked offline | ✅ code |
| Session note / discount | ✅ | ✅ | ✅ dialogs | blocked offline | ✅ |
| Table update / QR regenerate | ✅ | ✅ PUT + regenerate | API ready | — | ✅ route |
| Menu orders + sound | ✅ poll | recent-menu + list | ✅ badge+wav+poll | — | ✅ |
| Kitchen statuses | ✅ new→ready | ✅ excludes invoiced | ✅ | — | ✅ PHP |
| Running orders + refund | ✅ | returns + mark_refunded | ✅ status/invoice/return | — | ✅ |
| Invoices list/show/edit | ✅ | enriched tax/payment | ✅ view+print arch | — | ✅ |
| Daily reports | ✅ rich | enriched parity fields | ✅ channels/paid/types | — | ✅ PHP |
| Customers | ✅ | GET/POST | ✅ + cart picker | — | ✅ |
| Permissions gating | Spatie | permissionMap | create/discount/tables/reports/refund | — | ✅ |
| Offline sync | n/a | Idempotency-Key | Pending/Syncing/Synced/Failed + stuck recovery | ✅ | ✅ |
| Realtime | Echo | channel name in bootstrap | Polling fallback; Pusher stub honest | — | ✅ |
| Printing | browser | n/a | ESC/POS + UnconfiguredGateway | — | BLOCKED hardware |
| Catalog items write | ✅ admin | ❌ intentionally | Empty state → use Web | — | BLOCKED |
| Shifts | ❌ | shifts_supported:false | hidden | — | MISSING (Laravel) |

---

## ✅ READY (مغلق بالكامل في الكود)

- Cashier layout RTL denser + cart hierarchy + order types الصحيحة  
- Categories sidebar/desktop + mobile chips  
- Tables lifecycle + wizards + note/discount + offline guard  
- Split bill يرسل مجموعتين ويغطي كل الكميات (متوافق مع PosOrderService)  
- Menu orders: polling + recent-menu + صوت حقيقي + badge  
- Kitchen filter يستبعد الطلبات المفوترة مثل الويب  
- Reports enriched + Flutter UI يعرض الحقول الحقيقية  
- Offline queue + stuck syncing recovery + client_reference  
- Permissions fail-closed لإنشاء الطلبات  
- طباعة: إنشاء فاتورة ثم محاولة طباعة حقيقية؛ فشل صريح بدون نجاح وهمي  
- لا لمس `apps/hasim` ولا `/api/mobile/v1`

## ⚠️ PARTIAL

- Pixel-perfect vs كل شاشات الويب (تدفق مطابق؛ تفاصيل بصرية قد تختلف قليلًا على التابلت)  
- Realtime websocket يحتاج credentials؛ Polling يعمل  
- Delivery: اختيار عميل موجود؛ نموذج عميل توصيل أعمق ما زال أخف من الويب  

## 🔴 BLOCKED (خارجي)

1. **إرسال طباعة حرارية فعلي** — يحتاج Native SDK (Bluetooth/USB/Network plugin مربوط بالجهاز)  
2. **Pusher/Reverb live** — يحتاج مفاتيح/بنية تحتية  
3. **كتابة كتالوج الأصناف من التطبيق** — لم يُفتح write surface إداري كامل عمدًا؛ الويب يبقى لإدارة الأصناف  

## ❌ MISSING

- **Shifts** — غير موجودة في Laravel أصلًا (`shifts_supported: false`)  
- Catalog CRUD من Flutter (انظر BLOCKED #3)

---

## Tests

```bash
php artisan test --filter=CashierApiV1Test
cd apps/hasim_cashier && flutter test
```

آخر نتائج هذه الجولة: CashierApiV1Test passed (3 tests / 53 assertions). Flutter analyze: infos فقط.

## الحكم الصريح

**ليس READY كاملًا على مستوى جهاز طباعة + WebSocket حي** — وهذا موثّق كـ BLOCKED.  

**لمنطق الكاشير التشغيلي (طلبات/طاولات/مطبخ/فواتير/تقارير/أوفلاين/صلاحيات/بدون Fake):** جاهز للإنتاج من جهة الكود والـ API بعد هذه الجولة، بشرط توفر اتصال الشبكة لعمليات الطاولات وربط طابعة لاحقًا عند الحاجة للطباعة الفعلية.
