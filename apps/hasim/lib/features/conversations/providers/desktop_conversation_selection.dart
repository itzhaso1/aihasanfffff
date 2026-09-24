import 'package:flutter_riverpod/flutter_riverpod.dart';

/// المحادثة المعروضة في مساحة سطح المكتب — لا يغيّر منطق الموبايل (التنقل بالمسار).
final desktopSelectedConversationIdProvider = StateProvider<int?>((ref) => null);

/// لوحة معلومات العميل على سطح المكتب فقط. تُغلق عند إغلاق الـ Drawer وليس عند تبديل المحادثة.
final desktopCustomerDrawerOpenProvider = StateProvider<bool>((ref) => false);

/// ملاحظات/وسوم العرض المحلي للدرج — لا تغيّر الـ API. تُحدَّث إن وُجدت جهة اتصال مطابقة.
final desktopCustomerNotesProvider = StateProvider<Map<int, String>>((ref) => {});

final desktopCustomerTagsProvider = StateProvider<Map<int, List<String>>>((ref) => {});
