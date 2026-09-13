# Table workspace UX + invoice-on-close only

## ما الذي كان ناقصًا
1. الضغط على الطاولة يغيّر Sidebar فقط — لا يدخل Workspace مستقل.
2. إضافة طلب لطاولة يفتح Dialog فاتورة/طباعة خطأً.
3. إغلاق الطاولة بدون مراجعة إجمالي / طريقة دفع / خيار طباعة بعد الإصدار.

## ما تم تغييره

### UX الطاولات
- `TablesBoard`: شبكة فقط؛ الضغط → `openTableWorkspace` → `TableDetailScreen` كاملة.
- داخل التفاصيل: معلومات، مدة الجلسة، طلبات، تعديل/حذف، ملاحظة، خصم، نقل، دمج، تقسيم، إغلاق، إلغاء.

### فاتورة فقط عند الإغلاق
- طلب طاولة من الكاشير: SnackBar نجاح + رجوع للتفاصيل — **بدون** Dialog فاتورة.
- `CloseTableFlow`: مراجعة → طريقة دفع → تأكيد → API close → Dialog طباعة/بدون فاتورة.

### API
- `POST .../sessions/{session}/close` يقبل `payment_method` اختياريًا ويحفظه على الطلبات + يحدّث payment_status عند الدفع الفوري.
- تفاصيل الطاولة تتضمن `subtotal` / `discount_amount` / `tax_amount` / `orders_count`.

## الملفات الرئيسية
- `apps/hasim_cashier/lib/features/tables/table_detail_screen.dart` (جديد)
- `apps/hasim_cashier/lib/features/tables/tables_board.dart` (إعادة كتابة)
- `apps/hasim_cashier/lib/features/tables/table_workspace.dart` (جديد)
- `apps/hasim_cashier/lib/features/home/shell_screen.dart` (checkout طاولة)
- `app/Http/Controllers/Api/Cashier/V1/TableController.php`
- `app/Services/Pos/PosOrderService.php`
