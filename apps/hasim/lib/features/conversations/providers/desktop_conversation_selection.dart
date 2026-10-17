import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/prefs_store.dart';

/// المحادثة المعروضة في مساحة سطح المكتب — لا يغيّر منطق الموبايل (التنقل بالمسار).
final desktopSelectedConversationIdProvider = StateProvider<int?>((ref) => null);

/// لوحة معلومات العميل على سطح المكتب فقط. تُغلق عند إغلاق الـ Drawer وليس عند تبديل المحادثة.
final desktopCustomerDrawerOpenProvider = StateProvider<bool>((ref) => false);

/// ملاحظات العملاء — تُحفظ محليًا لكل مساحة عمل، وتُحدَّث جهة الاتصال المطابقة إن وُجدت.
class CustomerNotesController extends StateNotifier<Map<int, String>> {
  CustomerNotesController(this._prefs, this._workspaceId)
    : super(
        _workspaceId == null ? const {} : _prefs.customerNotes(_workspaceId),
      );

  final PrefsStore _prefs;
  final int? _workspaceId;

  void setNote(int customerId, String note) {
    state = {...state, customerId: note};
    final workspaceId = _workspaceId;
    if (workspaceId != null) {
      unawaited(_prefs.setCustomerNotes(workspaceId, state));
    }
  }
}

final desktopCustomerNotesProvider =
    StateNotifierProvider<CustomerNotesController, Map<int, String>>((ref) {
      return CustomerNotesController(
        ref.watch(prefsStoreProvider),
        ref.watch(activeWorkspaceIdProvider),
      );
    });

final desktopCustomerTagsProvider = StateProvider<Map<int, List<String>>>((ref) => {});
