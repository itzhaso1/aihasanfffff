/// Arabic copy for Settings → مزامنة الآن.
///
/// Do not treat session/stock leftovers or parent waits as "Laravel is down".
class SyncNowCopy {
  const SyncNowCopy._();

  static String afterFlush({
    required int synced,
    required int failed,
    required int failedQueued,
    required int ready,
    required int waitingParent,
    required int leftovers,
    required bool authRequired,
    required String apiBase,
    String? lastError,
  }) {
    if (authRequired) {
      return 'الخادم رفض التوكن. أعد ربط السحابة من شاشة الدخول.';
    }
    if (failedQueued > 0 && waitingParent > 0 && ready == 0 && synced == 0) {
      final error = lastError?.trim() ?? '';
      final hint = error.isEmpty ? '' : ' السبب: $error';
      return 'ما زال $waitingParent بانتظار أب لأن $failedQueued عملية فشلت دائماً.$hint أعد محاولة الفاشل بعد إصلاح السبب.';
    }
    if (failed > 0) {
      return 'فشلت $failed · نجحت $synced · جاهز $ready · ينتظر أب $waitingParent.';
    }
    if (synced > 0 && ready == 0 && waitingParent == 0) {
      return 'تمت مزامنة $synced عملية.';
    }
    if (synced > 0) {
      return 'تمت مزامنة $synced. جاهز $ready · ينتظر أب $waitingParent.';
    }
    if (waitingParent > 0 && ready == 0) {
      return 'ما زال $waitingParent بانتظار عنصر أب (طلب/تصنيف/طاولة/عميل). ليست مشكلة اتصال Laravel.';
    }
    if (ready > 0) {
      final error = lastError?.trim() ?? '';
      if (error.isNotEmpty) {
        return 'ما زال $ready جاهزاً ولم يُقبل. $error';
      }
      return 'ما زال $ready فاتورة/منيو/طاولة جاهزة ولم تُقبل. تحقق من Laravel على $apiBase.';
    }
    if (leftovers > 0) {
      return 'لا يوجد جاهز للدفع. $leftovers عملية جلسة/مخزون/أخرى تبقى محلية.';
    }
    return 'لا توجد فواتير أو تغييرات منيو/طاولات بانتظار المزامنة.';
  }
}
