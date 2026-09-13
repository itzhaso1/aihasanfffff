import 'package:drift/drift.dart';

import '../api/cashier_api.dart';
import '../device/device_registration_service.dart';
import '../local_db/app_database.dart';
import '../local_db/initial_sync_service.dart';
import '../local_db/workspace_scope.dart';
import '../pos/application/local_auth_service.dart';
import '../pos/pos_mode.dart';
import 'cloud_link_store.dart';

class CashierCloudLinkService {
  CashierCloudLinkService({
    required CashierApiClient api,
    required CloudLinkStore store,
    required DeviceRegistrationService devices,
    required AppDatabase db,
    required LocalAuthService localAuth,
    required Future<String> Function() deviceId,
    InitialSyncService? initialSync,
  }) : _api = api,
       _store = store,
       _devices = devices,
       _db = db,
       _localAuth = localAuth,
       _deviceId = deviceId,
       _initialSync = initialSync;

  final CashierApiClient _api;
  final CloudLinkStore _store;
  final DeviceRegistrationService _devices;
  final AppDatabase _db;
  final LocalAuthService _localAuth;
  final Future<String> Function() _deviceId;
  final InitialSyncService? _initialSync;

  Future<List<Map<String, dynamic>>> loadWorkspaces() async {
    final data = await _api.get('/workspaces');
    return parseWorkspaceList(data);
  }

  Future<CloudLinkSnapshot> bindWorkspace({
    required Map<String, dynamic> workspace,
    required String token,
    required Map<String, dynamic> user,
  }) async {
    if (!isPosEnabled(workspace)) {
      throw ApiException(
        'الكاشير غير متاح في باقتك الحالية',
        statusCode: 403,
      );
    }

    final workspaceId = requireId(workspace['id'], 'مساحة العمل غير متاحة.');

    var posEnabled = true;
    try {
      final switched = await _api.post(
        '/workspaces/switch',
        data: {
          'workspace_id': workspaceId,
          'device_name': 'كاشير حاسم',
          'device_type': 'cashier',
        },
      );
      if (switched.containsKey('pos_enabled')) {
        posEnabled = switched['pos_enabled'] == true;
      }
    } on ApiException {
      rethrow;
    }

    if (!posEnabled) {
      throw ApiException(
        'الكاشير غير متاح في باقتك الحالية',
        statusCode: 403,
      );
    }

    final deviceId = await _deviceId();
    if (deviceId.trim().isEmpty) {
      throw ApiException('معرّف الجهاز غير صالح.', statusCode: 422);
    }

    final registered = await _devices.register(deviceId: deviceId);
    final boundDeviceId = '${registered['device_id'] ?? deviceId}'.trim();
    if (boundDeviceId.isNotEmpty && boundDeviceId != deviceId) {
      throw ApiException(
        'معرّف الجهاز تغيّر بعد التسجيل. أوقف العملية.',
        statusCode: 409,
      );
    }

    final serverWorkspaceId = requireId(
      registered['workspace_id'] ?? workspaceId,
      'تعذر ربط الجهاز بمساحة العمل.',
    );
    final serverUserId = requireId(
      registered['user_id'] ?? registered['account_id'] ?? user['id'],
      'تعذر حفظ مستخدم الخادم.',
    );

    final snapshot = CloudLinkSnapshot(
      token: token,
      workspaceId: serverWorkspaceId,
      userId: serverUserId,
      deviceId: deviceId,
      posEnabled: true,
      deviceRegistered: true,
    );
    // Snapshot first. Saving the link before a failed catalog download left
    // the device "linked" on 900001 so sales never entered the push queue.
    final initialSync = _initialSync;
    if (initialSync != null) {
      await initialSync.run(
        serverWorkspaceId,
        deviceId: snapshot.deviceId,
      );
    }
    await _store.save(snapshot);
    await _persistLocalDevice(snapshot);
    await _markExistingStoreConnected();
    return snapshot;
  }

  /// Re-run the catalog snapshot when a previous bind saved a link without it.
  Future<bool> ensureCatalogSnapshot(CloudLinkSnapshot link) async {
    final workspaceId = link.workspaceId;
    if (workspaceId == null ||
        workspaceId <= 0 ||
        PosMode.isReservedStandaloneWorkspace(workspaceId)) {
      return false;
    }
    if (await _db.hasInitialSync(workspaceId)) return true;
    final initialSync = _initialSync;
    if (initialSync == null) return false;
    await initialSync.run(workspaceId, deviceId: link.deviceId);
    return _db.hasInitialSync(workspaceId);
  }

  /// PIN sessions stay on the local store; catalog/tables read the Laravel
  /// workspace only after a successful Phase 1B snapshot.
  static Future<int> catalogWorkspaceId({
    required int localStoreWorkspaceId,
    required CloudLinkSnapshot? link,
    required AppDatabase db,
  }) async {
    final cloudId = link?.workspaceId;
    if (link?.isLinked != true || cloudId == null || cloudId <= 0) {
      return localStoreWorkspaceId;
    }
    if (PosMode.isReservedStandaloneWorkspace(cloudId)) {
      return localStoreWorkspaceId;
    }
    if (await db.hasInitialSync(cloudId)) {
      return cloudId;
    }
    return localStoreWorkspaceId;
  }

  Future<CloudLinkSnapshot?> readLink() => _store.read();

  Future<void> _persistLocalDevice(CloudLinkSnapshot snapshot) async {
    final now = DateTime.now();
    await _db
        .into(_db.localDevices)
        .insertOnConflictUpdate(
          LocalDevicesCompanion.insert(
            deviceId: snapshot.deviceId!,
            accountId: Value(snapshot.userId),
            workspaceId: Value(snapshot.workspaceId),
            userId: Value(snapshot.userId),
            name: const Value('كاشير حاسم'),
            platform: const Value('cashier'),
            registeredAt: Value(now),
            lastSeenAt: Value(now),
          ),
        );
  }

  Future<void> _markExistingStoreConnected() async {
    final store = await _localAuth.anyStore();
    if (store == null) return;
    if (store.workspaceId != PosMode.standaloneWorkspaceId) return;
    if (store.connectedMode) return;
    await _localAuth.updateStore(
      storeId: store.localId,
      connectedMode: true,
    );
  }

  static List<Map<String, dynamic>> parseWorkspaceList(
    Map<String, dynamic> data,
  ) {
    final list = <Map<String, dynamic>>[];
    final raw = data['workspaces'];
    if (raw is! List) return list;
    for (final item in raw) {
      if (item is! Map) continue;
      final map = Map<String, dynamic>.from(item);
      if (map['workspace'] is Map) {
        final ws = Map<String, dynamic>.from(map['workspace'] as Map);
        list.add({
          ...ws,
          'pos_enabled': map['pos_enabled'] == true || ws['pos_enabled'] == true,
        });
      } else {
        list.add(map);
      }
    }
    return list;
  }

  static List<Map<String, dynamic>> posEnabledWorkspaces(
    List<Map<String, dynamic>> workspaces,
  ) {
    return [
      for (final workspace in workspaces)
        if (isPosEnabled(workspace)) workspace,
    ];
  }

  static bool isPosEnabled(Map<String, dynamic> workspace) =>
      workspace['pos_enabled'] == true;

  static int requireId(Object? raw, String message) {
    final id = raw is int ? raw : int.tryParse('$raw');
    if (id == null || id <= 0) {
      throw ApiException(message, statusCode: 422);
    }
    return id;
  }
}
