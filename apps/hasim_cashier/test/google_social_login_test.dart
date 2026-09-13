import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/api/cashier_api.dart';
import 'package:hasim_cashier/core/api/cashier_network_policy.dart';
import 'package:hasim_cashier/core/config/app_config.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/offline/offline_store.dart';
import 'package:hasim_cashier/core/offline/sync_engine.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/sync/pos_sync_coordinator.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_now_copy.dart';

class _HiveSpy extends SyncEngine {
  _HiveSpy() : super(OfflineStore.instance);

  var flushCalls = 0;

  @override
  Future<SyncFlushResult> flushPendingOrders({int? workspaceId}) async {
    flushCalls++;
    return const SyncFlushResult(keptPending: 54);
  }
}

class _SqliteStub extends SyncEngineV2 {
  _SqliteStub(AppDatabase db) : super(db, SyncQueueRepository(db));

  @override
  Future<SyncEngineV2Result> syncBidirectional({
    required int workspaceId,
    String? deviceId,
  }) async {
    return const SyncEngineV2Result(synced: 0, keptPending: 0);
  }
}

void main() {
  test('offline-only still allows Laravel Google social login', () {
    expect(AppConfig.offlineOnly, isTrue);
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: null,
        path: '/auth/social',
        method: 'POST',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.cloudSetupPaths.contains('/auth/social'),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: null,
        path: '/auth/google/start',
        method: 'POST',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: null,
        path: '/auth/google/status',
        method: 'GET',
      ),
      isTrue,
    );
  });

  test('cashier client posts Google token to /auth/social', () async {
    late String path;
    late Map<String, dynamic> posted;
    final dio = Dio(
      BaseOptions(baseUrl: 'http://cashier.test/api/cashier/v1'),
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          path = options.path;
          posted = Map<String, dynamic>.from(options.data as Map);
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: 200,
              data: {
                'success': true,
                'data': {
                  'token': 'sanctum-google',
                  'user': {
                    'id': 9,
                    'name': 'Google User',
                    'email': 'owner@hasim.test',
                  },
                  'workspace': {
                    'id': 12,
                    'name': 'المتجر',
                    'pos_enabled': true,
                  },
                  'workspaces': [
                    {'id': 12, 'name': 'المتجر', 'pos_enabled': true},
                  ],
                  'pos_enabled': true,
                },
              },
            ),
          );
        },
      ),
    );

    final data = await CashierApiClient(dio).post(
      '/auth/social',
      data: {
        'provider': 'google',
        'access_token': 'ya29.google-token',
        'device_name': 'كاشير حاسم',
        'device_type': 'cashier',
      },
    );

    expect(path, '/auth/social');
    expect(posted['provider'], 'google');
    expect(posted['access_token'], 'ya29.google-token');
    expect(data['token'], 'sanctum-google');
    expect(data['pos_enabled'], isTrue);
  });

  test('cashier client unwraps Laravel Google browser ticket', () async {
    final seen = <String>[];
    final dio = Dio(
      BaseOptions(baseUrl: 'http://cashier.test/api/cashier/v1'),
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          seen.add('${options.method} ${options.path}');
          if (options.path.contains('/auth/google/start')) {
            handler.resolve(
              Response(
                requestOptions: options,
                statusCode: 200,
                data: {
                  'success': true,
                  'data': {
                    'ticket': '11111111-1111-1111-1111-111111111111',
                    'auth_url':
                        'https://accounts.google.com/o/oauth2/v2/auth?client_id=x',
                    'expires_in': 600,
                  },
                },
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
                  'status': 'ready',
                  'access_token': 'ya29.from-laravel-env',
                  'error': null,
                },
              },
            ),
          );
        },
      ),
    );

    final api = CashierApiClient(dio);
    final started = await api.post('/auth/google/start');
    expect(started['ticket'], '11111111-1111-1111-1111-111111111111');
    expect(started['auth_url'], contains('accounts.google.com'));

    final status = await api.get(
      '/auth/google/status',
      query: {'ticket': started['ticket']},
    );
    expect(status['status'], 'ready');
    expect(status['access_token'], 'ya29.from-laravel-env');
    expect(seen, [
      'POST /auth/google/start',
      'GET /auth/google/status',
    ]);
  });

  test('offlineOnly skips Hive /orders leftovers during Settings sync', () async {
    expect(AppConfig.offlineOnly, isTrue);
    final hive = _HiveSpy();
    final db = AppDatabase.memory();
    addTearDown(db.close);
    final coordinator = PosSyncCoordinator(
      hiveEngine: hive,
      sqliteEngine: _SqliteStub(db),
    );
    final result = await coordinator.flushPendingOrders(
      workspaceId: 12,
      deviceId: 'dev-1',
    );
    expect(hive.flushCalls, 0);
    expect(result.keptPending, 0);
    expect(result.synced, 0);
  });

  test('Settings copy does not blame Laravel for session leftovers', () {
    expect(
      SyncNowCopy.afterFlush(
        synced: 0,
        failed: 0,
        failedQueued: 0,
        ready: 0,
        waitingParent: 0,
        leftovers: 54,
        authRequired: false,
        apiBase: 'http://127.0.0.1:8000',
      ),
      contains('تبقى محلية'),
    );
    expect(
      SyncNowCopy.afterFlush(
        synced: 0,
        failed: 0,
        failedQueued: 0,
        ready: 0,
        waitingParent: 0,
        leftovers: 54,
        authRequired: false,
        apiBase: 'http://127.0.0.1:8000',
      ),
      isNot(contains('تحقق من Laravel')),
    );
    expect(
      SyncNowCopy.afterFlush(
        synced: 0,
        failed: 0,
        failedQueued: 0,
        ready: 3,
        waitingParent: 0,
        leftovers: 51,
        authRequired: false,
        apiBase: 'http://127.0.0.1:8000',
      ),
      contains('ما زال 3 فاتورة'),
    );
    expect(
      SyncNowCopy.afterFlush(
        synced: 0,
        failed: 0,
        failedQueued: 0,
        ready: 0,
        waitingParent: 8,
        leftovers: 0,
        authRequired: false,
        apiBase: 'http://127.0.0.1:8000',
      ),
      contains('انتظار عنصر أب'),
    );
    expect(
      SyncNowCopy.afterFlush(
        synced: 0,
        failed: 0,
        failedQueued: 0,
        ready: 0,
        waitingParent: 8,
        leftovers: 0,
        authRequired: false,
        apiBase: 'http://127.0.0.1:8000',
      ),
      isNot(contains('تحقق من Laravel')),
    );
    expect(
      SyncNowCopy.afterFlush(
        synced: 0,
        failed: 0,
        failedQueued: 18,
        ready: 0,
        waitingParent: 8,
        leftovers: 29,
        authRequired: false,
        apiBase: 'http://127.0.0.1:8000',
        lastError: 'صنف غير موجود',
      ),
      contains('18 عملية فشلت'),
    );
  });
}
