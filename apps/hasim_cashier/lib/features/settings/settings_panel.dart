import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/api/cashier_api.dart';
import '../../core/api/cashier_request_auth.dart';
import '../../core/audio/menu_sound_service.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/auth/cashier_cloud_link_service.dart';
import '../../core/auth/cloud_link_store.dart';
import '../../core/config/app_config.dart';
import '../../core/local_db/app_database.dart';
import '../../core/local_db/local_db_providers.dart';
import '../../core/navigation/pos_shell_nav.dart';
import '../../core/permissions/cashier_permissions.dart';
import '../../core/permissions/permissions_provider.dart';
import '../../core/pos/application/local_auth_service.dart';
import '../../core/pos/application/pos_providers.dart';
import '../../core/pos/domain/pricing_service.dart';
import '../../core/pos/pos_errors.dart';
import '../../core/pos/pos_mode.dart';
import '../../core/printing/printer_service.dart';
import '../../core/realtime/pos_event_source.dart';
import '../../core/repositories/sync_queue_repository.dart';
import '../../core/sync/pos_sync_coordinator.dart';
import '../../core/sync/sync_now_copy.dart';
import '../../core/sync/sync_queue_classifier.dart';
import '../../core/theme/hasim_colors.dart';
import '../../core/theme/hasim_radius.dart';
import '../../core/theme/hasim_spacing.dart';
import '../../core/util/json_numbers.dart';
import '../../core/widgets/hasim_top_notice.dart';
import '../../core/widgets/hasim_widgets.dart';
import '../cart/cart_controller.dart';

/// Cashier settings — POS settings via API + local printer/realtime.
class SettingsPanel extends ConsumerStatefulWidget {
  const SettingsPanel({super.key});

  @override
  ConsumerState<SettingsPanel> createState() => _SettingsPanelState();
}

class _SettingsPanelState extends ConsumerState<SettingsPanel> {
  var _sound = true;
  var _delivery = true;
  var _ready = false;
  var _savingPos = false;
  var _syncing = false;
  var _wiping = false;
  var _pendingSync = 0;
  var _failedSync = 0;
  var _unsupportedSync = 0;
  var _waitingParentSync = 0;
  String? _failedHint;
  final _tax = TextEditingController(text: '0');
  final _currency = TextEditingController(text: 'SAR');
  PrinterProfile? _profile;
  final _name = TextEditingController(text: 'طابعة الشبكة');
  final _address = TextEditingController();
  PrinterTransport _transport = PrinterTransport.network;
  List<LocalUser> _users = const [];
  String? _storeName;
  LocalShift? _currentOpenShift;

  Map<String, dynamic> get _perms => CashierPermissions.resolve(
    ref.read(cashierPermissionsProvider),
    ref.read(authControllerProvider).valueOrNull?.permissions,
  );

  bool get _canManagePos => CashierPermissions.canManageMenu(_perms);

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _tax.dispose();
    _currency.dispose();
    _name.dispose();
    _address.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final sound = ref.read(menuSoundServiceProvider);
    final printer = await ref.read(printerServiceFutureProvider.future);
    Map<String, dynamic>? settings;
    final store = await ref.read(localAuthServiceProvider).anyStore();
    if (store != null) {
      settings = {'tax_rate': store.taxRate, 'currency': store.currency};
    }
    if (!mounted) return;
    setState(() {
      _storeName = store?.name;
      if (settings != null) {
        _tax.text = asDoubleOr(settings['tax_rate']).toStringAsFixed(2);
        _currency.text = '${settings['currency'] ?? 'SAR'}';
        _sound =
            settings['sound_enabled'] == true ||
            settings['new_order_sound'] == true;
        _delivery = settings['enable_delivery'] != false;
        ref.read(menuSoundServiceProvider).setEnabled(_sound);
        ref
            .read(cartControllerProvider.notifier)
            .setTaxRate(asDoubleOr(settings['tax_rate']));
      } else {
        _sound = sound.enabled;
      }
      _profile = printer.selected;
      if (_profile != null) {
        _name.text = _profile!.name;
        _address.text = _profile!.address ?? '';
        _transport = _profile!.transport;
      }
      _ready = true;
    });
    await _refreshUsers();
    await _refreshOpenShift();
    unawaited(_hydrateCloudQuietly());
    await _refreshSyncStatus();
  }

  Future<void> _hydrateCloudQuietly() async {
    try {
      await ref.read(authControllerProvider.notifier).hydrateCloudLinkSession();
      if (mounted) await _refreshSyncStatus();
    } catch (_) {
      // Secure storage can stall in tests; in-memory link is enough to sync.
    }
  }

  Future<void> _refreshSyncStatus() async {
    final cloud = CashierRequestAuth.activeLink(
      ref.read(cloudLinkSessionProvider),
    );
    final workspaceId = CashierRequestAuth.workspaceId(
      sessionWorkspaceId: ref.read(workspaceIdProvider),
      cloud: cloud,
    );
    var pending = 0;
    var failed = 0;
    var unsupported = 0;
    var waitingParent = 0;
    String? failedHint;
    if (workspaceId != null && workspaceId > 0) {
      final classifier = SyncQueueClassifier(ref.read(appDatabaseProvider));
      final counts = await classifier.counts(workspaceId);
      pending = counts.scopedPending;
      failed = counts.failed;
      unsupported =
          counts.unsupported +
          counts.standalone +
          counts.blocked +
          counts.alreadyApplied;
      waitingParent = counts.waitingParent;
      failedHint = await classifier.firstFailedHint(workspaceId);
    }
    if (!mounted) return;
    setState(() {
      _pendingSync = pending;
      _failedSync = failed;
      _unsupportedSync = unsupported;
      _waitingParentSync = waitingParent;
      _failedHint = failedHint;
    });
  }

  void _showSyncMessage(String text) {
    if (!mounted) return;
    showHasimTopNotice(context, text);
  }

  Future<void> _syncNow() async {
    if (_syncing) return;
    setState(() => _syncing = true);
    try {
      try {
        await ref
            .read(authControllerProvider.notifier)
            .hydrateCloudLinkSession()
            .timeout(const Duration(milliseconds: 400));
      } catch (_) {
        // Secure storage can stall in tests; in-memory link is enough to decide.
      }
      var cloud = CashierRequestAuth.activeLink(
        ref.read(cloudLinkSessionProvider),
      );
      final sessionToken = ref.read(authControllerProvider).valueOrNull?.token;
      if (!CashierRequestAuth.canSync(
        sessionToken: sessionToken,
        cloud: cloud,
      )) {
        _showSyncMessage(
          'اربط الحساب السحابي أولاً من شاشة الدخول (وضع السحابة)، ثم ادخل بالـ PIN. تشغيل Laravel وحده لا يكفي.',
        );
        return;
      }
      if (cloud != null) {
        try {
          await ref
              .read(cashierCloudLinkServiceProvider)
              .ensureCatalogSnapshot(cloud);
          await ref
              .read(authControllerProvider.notifier)
              .hydrateCloudLinkSession();
          cloud = CashierRequestAuth.activeLink(
            ref.read(cloudLinkSessionProvider),
          );
          final store = await ref.read(localAuthServiceProvider).anyStore();
          final catalogId = await CashierCloudLinkService.catalogWorkspaceId(
            localStoreWorkspaceId:
                store?.workspaceId ?? PosMode.standaloneWorkspaceId,
            link: cloud,
            db: ref.read(appDatabaseProvider),
          );
          if (!PosMode.isReservedStandaloneWorkspace(catalogId)) {
            ref.read(workspaceIdProvider.notifier).state = catalogId;
          }
        } catch (e) {
          _showSyncMessage(
            e is ApiException
                ? 'تعذر تحميل كتالوج السحابة: ${e.message}'
                : 'تعذر تحميل كتالوج السحابة. تحقق من Laravel على ${AppConfig.apiBase}.',
          );
          return;
        }
      }
      final coordinator = ref.read(posSyncCoordinatorProvider);
      if (!coordinator.allowNetwork) {
        _showSyncMessage(
          'تعذر فتح مسار المزامنة. تحقق من ربط الجهاز وتوكن السحابة.',
        );
        return;
      }
      final workspaceId = CashierRequestAuth.workspaceId(
        sessionWorkspaceId: ref.read(workspaceIdProvider),
        cloud: cloud,
      );
      final deviceId =
          CashierRequestAuth.deviceId(
            sessionDeviceId: ref.read(deviceIdHeaderProvider),
            cloud: cloud,
          ) ??
          await ref.read(deviceIdentityProvider).getOrCreateDeviceId();
      if (workspaceId == null || workspaceId <= 0) {
        _showSyncMessage('لا توجد مساحة عمل للمزامنة.');
        return;
      }
      if (PosMode.isReservedStandaloneWorkspace(workspaceId)) {
        _showSyncMessage(
          'المساحة ما زالت محلية (900001). حمّل كتالوج السحابة بعد الربط حتى تدخل طلبات السفري الطابور.',
        );
        return;
      }
      await SyncQueueRepository(
        ref.read(appDatabaseProvider),
      ).clearPendingBackoff(workspaceId);
      final result = await coordinator.flushPendingOrders(
        workspaceId: workspaceId,
        deviceId: deviceId,
      );
      await _refreshSyncStatus();
      if (result.authRequired) {
        _showSyncMessage('الخادم رفض التوكن. أعد ربط السحابة من شاشة الدخول.');
        return;
      }
      final classifier = SyncQueueClassifier(ref.read(appDatabaseProvider));
      final counts = await classifier.counts(workspaceId);
      final leftovers =
          counts.unsupported +
          counts.standalone +
          counts.blocked +
          counts.alreadyApplied;
      final lastError =
          await classifier.firstReadyLastError(workspaceId) ??
          await classifier.firstFailedHint(workspaceId);
      _showSyncMessage(
        SyncNowCopy.afterFlush(
          synced: result.synced,
          failed: result.failed,
          failedQueued: counts.failed,
          ready: counts.ready,
          waitingParent: counts.waitingParent,
          leftovers: leftovers,
          authRequired: false,
          apiBase: AppConfig.apiBase,
          lastError: lastError,
        ),
      );
    } catch (e) {
      _showSyncMessage(e is PosException ? e.messageAr : 'تعذر المزامنة: $e');
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  Future<void> _retryFailedThenSync() async {
    if (_syncing) return;
    final cloud = CashierRequestAuth.activeLink(
      ref.read(cloudLinkSessionProvider),
    );
    final workspaceId = CashierRequestAuth.workspaceId(
      sessionWorkspaceId: ref.read(workspaceIdProvider),
      cloud: cloud,
    );
    if (workspaceId == null || workspaceId <= 0) {
      _showSyncMessage('لا توجد مساحة عمل للمزامنة.');
      return;
    }
    final n = await ref
        .read(syncQueueRepositoryProvider)
        .requeueInContractFailed(workspaceId);
    if (n == 0) {
      _showSyncMessage(
        'لا يوجد فشل فواتير/منيو/طاولات لإعادة المحاولة. الرقم $_failedSync غالباً جلسات خارج العقد وتبقى محلية.',
      );
      return;
    }
    await _syncNow();
  }

  Future<void> _refreshOpenShift() async {
    final workspaceId = ref.read(workspaceIdProvider);
    LocalShift? open;
    if (workspaceId != null) {
      try {
        open = await ref.read(shiftServiceProvider).currentOpen(workspaceId);
      } catch (_) {
        open = null;
      }
    }
    if (!mounted) return;
    setState(() => _currentOpenShift = open);
  }

  Future<void> _refreshUsers() async {
    final workspaceId = await ref
        .read(localAuthServiceProvider)
        .localUnlockWorkspaceId();
    if (workspaceId == null || workspaceId <= 0) return;
    try {
      final users = await ref
          .read(localAuthServiceProvider)
          .listUsers(workspaceId);
      if (!mounted) return;
      setState(() => _users = users);
    } catch (_) {
      if (!mounted) return;
      setState(() => _users = const []);
    }
  }

  Future<void> _savePosSettings() async {
    if (!_canManagePos) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('لا تملك صلاحية تحديث إعدادات الكاشير.')),
      );
      return;
    }
    final tax = double.tryParse(_tax.text.trim());
    if (tax == null || tax < 0 || tax > 100) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('نسبة الضريبة غير صالحة.')));
      return;
    }
    setState(() => _savingPos = true);
    try {
      final store = await ref.read(localAuthServiceProvider).anyStore();
      if (store != null) {
        await ref
            .read(localAuthServiceProvider)
            .updateStore(
              storeId: store.localId,
              taxRate: tax,
              currency: _currency.text.trim().toUpperCase(),
            );
      }
      await ref.read(menuSoundServiceProvider).setEnabled(_sound);
      ref.read(cartControllerProvider.notifier).setTaxRate(tax);
      if (!mounted) return;
      setState(() => _savingPos = false);
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('تم حفظ إعدادات المتجر محلياً.')),
      );
    } catch (e) {
      if (!mounted) return;
      setState(() => _savingPos = false);
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(e.toString())));
    }
  }

  Future<void> _savePrinter() async {
    final printer = await ref.read(printerServiceFutureProvider.future);
    final profile = PrinterProfile(
      id: _profile?.id ?? 'primary',
      name: _name.text.trim().isEmpty ? 'طابعة' : _name.text.trim(),
      transport: _transport,
      address: _address.text.trim().isEmpty ? null : _address.text.trim(),
    );
    await printer.saveProfile(profile);
    if (!mounted) return;
    setState(() => _profile = profile);
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('تم حفظ إعدادات الطابعة.')));
  }

  Future<void> _testPrint() async {
    final printer = await ref.read(printerServiceFutureProvider.future);
    final result = await printer.testPrint();
    if (!mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(SnackBar(content: Text(result.message)));
  }

  Future<void> _openShift() async {
    final workspaceId = ref.read(workspaceIdProvider);
    final userId = ref.read(currentLocalUserIdProvider) ?? 'local';
    if (workspaceId == null) return;
    final opening = TextEditingController(text: '0');
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('افتتاح الكاش'),
        content: TextField(
          controller: opening,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'النقد الافتتاحي'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('افتتاح'),
          ),
        ],
      ),
    );
    final openingCash = double.tryParse(opening.text) ?? 0;
    opening.dispose();
    if (ok != true) return;
    try {
      final id = await ref
          .read(shiftServiceProvider)
          .open(
            workspaceId: workspaceId,
            userId: userId,
            openingCash: openingCash,
            permissions: ref
                .read(authControllerProvider)
                .valueOrNull
                ?.permissions,
          );
      ref.read(currentShiftIdProvider.notifier).state = id;
      await _refreshOpenShift();
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تم افتتاح الكاش.')));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e is PosException ? e.messageAr : '$e')),
      );
    }
  }

  Future<void> _closeShift() async {
    final workspaceId = ref.read(workspaceIdProvider);
    var shiftId = ref.read(currentShiftIdProvider);
    if (workspaceId == null) return;
    shiftId ??= (await ref.read(shiftServiceProvider).currentOpen(workspaceId))
        ?.localId;
    if (shiftId == null) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('لا يوجد كاش مفتوح.')));
      return;
    }
    final actual = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('إغلاق الكاش'),
        content: TextField(
          controller: actual,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(labelText: 'النقد الفعلي'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('إغلاق'),
          ),
        ],
      ),
    );
    final actualCash = double.tryParse(actual.text) ?? 0;
    actual.dispose();
    if (ok != true) return;
    try {
      final result = await ref
          .read(shiftServiceProvider)
          .close(
            workspaceId: workspaceId,
            shiftId: shiftId,
            actualCash: actualCash,
            permissions: ref
                .read(authControllerProvider)
                .valueOrNull
                ?.permissions,
          );
      ref.read(currentShiftIdProvider.notifier).state = null;
      await _refreshOpenShift();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'المتوقع ${result['expected']} · الفعلي ${result['actual']} · الفرق ${result['difference']}',
          ),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e is PosException ? e.messageAr : '$e')),
      );
    }
  }

  Future<void> _addLocalTable() async {
    if (!CashierPermissions.canCreateTables(_perms)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('لا تملك صلاحية إضافة طاولات.')),
      );
      return;
    }
    final workspaceId = ref.read(workspaceIdProvider);
    if (workspaceId == null || workspaceId <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('لا توجد مساحة عمل. أنشئ متجراً محلياً أولاً.'),
        ),
      );
      return;
    }
    final name = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('طاولة جديدة'),
        content: TextField(
          controller: name,
          autofocus: true,
          decoration: const InputDecoration(labelText: 'اسم / رقم الطاولة'),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('حفظ'),
          ),
        ],
      ),
    );
    final trimmed = name.text.trim();
    name.dispose();
    if (ok != true || trimmed.isEmpty) return;
    try {
      await ref
          .read(catalogAdminServiceProvider)
          .createTable(
            workspaceId: workspaceId,
            name: trimmed,
            permissions: CashierPermissions.resolve(
              ref.read(cashierPermissionsProvider),
              ref.read(authControllerProvider).valueOrNull?.permissions,
            ),
          );
      ref.invalidate(localTablesProvider);
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تمت إضافة الطاولة.')));
    } catch (e) {
      if (!mounted) return;
      final message = e is PosException ? e.messageAr : '$e';
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('تعذر إضافة الطاولة: $message')));
    }
  }

  Future<String?> _askBackupPassword({required String title}) async {
    final password = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(title),
        content: TextField(
          controller: password,
          obscureText: true,
          decoration: const InputDecoration(
            labelText: 'كلمة مرور النسخة (6 أحرف على الأقل)',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('متابعة'),
          ),
        ],
      ),
    );
    final value = password.text;
    password.dispose();
    if (ok != true) return null;
    return value;
  }

  Future<void> _exportBackup() async {
    if (!CashierPermissions.canBackup(_perms)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('لا تملك صلاحية النسخ الاحتياطي.')),
      );
      return;
    }
    final workspaceId = ref.read(workspaceIdProvider);
    if (workspaceId == null) return;
    final password = await _askBackupPassword(title: 'تشفير النسخة الاحتياطية');
    if (password == null) return;
    try {
      final file = await ref
          .read(backupServiceProvider)
          .exportBackup(
            workspaceId: workspaceId,
            password: password,
            permissions: _perms,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('تم التصدير: ${file.path}')));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    }
  }

  Future<void> _restoreBackup() async {
    if (!CashierPermissions.canBackup(_perms)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('لا تملك صلاحية استعادة النسخة.')),
      );
      return;
    }
    final workspaceId = ref.read(workspaceIdProvider);
    if (workspaceId == null) return;
    final backups = await ref.read(backupServiceProvider).listBackups();
    if (backups.isEmpty) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('لا توجد نسخة احتياطية بعد. صدّر أولاً.')),
      );
      return;
    }
    final chosen = backups.first;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('استعادة النسخة؟'),
        content: Text(
          'سيتم أخذ نسخة أمان ثم الكتابة فوق البيانات من:\n${chosen.path}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('استعادة'),
          ),
        ],
      ),
    );
    if (ok != true) return;
    final password = await _askBackupPassword(title: 'كلمة مرور النسخة');
    if (password == null) return;
    try {
      await ref
          .read(backupServiceProvider)
          .exportBackup(
            workspaceId: workspaceId,
            password: password,
            permissions: _perms,
          );
      await ref
          .read(backupServiceProvider)
          .restoreFile(
            chosen,
            confirmed: true,
            password: password,
            permissions: _perms,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('تمت الاستعادة.')));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e is PosException ? e.messageAr : '$e')),
      );
    }
  }

  Future<void> _createStaffUser() async {
    if (!CashierPermissions.canManageUsers(_perms)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('لا تملك صلاحية إنشاء الحسابات.')),
      );
      return;
    }
    final workspaceId = await ref
        .read(localAuthServiceProvider)
        .localUnlockWorkspaceId();
    if (workspaceId == null || workspaceId <= 0) return;
    final name = TextEditingController();
    final username = TextEditingController();
    final pin = TextEditingController();
    var role = 'cashier';
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setLocal) => AlertDialog(
          title: const Text('حساب جديد'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: name,
                  autofocus: true,
                  decoration: const InputDecoration(labelText: 'الاسم'),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: username,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'الإيميل'),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: pin,
                  obscureText: true,
                  decoration: const InputDecoration(
                    labelText: 'كلمة المرور (4 أحرف على الأقل)',
                  ),
                ),
                const SizedBox(height: 8),
                DropdownButtonFormField<String>(
                  value: role,
                  decoration: const InputDecoration(labelText: 'الدور'),
                  items: const [
                    DropdownMenuItem(value: 'cashier', child: Text('كاشير')),
                    DropdownMenuItem(value: 'chef', child: Text('شيف')),
                  ],
                  onChanged: (v) {
                    if (v != null) setLocal(() => role = v);
                  },
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('إلغاء'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('إنشاء'),
            ),
          ],
        ),
      ),
    );
    final trimmedName = name.text.trim();
    final trimmedUser = username.text.trim();
    final trimmedPin = pin.text.trim();
    name.dispose();
    username.dispose();
    pin.dispose();
    if (ok != true) return;
    if (!mounted) return;
    if (trimmedName.isEmpty || trimmedUser.isEmpty) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('الاسم والإيميل مطلوبان.')));
      return;
    }
    try {
      await ref
          .read(localAuthServiceProvider)
          .createUser(
            workspaceId: workspaceId,
            name: trimmedName,
            username: trimmedUser,
            pin: trimmedPin,
            role: role == 'chef' ? 'chef' : 'cashier',
            actorPermissions: _perms,
          );
      await _refreshUsers();
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            role == 'chef'
                ? 'تم إنشاء حساب الشيف. يدخل بالإيميل وكلمة المرور إلى المطبخ.'
                : 'تم إنشاء حساب الكاشير.',
          ),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e is PosException ? e.messageAr : '$e')),
      );
    }
  }

  String _formatOpenedAt(DateTime at) {
    return DateFormat('yyyy/MM/dd  HH:mm').format(at.toLocal());
  }

  Future<int?> _wipeWorkspaceId() async {
    final cloud = CashierRequestAuth.activeLink(
      ref.read(cloudLinkSessionProvider),
    );
    final fromSession = CashierRequestAuth.workspaceId(
      sessionWorkspaceId: ref.read(workspaceIdProvider),
      cloud: cloud,
    );
    if (fromSession != null && fromSession > 0) return fromSession;
    return ref.read(localAuthServiceProvider).localUnlockWorkspaceId();
  }

  Future<void> _confirmLocalWipe({
    required String title,
    required String body,
    required Future<int> Function(int workspaceId) wipe,
    required void Function() onDone,
    required String doneLabel,
  }) async {
    if (_wiping) return;
    ScaffoldMessenger.of(context).hideCurrentSnackBar();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => Dialog(
        insetPadding: const EdgeInsets.symmetric(horizontal: 48, vertical: 24),
        backgroundColor: HasimColors.surface,
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 360),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(
                  title,
                  style: const TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 16,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  body,
                  style: const TextStyle(
                    fontSize: 13,
                    color: HasimColors.muted,
                    height: 1.45,
                  ),
                ),
                const SizedBox(height: 16),
                HsPrimaryButton(
                  label: 'حذف الكل',
                  onPressed: () => Navigator.pop(ctx, true),
                ),
                const SizedBox(height: 8),
                HsOutlineButton(
                  label: 'إلغاء',
                  onPressed: () => Navigator.pop(ctx, false),
                ),
              ],
            ),
          ),
        ),
      ),
    );
    if (ok != true) return;
    final workspaceId = await _wipeWorkspaceId();
    if (workspaceId == null || workspaceId <= 0) {
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('لا توجد مساحة عمل محلية.')));
      return;
    }
    setState(() => _wiping = true);
    try {
      final count = await wipe(workspaceId);
      onDone();
      await _refreshSyncStatus();
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text('$doneLabel ($count)')));
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(e is PosException ? e.messageAr : '$e')),
      );
    } finally {
      if (mounted) setState(() => _wiping = false);
    }
  }

  Future<void> _wipeAllInvoices() {
    return _confirmLocalWipe(
      title: 'حذف جميع الفواتير',
      body:
          'سيتم حذف كل الفواتير والمدفوعات والمرتجعات المرتبطة بها من هذا الجهاز فقط. الطلبات والحسابات والطاولات تبقى.',
      doneLabel: 'تم حذف الفواتير محلياً',
      wipe: (workspaceId) => ref
          .read(localDataWipeServiceProvider)
          .deleteAllInvoices(workspaceId: workspaceId, permissions: _perms),
      onDone: () {
        ref.read(invoicesRevisionProvider.notifier).state++;
      },
    );
  }

  Future<void> _wipeAllProducts() {
    return _confirmLocalWipe(
      title: 'حذف جميع الأصناف',
      body:
          'سيتم حذف كل الأصناف والتصنيفات من منيو هذا الجهاز. السلة الحالية تُفرَّغ. إن كان الجهاز مرتبطاً بالسحابة قد تعود الأصناف بعد المزامنة.',
      doneLabel: 'تم حذف الأصناف محلياً',
      wipe: (workspaceId) => ref
          .read(localDataWipeServiceProvider)
          .deleteAllProducts(workspaceId: workspaceId, permissions: _perms),
      onDone: () {
        ref.read(catalogRevisionProvider.notifier).state++;
        ref.invalidate(catalogItemsProvider);
        ref.invalidate(categoriesProvider);
        ref.read(cartControllerProvider.notifier).clear();
      },
    );
  }

  Future<void> _wipeAllOrders() {
    return _confirmLocalWipe(
      title: 'حذف جميع الطلبات',
      body:
          'سيتم حذف كل الطلبات المحلية واستعلامات المطبخ المرتبطة بها من هذا الجهاز. الفواتير تبقى إن وُجدت، والطاولات المشغولة بلا طلبات تُفتح.',
      doneLabel: 'تم حذف الطلبات محلياً',
      wipe: (workspaceId) => ref
          .read(localDataWipeServiceProvider)
          .deleteAllOrders(workspaceId: workspaceId, permissions: _perms),
      onDone: () {
        ref.read(ordersRevisionProvider.notifier).state++;
        ref.read(tablesRevisionProvider.notifier).state++;
      },
    );
  }

  Future<void> _wipeAllKitchenTickets() {
    return _confirmLocalWipe(
      title: 'حذف جميع استعلامات المطبخ',
      body:
          'سيتم حذف تذاكر المطبخ المحلية (الجديدة والمكتملة والملغاة) من هذا الجهاز. الطاولات بلا طلبات متبقية تُفتح.',
      doneLabel: 'تم حذف استعلامات المطبخ محلياً',
      wipe: (workspaceId) => ref
          .read(localDataWipeServiceProvider)
          .deleteAllKitchenTickets(
            workspaceId: workspaceId,
            permissions: _perms,
          ),
      onDone: () {
        ref.read(ordersRevisionProvider.notifier).state++;
        ref.read(tablesRevisionProvider.notifier).state++;
      },
    );
  }

  ({Color background, Color foreground}) _roleTone(String role) {
    if (LocalAuthService.isKitchenRole(role)) {
      return (
        background: HasimColors.warningSoft,
        foreground: HasimColors.warning,
      );
    }
    final value = role.trim().toLowerCase();
    if (value == 'admin' || value == 'manager') {
      return (
        background: HasimColors.brandSoft,
        foreground: HasimColors.brandDark,
      );
    }
    return (background: HasimColors.ctaSoft, foreground: HasimColors.ctaDark);
  }

  Widget _infoBanner({
    required IconData icon,
    required String text,
    Color background = HasimColors.brandSoft,
    Color foreground = HasimColors.brandDark,
  }) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: background,
        borderRadius: BorderRadius.circular(HasimRadius.md),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 16, color: foreground),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                fontSize: 12,
                height: 1.35,
                fontWeight: FontWeight.w600,
                color: foreground,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _headerCard() {
    final now = DateTime.now();
    const weekdays = [
      'الاثنين',
      'الثلاثاء',
      'الأربعاء',
      'الخميس',
      'الجمعة',
      'السبت',
      'الأحد',
    ];
    const months = [
      'يناير',
      'فبراير',
      'مارس',
      'أبريل',
      'مايو',
      'يونيو',
      'يوليو',
      'أغسطس',
      'سبتمبر',
      'أكتوبر',
      'نوفمبر',
      'ديسمبر',
    ];
    final dateLabel =
        '${weekdays[now.weekday - 1]} ${now.day} ${months[now.month - 1]} ${now.year}';
    final timeLabel =
        '${now.hour.toString().padLeft(2, '0')}:${now.minute.toString().padLeft(2, '0')}';
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= 960;
        final dateTexts = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              dateLabel,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                fontSize: 12,
                fontWeight: FontWeight.w700,
                color: HasimColors.ink,
              ),
            ),
            Text(
              timeLabel,
              style: const TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.w900,
                color: HasimColors.ink,
              ),
            ),
            if (_storeName != null && _storeName!.trim().isNotEmpty)
              Text(
                _storeName!.trim(),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(
                  fontSize: 11,
                  fontWeight: FontWeight.w600,
                  color: HasimColors.muted,
                ),
              ),
          ],
        );
        final dateCard = HsCard(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
          child: Row(
            mainAxisSize: wide ? MainAxisSize.min : MainAxisSize.max,
            children: [
              Container(
                width: 42,
                height: 42,
                decoration: BoxDecoration(
                  color: HasimColors.ctaSoft,
                  borderRadius: BorderRadius.circular(HasimRadius.md),
                ),
                alignment: Alignment.center,
                child: const Icon(
                  Icons.calendar_today_outlined,
                  color: HasimColors.ctaDark,
                  size: 18,
                ),
              ),
              const SizedBox(width: 10),
              if (wide) dateTexts else Flexible(child: dateTexts),
            ],
          ),
        );
        final welcome = HsWelcomeBanner(
          title: 'لوحة التحكم',
          subtitle: 'إدارة النظام والإعدادات العامة',
          badge: Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.16),
              borderRadius: BorderRadius.circular(HasimRadius.pill),
            ),
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.circle, size: 8, color: Colors.white),
                SizedBox(width: 6),
                Text(
                  'النظام يعمل بشكل طبيعي',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ],
            ),
          ),
        );
        if (!wide) {
          return Column(
            children: [welcome, const SizedBox(height: 12), dateCard],
          );
        }
        return Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            dateCard,
            const SizedBox(width: 12),
            Expanded(child: welcome),
          ],
        );
      },
    );
  }

  Widget _syncCard({required CloudLinkSnapshot? cloud}) {
    final linked = cloud != null;
    final workspaceId = CashierRequestAuth.workspaceId(
      sessionWorkspaceId: ref.watch(workspaceIdProvider),
      cloud: cloud,
    );
    final localWorkspace = PosMode.isReservedStandaloneWorkspace(
      workspaceId ?? 0,
    );
    return HsSectionCard(
      icon: Icons.cloud_sync_outlined,
      iconBackground: HasimColors.brandSoft,
      iconColor: HasimColors.brandDark,
      title: 'مزامنة السحابة',
      subtitle:
          'إرسال الفواتير وتغييرات المنيو وبيانات الطاولات الأساسية إلى حساب حاسم.',
      badge: _failedSync > 0
          ? HsStatusBadge(label: 'فشل $_failedSync', tone: HsStatusTone.danger)
          : _pendingSync > 0
          ? const HsStatusBadge(
              label: 'بانتظار المزامنة',
              tone: HsStatusTone.warning,
            )
          : linked
          ? const HsStatusBadge(label: 'مرتبط', tone: HsStatusTone.success)
          : const HsStatusBadge(label: 'غير متصل', tone: HsStatusTone.warning),
      highlight: true,
      children: [
        _infoBanner(
          icon: linked ? Icons.cloud_done_outlined : Icons.cloud_off_outlined,
          text: linked
              ? 'مرتبط بالسحابة · مساحة العمل ${cloud.workspaceId}'
              : 'غير مرتبط بالسحابة. تشغيل السيرفر وتطبيق فلاتر معاً لا يكفي بدون ربط الحساب.',
          background: Colors.white,
          foreground: linked ? HasimColors.ctaDark : HasimColors.warning,
        ),
        Text(
          'الخادم: ${AppConfig.apiBase}',
          style: const TextStyle(fontSize: 12, color: HasimColors.muted),
        ),
        Text(
          'بانتظار المزامنة: $_pendingSync'
          '${_waitingParentSync > 0 ? ' · منها $_waitingParentSync تنتظر عنصراً أب' : ''}',
          style: const TextStyle(fontSize: 12, color: HasimColors.muted),
        ),
        Text(
          'فشل دائم: $_failedSync · عمليات خارج العقد: $_unsupportedSync',
          style: const TextStyle(fontSize: 12, color: HasimColors.muted),
        ),
        if (_failedHint != null && _failedHint!.trim().isNotEmpty)
          _infoBanner(
            icon: Icons.error_outline,
            text: 'سبب الفشل: $_failedHint',
            background: Colors.white,
            foreground: HasimColors.warning,
          ),
        if (linked && localWorkspace)
          _infoBanner(
            icon: Icons.info_outline,
            text:
                'المنيو ما زال على المساحة المحلية 900001. حمّل كتالوج السحابة حتى تُصفّ الطلبات للمزامنة.',
            background: Colors.white,
            foreground: HasimColors.brandDark,
          ),
        HsPrimaryButton(
          label: _syncing ? 'جاري المزامنة…' : 'مزامنة الآن',
          icon: Icons.sync,
          loading: _syncing,
          onPressed: _syncing ? null : _syncNow,
        ),
        if (_failedSync > 0)
          HsOutlineButton(
            label: 'إعادة محاولة الفاشل',
            icon: Icons.replay,
            onPressed: _syncing ? null : _retryFailedThenSync,
          ),
      ],
    );
  }

  Widget _usersCard() {
    return HsSectionCard(
      icon: Icons.groups_outlined,
      iconBackground: HasimColors.brandSoft,
      iconColor: HasimColors.brandDark,
      title: 'حسابات الكاشير والشيف',
      subtitle:
          'فتح الجهاز محلياً فقط. أنشئ Admin / Cashier / Chef من منصة حاسم. لا يُنشأ حساب Laravel من هنا.',
      children: [
        if (_users.isEmpty)
          const Text(
            'لا يوجد مستخدمون بعد.',
            style: TextStyle(fontSize: 12, color: HasimColors.muted),
          )
        else
          for (final user in _users)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 2),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          user.name,
                          style: const TextStyle(fontWeight: FontWeight.w700),
                        ),
                        Text(
                          user.username,
                          style: const TextStyle(
                            fontSize: 12,
                            color: HasimColors.muted,
                          ),
                        ),
                      ],
                    ),
                  ),
                  HsBadge(
                    label: LocalAuthService.roleLabelAr(user.role),
                    background: _roleTone(user.role).background,
                    foreground: _roleTone(user.role).foreground,
                  ),
                ],
              ),
            ),
        HsOutlineButton(
          label: 'إدارة المستخدمين والصلاحيات',
          icon: Icons.manage_accounts_outlined,
          onPressed: () => requestPosShellTab(ref, PosShellTab.users),
        ),
        HsPrimaryButton(
          label: 'إنشاء حساب كاشير أو شيف',
          icon: Icons.person_add_alt_1_outlined,
          onPressed: _createStaffUser,
        ),
      ],
    );
  }

  Widget _cashCard() {
    final open = _currentOpenShift;
    final currency = _currency.text.trim().isEmpty
        ? 'SAR'
        : _currency.text.trim();
    return HsSectionCard(
      icon: Icons.point_of_sale_outlined,
      iconBackground: HasimColors.ctaSoft,
      iconColor: HasimColors.ctaDark,
      title: 'افتتاح الكاش',
      subtitle: 'يجب افتتاح الكاش قبل بدء البيع.',
      badge: open != null
          ? const HsStatusBadge(label: 'جاهز', tone: HsStatusTone.success)
          : const HsStatusBadge(
              label: 'بانتظار الافتتاح',
              tone: HsStatusTone.warning,
            ),
      highlight: true,
      children: [
        if (open != null) ...[
          _infoBanner(
            icon: Icons.check_circle_outline,
            text:
                'الكاش مفتوح · وقت الافتتاح ${_formatOpenedAt(open.openedAt)}'
                ' · نقد الافتتاح ${Money.fromCents(open.openingCash).toStringAsFixed(2)} $currency',
            background: Colors.white,
            foreground: HasimColors.ctaDark,
          ),
        ] else
          _infoBanner(
            icon: Icons.info_outline,
            text: 'افتتاح الكاش مطلوب قبل بدء البيع على هذا الجهاز.',
            background: Colors.white,
            foreground: HasimColors.brandDark,
          ),
        HsPrimaryButton(
          label: 'افتتاح الكاش',
          icon: Icons.lock_open_outlined,
          onPressed: _openShift,
        ),
        HsOutlineButton(
          label: 'إغلاق الكاش',
          icon: Icons.lock_outline,
          onPressed: _closeShift,
        ),
      ],
    );
  }

  Widget _posSettingsCard({required bool canManage}) {
    return HsSectionCard(
      icon: Icons.tune,
      iconBackground: HasimColors.warningSoft,
      iconColor: HasimColors.warning,
      title: 'إعدادات الكاشير المحلية',
      subtitle: canManage
          ? 'تُحفظ محلياً على هذا الجهاز (بدون خادم)'
          : 'عرض فقط — تحتاج menu.manage للتعديل',
      children: [
        Row(
          children: [
            Expanded(
              child: TextField(
                controller: _tax,
                enabled: canManage && _ready && !_savingPos,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: 'نسبة الضريبة %',
                  isDense: true,
                ),
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: TextField(
                controller: _currency,
                enabled: canManage && _ready && !_savingPos,
                decoration: const InputDecoration(
                  labelText: 'العملة',
                  isDense: true,
                ),
              ),
            ),
          ],
        ),
        HsToggleRow(
          label: 'صوت الطلبات الجديدة (المنيو والمطبخ)',
          value: _sound,
          onChanged: (!canManage || !_ready || _savingPos)
              ? null
              : (v) => setState(() => _sound = v),
        ),
        HsToggleRow(
          label: 'تفعيل التوصيل',
          value: _delivery,
          onChanged: (!canManage || !_ready || _savingPos)
              ? null
              : (v) => setState(() => _delivery = v),
        ),
        if (canManage)
          HsPrimaryButton(
            label: _savingPos ? 'جاري الحفظ…' : 'حفظ إعدادات الكاشير',
            icon: Icons.save_outlined,
            onPressed: (!_ready || _savingPos) ? null : _savePosSettings,
          ),
      ],
    );
  }

  Widget _stationsCard({
    required bool canViewReports,
    required bool canUseKitchen,
  }) {
    return HsSectionCard(
      icon: Icons.assessment_outlined,
      iconBackground: HasimColors.brandSoft,
      iconColor: HasimColors.brandDark,
      title: 'التقارير والمطبخ',
      subtitle:
          'التقارير والمطبخ صفحتان مستقلتان. صلاحية الدخول تُحدد لكل مستخدم من تبويب المستخدمون.',
      children: [
        if (canViewReports)
          HsOutlineButton(
            label: 'فتح التقارير',
            icon: Icons.bar_chart_outlined,
            onPressed: () {
              final router = GoRouter.maybeOf(context);
              if (router != null) {
                context.go('/reports');
              } else {
                requestPosShellTab(ref, PosShellTab.reports);
              }
            },
          ),
        if (canUseKitchen)
          HsOutlineButton(
            label: 'فتح المطبخ',
            icon: Icons.soup_kitchen_outlined,
            onPressed: () {
              final router = GoRouter.maybeOf(context);
              if (router != null) {
                context.go('/kitchen');
              } else {
                requestPosShellTab(ref, PosShellTab.kitchen);
              }
            },
          ),
      ],
    );
  }

  Widget _backupCard() {
    return HsSectionCard(
      icon: Icons.sd_storage_outlined,
      iconBackground: HasimColors.ctaSoft,
      iconColor: HasimColors.ctaDark,
      title: 'نسخة احتياطية محلية',
      subtitle: 'تصدير واستعادة بيانات هذا الجهاز.',
      children: [
        HsPrimaryButton(
          label: 'تصدير Backup',
          icon: Icons.file_upload_outlined,
          onPressed: _exportBackup,
        ),
        HsOutlineButton(
          label: 'استعادة آخر نسخة',
          icon: Icons.file_download_outlined,
          onPressed: _restoreBackup,
        ),
      ],
    );
  }

  Widget _dataWipeCard({
    required bool canWipeInvoices,
    required bool canWipeProducts,
    required bool canWipeOrders,
    required bool canWipeKitchen,
  }) {
    return HsSectionCard(
      icon: Icons.delete_sweep_outlined,
      iconBackground: HasimColors.dangerSoft,
      iconColor: HasimColors.danger,
      title: 'تنظيف البيانات المحلية',
      subtitle:
          'حذف تشغيلي على هذا الجهاز فقط. الحسابات والطاولات والإعدادات تبقى.',
      children: [
        if (canWipeInvoices)
          HsOutlineButton(
            label: 'حذف جميع الفواتير',
            icon: Icons.receipt_long_outlined,
            foreground: HasimColors.danger,
            borderColor: HasimColors.danger,
            onPressed: _wiping ? null : _wipeAllInvoices,
          ),
        if (canWipeProducts)
          HsOutlineButton(
            label: 'حذف جميع الأصناف',
            icon: Icons.inventory_2_outlined,
            foreground: HasimColors.danger,
            borderColor: HasimColors.danger,
            onPressed: _wiping ? null : _wipeAllProducts,
          ),
        if (canWipeOrders)
          HsOutlineButton(
            label: 'حذف جميع الطلبات',
            icon: Icons.shopping_bag_outlined,
            foreground: HasimColors.danger,
            borderColor: HasimColors.danger,
            onPressed: _wiping ? null : _wipeAllOrders,
          ),
        if (canWipeKitchen)
          HsOutlineButton(
            label: 'حذف جميع استعلامات المطبخ',
            icon: Icons.soup_kitchen_outlined,
            foreground: HasimColors.danger,
            borderColor: HasimColors.danger,
            onPressed: _wiping ? null : _wipeAllKitchenTickets,
          ),
      ],
    );
  }

  Widget _tablesCard({required bool canCreate}) {
    return HsSectionCard(
      icon: Icons.table_restaurant_outlined,
      iconBackground: HasimColors.ctaSoft,
      iconColor: HasimColors.ctaDark,
      title: 'الطاولات',
      subtitle: 'إدارة الطاولات المحلية وإضافة طاولة لهذا الجهاز.',
      children: [
        HsPrimaryButton(
          label: 'فتح الطاولات',
          icon: Icons.grid_view_outlined,
          onPressed: () => requestPosShellTab(ref, PosShellTab.tables),
        ),
        if (canCreate)
          HsOutlineButton(
            label: 'إضافة طاولة محلية',
            icon: Icons.add,
            onPressed: _addLocalTable,
          ),
      ],
    );
  }

  Widget _soundCard() {
    return HsSectionCard(
      icon: Icons.volume_up_outlined,
      iconBackground: HasimColors.warningSoft,
      iconColor: HasimColors.warning,
      title: 'اختبار الصوت',
      subtitle: 'تشغيل عينة صوت طلب جديد.',
      children: [
        HsOutlineButton(
          label: 'تشغيل عينة',
          icon: Icons.play_arrow_rounded,
          onPressed: () => ref.read(menuSoundServiceProvider).playNewOrder(),
        ),
      ],
    );
  }

  Widget _realtimeCard() {
    return HsSectionCard(
      icon: Icons.wifi_tethering,
      iconBackground: HasimColors.brandSoft,
      iconColor: HasimColors.brandDark,
      title: 'Realtime',
      subtitle:
          'Polling هو المصدر الافتراضي. Pusher/Reverb لن يُفعَّل بدون credentials.',
      badge: const HsStatusBadge(label: 'جاهز', tone: HsStatusTone.success),
      children: [
        Text(
          'الوضع الحالي: ${ref.watch(posRealtimeModeProvider)}',
          style: const TextStyle(fontSize: 12, color: HasimColors.muted),
        ),
      ],
    );
  }

  Widget _printerCard() {
    return HsSectionCard(
      icon: Icons.print_outlined,
      iconBackground: HasimColors.ctaSoft,
      iconColor: HasimColors.ctaDark,
      title: 'إعدادات الطابعة (ESC/POS)',
      subtitle: _profile == null
          ? 'غير مُعدّة'
          : (_profile!.address == null || _profile!.address!.isEmpty
                ? 'محفوظة بدون عنوان (غير متصلة)'
                : 'عنوان محفوظ — الإرسال يحتاج بوابة Native حقيقية'),
      children: [
        TextField(
          controller: _name,
          decoration: const InputDecoration(
            labelText: 'اسم الطابعة',
            isDense: true,
          ),
        ),
        DropdownButtonFormField<PrinterTransport>(
          value: _transport,
          decoration: const InputDecoration(
            labelText: 'نوع الاتصال',
            isDense: true,
          ),
          items: const [
            DropdownMenuItem(
              value: PrinterTransport.network,
              child: Text('Network'),
            ),
            DropdownMenuItem(
              value: PrinterTransport.bluetooth,
              child: Text('Bluetooth'),
            ),
            DropdownMenuItem(value: PrinterTransport.usb, child: Text('USB')),
            DropdownMenuItem(
              value: PrinterTransport.system,
              child: Text('System'),
            ),
          ],
          onChanged: (v) {
            if (v != null) setState(() => _transport = v);
          },
        ),
        TextField(
          controller: _address,
          decoration: const InputDecoration(
            labelText: 'العنوان (IP / MAC / USB path)',
            isDense: true,
            hintText: 'مثال: 192.168.1.50',
          ),
        ),
        Row(
          children: [
            Expanded(
              child: HsPrimaryButton(
                label: 'حفظ الطابعة',
                onPressed: _savePrinter,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: HsOutlineButton(
                label: 'Test Print',
                onPressed: _testPrint,
              ),
            ),
          ],
        ),
        const Text(
          'طابعة الشبكة ترسل ESC/POS عبر TCP:9100. Bluetooth/USB يحتاج Native لاحقاً. فشل الطباعة لا يلغي البيع.',
          style: TextStyle(fontSize: 11, color: HasimColors.muted),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    final canManage = CashierPermissions.canManageMenu(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final canManageUsers = CashierPermissions.canManageUsers(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final canViewTables = CashierPermissions.canViewTables(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final canCreateTables = CashierPermissions.canCreateTables(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final canViewReports = CashierPermissions.canViewReports(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final canUseKitchen = CashierPermissions.canUseKitchen(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final canWipeInvoices = CashierPermissions.canDeleteInvoices(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final canWipeProducts = canManage;
    final canWipeOrders = CashierPermissions.canWipeOrders(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final canWipeKitchen = CashierPermissions.canWipeKitchen(
      CashierPermissions.resolve(
        ref.watch(cashierPermissionsProvider),
        ref.watch(authControllerProvider).valueOrNull?.permissions,
      ),
    );
    final showDataWipe =
        canWipeInvoices || canWipeProducts || canWipeOrders || canWipeKitchen;
    final cloud = CashierRequestAuth.activeLink(
      ref.watch(cloudLinkSessionProvider),
    );

    return ListView(
      padding: const EdgeInsets.all(HasimSpacing.lg),
      children: [
        _headerCard(),
        const SizedBox(height: HasimSpacing.md),
        HsSoftGrid(
          minTileWidth: 320,
          maxColumns: 3,
          spacing: 16,
          children: [
            _syncCard(cloud: cloud),
            if (canManageUsers) _usersCard(),
            _cashCard(),
            _posSettingsCard(canManage: canManage),
            if (canViewReports || canUseKitchen)
              _stationsCard(
                canViewReports: canViewReports,
                canUseKitchen: canUseKitchen,
              ),
            _backupCard(),
            if (showDataWipe)
              _dataWipeCard(
                canWipeInvoices: canWipeInvoices,
                canWipeProducts: canWipeProducts,
                canWipeOrders: canWipeOrders,
                canWipeKitchen: canWipeKitchen,
              ),
            _soundCard(),
            if (canViewTables || canCreateTables)
              _tablesCard(canCreate: canCreateTables),
            _realtimeCard(),
            _printerCard(),
          ],
        ),
      ],
    );
  }
}
