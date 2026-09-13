/// Arabic copy for Kitchen sync status and the manual button.
class KitchenSyncCopy {
  const KitchenSyncCopy._();

  static const button = 'مزامنة الآن';
  static const syncing = 'جاري المزامنة...';
  static const done = 'تمت المزامنة';
  static const failed = 'تعذر المزامنة';
  static const offline =
      'لا يوجد اتصال بالإنترنت — تم الاحتفاظ بالبيانات محليًا.';
  static const connected = 'متصل';
  static const disconnected = 'غير متصل';

  static String lastSyncSeconds(int seconds) {
    final n = seconds < 0 ? 0 : seconds;
    return 'آخر مزامنة: قبل $n ثواني';
  }
}
