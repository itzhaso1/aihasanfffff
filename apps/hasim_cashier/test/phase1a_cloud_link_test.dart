import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/api/cashier_api.dart';
import 'package:hasim_cashier/core/api/cashier_network_policy.dart';
import 'package:hasim_cashier/core/auth/cashier_cloud_link_service.dart';
import 'package:hasim_cashier/core/auth/cloud_link_store.dart';
import 'package:hasim_cashier/core/config/app_config.dart';
import 'package:hasim_cashier/core/device/device_registration_service.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/initial_sync_service.dart';
import 'package:hasim_cashier/core/local_db/workspace_scope.dart';
import 'package:hasim_cashier/core/pos/application/checkout_service.dart';
import 'package:hasim_cashier/core/pos/application/document_numbers.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/application/stock_engine.dart';
import 'package:hasim_cashier/core/pos/pos_mode.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';

void main() {
  late AppDatabase db;
  late LocalAuthService localAuth;
  late CloudLinkStore store;
  late Map<String, String> memory;

  setUp(() {
    db = AppDatabase.memory();
    localAuth = LocalAuthService(db);
    memory = <String, String>{};
    store = CloudLinkStore.memory(memory);
  });

  tearDown(() async {
    await db.close();
  });

  CashierCloudLinkService serviceFor(
    Dio dio, {
    String deviceId = 'device-fixed-uuid',
    InitialSyncService? initialSync,
  }) {
    final api = CashierApiClient(dio);
    return CashierCloudLinkService(
      api: api,
      store: store,
      devices: DeviceRegistrationService(api),
      db: db,
      localAuth: localAuth,
      deviceId: () async => deviceId,
      initialSync: initialSync,
    );
  }

  Dio fakeDio(_FakeCashierBackend backend) {
    final dio = Dio(
      BaseOptions(
        baseUrl: 'http://cashier.test/api/cashier/v1',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      ),
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          backend.handle(options, handler);
        },
      ),
    );
    return dio;
  }

  test('offline-only policy allows Phase 1A setup paths only', () {
    expect(AppConfig.offlineOnly, isTrue);
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: null,
        path: '/auth/login',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/workspaces',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/devices/register',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/sync/push',
        method: 'POST',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/catalog/items',
        method: 'GET',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/catalog/items',
        method: 'POST',
      ),
      isFalse,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/bootstrap',
        method: 'GET',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/tables',
        method: 'GET',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/sync/pull',
        method: 'POST',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/orders',
        method: 'POST',
      ),
      isFalse,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-token',
        path: '/auth/social',
        method: 'POST',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'standalone:1',
        path: '/auth/login',
      ),
      isFalse,
    );
  });

  test('A online login payload exposes POS workspaces', () {
    final parsed = CashierCloudLinkService.parseWorkspaceList({
      'workspaces': [
        {
          'id': 12,
          'name': 'المتجر',
          'pos_enabled': true,
        },
        {
          'id': 15,
          'name': 'فرع مغلق',
          'pos_enabled': false,
        },
      ],
    });
    expect(parsed, hasLength(2));
    expect(
      CashierCloudLinkService.posEnabledWorkspaces(parsed),
      hasLength(1),
    );
    expect(CashierCloudLinkService.isPosEnabled(parsed.first), isTrue);
    expect(CashierCloudLinkService.isPosEnabled(parsed.last), isFalse);
  });

  test('B invalid credentials stay an auth error', () async {
    final backend = _FakeCashierBackend()
      ..loginStatus = 401
      ..loginMessage = 'بيانات الدخول غير صحيحة.';
    final api = CashierApiClient(fakeDio(backend));
    expect(
      () => api.post(
        '/auth/login',
        data: {
          'email_or_phone': 'a@b.com',
          'password': 'bad',
        },
      ),
      throwsA(
        isA<ApiException>()
            .having((e) => e.statusCode, 'status', 401)
            .having((e) => e.message, 'message', 'بيانات الدخول غير صحيحة.'),
      ),
    );
  });

  test('C POS disabled does not register the device', () async {
    await localAuth.bootstrapStore(
      storeName: 'محلي',
      adminName: 'مدير',
      username: 'admin',
      pin: '1234',
    );
    final backend = _FakeCashierBackend()
      ..workspaces = [
        {'id': 44, 'name': 'بدون كاشير', 'pos_enabled': false},
      ];
    final service = serviceFor(fakeDio(backend));
    expect(
      () => service.bindWorkspace(
        workspace: backend.workspaces.first,
        token: 'token-1',
        user: {'id': 9},
      ),
      throwsA(
        isA<ApiException>().having((e) => e.statusCode, 'status', 403),
      ),
    );
    expect(await store.read(), isNull);
    expect(backend.registerCalls, 0);
    final local = await db.select(db.localDevices).get();
    expect(local, isEmpty);
  });

  test('D workspace selection uses POS-enabled workspace only', () {
    final workspaces = CashierCloudLinkService.parseWorkspaceList({
      'workspaces': [
        {
          'workspace': {'id': 1, 'name': 'A'},
          'pos_enabled': false,
        },
        {
          'workspace': {'id': 2, 'name': 'B'},
          'pos_enabled': true,
        },
      ],
    });
    final pos = CashierCloudLinkService.posEnabledWorkspaces(workspaces);
    expect(pos.single['id'], 2);
    expect(pos.single['name'], 'B');
  });

  test('E F device registration is idempotent and keeps device id', () async {
    await localAuth.bootstrapStore(
      storeName: 'محلي',
      adminName: 'مدير',
      username: 'admin',
      pin: '1234',
    );
    final backend = _FakeCashierBackend()
      ..workspaces = [
        {'id': 88, 'name': 'POS', 'pos_enabled': true},
      ];
    const deviceId = 'existing-flutter-device-id';
    final service = serviceFor(fakeDio(backend), deviceId: deviceId);

    final first = await service.bindWorkspace(
      workspace: backend.workspaces.first,
      token: 'token-1',
      user: {'id': 3},
    );
    final second = await service.bindWorkspace(
      workspace: backend.workspaces.first,
      token: 'token-1',
      user: {'id': 3},
    );

    expect(first.deviceId, deviceId);
    expect(second.deviceId, deviceId);
    expect(first.workspaceId, 88);
    expect(first.userId, 3);
    expect(first.isLinked, isTrue);
    expect(backend.registerCalls, 2);
    expect(backend.registeredIds.toSet(), {deviceId});

    final saved = await store.read();
    expect(saved?.deviceId, deviceId);
    expect(saved?.workspaceId, 88);
    expect(saved?.isLinked, isTrue);

    final devices = await db.select(db.localDevices).get();
    expect(devices, hasLength(1));
    expect(devices.single.deviceId, deviceId);
    expect(devices.single.workspaceId, 88);

    final localStore = await localAuth.anyStore();
    expect(localStore!.workspaceId, PosMode.standaloneWorkspaceId);
    expect(localStore.connectedMode, isTrue);
  });

  test('G device bound to another workspace is not saved locally', () async {
    await localAuth.bootstrapStore(
      storeName: 'محلي',
      adminName: 'مدير',
      username: 'admin',
      pin: '1234',
    );
    final backend = _FakeCashierBackend()
      ..workspaces = [
        {'id': 91, 'name': 'B', 'pos_enabled': true},
      ]
      ..registerStatus = 403
      ..registerMessage =
          'هذا الجهاز مسجّل لمساحة عمل أخرى ولا يمكن إعادة استخدامه.';
    final service = serviceFor(fakeDio(backend));
    expect(
      () => service.bindWorkspace(
        workspace: backend.workspaces.first,
        token: 'token-1',
        user: {'id': 4},
      ),
      throwsA(
        isA<ApiException>()
            .having((e) => e.statusCode, 'status', 403)
            .having(
              (e) => e.message,
              'message',
              contains('مساحة عمل أخرى'),
            ),
      ),
    );
    expect(await store.read(), isNull);
    expect(await db.select(db.localDevices).get(), isEmpty);
    final localStore = await localAuth.anyStore();
    expect(localStore!.connectedMode, isFalse);
    expect(localStore.workspaceId, PosMode.standaloneWorkspaceId);
  });

  test('H I restart-offline PIN still works after successful link', () async {
    final created = await localAuth.bootstrapStore(
      storeName: 'محلي',
      adminName: 'مدير',
      username: 'admin@local',
      pin: '1234',
    );
    await store.save(
      const CloudLinkSnapshot(
        token: 'sanctum-persisted',
        workspaceId: 77,
        userId: 5,
        deviceId: 'device-fixed-uuid',
        posEnabled: true,
        deviceRegistered: true,
      ),
    );
    await localAuth.updateStore(
      storeId: created.store.localId,
      connectedMode: true,
    );

    final link = await store.read();
    expect(link?.isLinked, isTrue);
    expect(link?.workspaceId, 77);

    final user = await localAuth.login(
      workspaceId: PosMode.standaloneWorkspaceId,
      username: 'admin@local',
      pin: '1234',
    );
    expect(user.localId, created.user.localId);
    expect(user.workspaceId, PosMode.standaloneWorkspaceId);

    final localStore = await localAuth.anyStore();
    expect(localStore!.workspaceId, isNot(77));
    expect(localStore.workspaceId, PosMode.standaloneWorkspaceId);

    final checkout = CheckoutService(
      db,
      StockEngine(db),
      DocumentNumberService(db),
      SyncQueueRepository(db),
    );
    expect(checkout, isNotNull);
    expect(PosMode.isReservedStandaloneWorkspace(localStore.workspaceId), isTrue);
    expect(PosMode.admitRestoredSession('sanctum-persisted'), isFalse);
  });

  test('failed catalog snapshot does not keep a cloud link', () async {
    await localAuth.bootstrapStore(
      storeName: 'محلي',
      adminName: 'مدير',
      username: 'admin',
      pin: '1234',
    );
    final backend = _FakeCashierBackend()
      ..workspaces = [
        {'id': 42, 'name': 'POS', 'pos_enabled': true},
      ];
    final api = CashierApiClient(fakeDio(backend));
    final service = serviceFor(
      fakeDio(backend),
      initialSync: _ThrowingInitialSync(db, api),
    );
    expect(
      () => service.bindWorkspace(
        workspace: backend.workspaces.first,
        token: 'token-1',
        user: {'id': 9},
      ),
      throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 500)),
    );
    expect(await store.read(), isNull);
  });

  test('ensureCatalogSnapshot retries a missing initial sync', () async {
    const link = CloudLinkSnapshot(
      token: 'sanctum-live',
      workspaceId: 42,
      userId: 9,
      deviceId: 'device-fixed-uuid',
      posEnabled: true,
      deviceRegistered: true,
    );
    await store.save(link);
    expect(await db.hasInitialSync(42), isFalse);

    final api = CashierApiClient(fakeDio(_FakeCashierBackend()));
    final service = serviceFor(
      fakeDio(_FakeCashierBackend()),
      initialSync: _OkInitialSync(db, api),
    );
    expect(await service.ensureCatalogSnapshot(link), isTrue);
    expect(await db.hasInitialSync(42), isTrue);
    expect(
      await CashierCloudLinkService.catalogWorkspaceId(
        localStoreWorkspaceId: PosMode.standaloneWorkspaceId,
        link: link,
        db: db,
      ),
      42,
    );
  });

  test('timeout is not rewritten as a successful offline login', () async {
    final dio = Dio(
      BaseOptions(baseUrl: 'http://cashier.test/api/cashier/v1'),
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          handler.reject(
            DioException(
              requestOptions: options,
              type: DioExceptionType.connectionTimeout,
            ),
          );
        },
      ),
    );
    final api = CashierApiClient(dio);
    expect(
      () => api.post('/auth/login', data: const {}),
      throwsA(
        isA<ApiException>()
            .having((e) => e.statusCode, 'status', 0)
            .having((e) => e.isNetwork, 'network', isTrue)
            .having(
              (e) => e.message,
              'message',
              contains('تعذر الاتصال بالخادم'),
            ),
      ),
    );
  });
}

class _FakeCashierBackend {
  int loginStatus = 200;
  String loginMessage = 'تم تسجيل الدخول بنجاح.';
  int registerStatus = 200;
  String registerMessage = 'هذا الجهاز مسجّل لمساحة عمل أخرى ولا يمكن إعادة استخدامه.';
  List<Map<String, dynamic>> workspaces = const [];
  int registerCalls = 0;
  final registeredIds = <String>[];

  void handle(RequestOptions options, RequestInterceptorHandler handler) {
    final path = options.path;
    if (path.endsWith('/auth/login')) {
      if (loginStatus != 200) {
        handler.reject(
          DioException(
            requestOptions: options,
            type: DioExceptionType.badResponse,
            response: Response(
              requestOptions: options,
              statusCode: loginStatus,
              data: {
                'success': false,
                'message': loginMessage,
              },
            ),
          ),
        );
        return;
      }
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {
              'token': 'token-1',
              'user': {'id': 3, 'name': 'مالك'},
              'workspace': workspaces.isEmpty ? null : workspaces.first,
              'workspaces': workspaces,
              'pos_enabled': workspaces.any((w) => w['pos_enabled'] == true),
            },
          },
        ),
      );
      return;
    }
    if (path.endsWith('/workspaces') && options.method == 'GET') {
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {'workspaces': workspaces},
          },
        ),
      );
      return;
    }
    if (path.endsWith('/workspaces/switch')) {
      final id = (options.data as Map?)?['workspace_id'];
      Map<String, dynamic>? match;
      for (final workspace in workspaces) {
        if (workspace['id'] == id) {
          match = workspace;
          break;
        }
      }
      if (match == null) {
        handler.reject(
          DioException(
            requestOptions: options,
            type: DioExceptionType.badResponse,
            response: Response(
              requestOptions: options,
              statusCode: 404,
              data: {
                'success': false,
                'message': 'مساحة العمل غير متاحة لهذا المستخدم.',
              },
            ),
          ),
        );
        return;
      }
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {
              'workspace': match,
              'pos_enabled': match['pos_enabled'] == true,
            },
          },
        ),
      );
      return;
    }
    if (path.endsWith('/devices/register')) {
      registerCalls++;
      final deviceId = '${(options.data as Map?)?['device_id'] ?? ''}';
      registeredIds.add(deviceId);
      if (registerStatus != 200) {
        handler.reject(
          DioException(
            requestOptions: options,
            type: DioExceptionType.badResponse,
            response: Response(
              requestOptions: options,
              statusCode: registerStatus,
              data: {
                'success': false,
                'message': registerMessage,
              },
            ),
          ),
        );
        return;
      }
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {
              'device_id': deviceId,
              'workspace_id': workspaces.first['id'],
              'account_id': 3,
              'user_id': 3,
              'name': 'كاشير حاسم',
              'platform': 'cashier',
            },
          },
        ),
      );
      return;
    }
    handler.reject(
      DioException(
        requestOptions: options,
        type: DioExceptionType.badResponse,
        response: Response(
          requestOptions: options,
          statusCode: 404,
          data: {'success': false, 'message': 'not found $path'},
        ),
      ),
    );
  }
}

class _ThrowingInitialSync extends InitialSyncService {
  _ThrowingInitialSync(AppDatabase db, CashierApiClient api) : super(db, api);

  @override
  Future<InitialSyncResult> run(int workspaceId, {String? deviceId}) {
    throw ApiException('فشل snapshot', statusCode: 500);
  }
}

class _OkInitialSync extends InitialSyncService {
  _OkInitialSync(this.appDb, CashierApiClient api) : super(appDb, api);

  final AppDatabase appDb;

  @override
  Future<InitialSyncResult> run(int workspaceId, {String? deviceId}) async {
    await appDb.markInitialSyncCompleted(workspaceId, deviceId: deviceId);
    return const InitialSyncResult(ready: true, message: 'ok');
  }
}
