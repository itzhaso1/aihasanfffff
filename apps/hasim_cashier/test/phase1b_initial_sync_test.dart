import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:drift/drift.dart' hide isNotNull, isNull;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/api/cashier_api.dart';
import 'package:hasim_cashier/core/auth/cashier_cloud_link_service.dart';
import 'package:hasim_cashier/core/auth/cloud_link_store.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/initial_sync_service.dart';
import 'package:hasim_cashier/core/local_db/local_ids.dart';
import 'package:hasim_cashier/core/local_db/workspace_scope.dart';
import 'package:hasim_cashier/core/pos/application/catalog_admin_service.dart';
import 'package:hasim_cashier/core/pos/application/checkout_service.dart';
import 'package:hasim_cashier/core/pos/application/document_numbers.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/application/shift_service.dart';
import 'package:hasim_cashier/core/pos/application/stock_engine.dart';
import 'package:hasim_cashier/core/pos/domain/pricing_service.dart';
import 'package:hasim_cashier/core/pos/pos_mode.dart';
import 'package:hasim_cashier/core/repositories/catalog_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';

void main() {
  late AppDatabase db;
  late LocalAuthService localAuth;

  setUp(() {
    db = AppDatabase.memory();
    localAuth = LocalAuthService(db);
  });

  tearDown(() async {
    await db.close();
  });

  InitialSyncService syncFor(_Phase1bBackend backend) {
    return InitialSyncService(
      db,
      CashierApiClient(fakeDio(backend)),
      deviceId: 'device-fixed-uuid',
    );
  }

  test('1 bootstrap success persists cashier settings and permissions', () async {
    final backend = _Phase1bBackend();
    final result = await syncFor(backend).run(10, deviceId: 'device-fixed-uuid');
    expect(result.ready, isTrue);
    expect(backend.bootstrapCalls, 1);

    final settings = await (db.select(db.localSettings)
          ..where((t) => t.workspaceId.equals(10) & t.key.equals('pos')))
        .getSingle();
    final decoded = jsonDecode(settings.valueJson) as Map;
    expect(decoded['tax_rate'], 15);
    expect(decoded['currency'], 'SAR');
    expect(decoded['pos_enabled'], isTrue);

    final perms = await (db.select(db.localPermissions)
          ..where((t) => t.workspaceId.equals(10)))
        .get();
    expect(perms.any((row) => row.key == 'pos.use' && row.allowed), isTrue);
  });

  test('2 bootstrap POS disabled does not complete sync', () async {
    await db.writeCursor(10, '42');
    final backend = _Phase1bBackend()..posEnabled = false;
    await expectLater(
      syncFor(backend).run(10, deviceId: 'device-fixed-uuid'),
      throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 403)),
    );
    expect(await db.hasInitialSync(10), isFalse);
    expect(await db.readCursor(10), '42');
    expect(await db.productCount(10), 0);
    expect(backend.itemPages, isEmpty);
    expect(backend.pullCalls, 0);
  });

  test('3 4 6 7 8 categories products tables and local ids imported', () async {
    final backend = _Phase1bBackend();
    final result = await syncFor(backend).run(10, deviceId: 'device-fixed-uuid');
    expect(result.ready, isTrue);
    expect(result.categoryCount, 2);
    expect(result.productCount, 2);
    expect(result.tableCount, 2);

    final categories = await CatalogRepository(db).categories(10);
    expect(categories.map((row) => row['local_id']), [
      LocalIds.category(10, 1),
      LocalIds.category(10, 2),
    ]);

    final products = await CatalogRepository(db).products(10);
    expect(products.singleWhere((row) => row['local_id'] == 'w10_prod_15')['name'], 'برجر');
    expect(products.singleWhere((row) => row['local_id'] == 'w10_prod_15')['price'], 12.5);
    expect(
      products.singleWhere((row) => row['local_id'] == 'w10_prod_15')['pos_item_category_id'],
      1,
    );

    final tables = await CatalogRepository(db).tables(10);
    expect(tables.map((row) => row['local_id']), [
      LocalIds.table(10, 7),
      LocalIds.table(10, 8),
    ]);
    expect(tables.first['qr_token'], isNotNull);
  });

  test('5 more than 200 products imports ALL pages', () async {
    final backend = _Phase1bBackend()..productCount = 250;
    final result = await syncFor(backend).run(10, deviceId: 'device-fixed-uuid');
    expect(result.ready, isTrue);
    expect(result.productCount, 250);
    expect(backend.itemPages, [1, 2, 3]);
    expect(backend.itemPerPage.toSet(), {100});
    expect(await db.productCount(10), 250);
    expect(
      await (db.select(db.localProducts)
            ..where((t) => t.localId.equals('w10_prod_250')))
          .getSingle(),
      isNotNull,
    );
  });

  test('9 existing local table operational payload is preserved', () async {
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: 'w10_table_7',
            workspaceId: 10,
            serverId: const Value(7),
            name: 'طاولة 7',
            status: const Value('occupied'),
            payloadJson: Value(
              jsonEncode({
                'id': 7,
                'session_client_id': 'sess-local-1',
                'session_open': true,
                'opened_at': '2026-01-01T10:00:00Z',
                'orders': [
                  {'local_id': 'ord-pending', 'status': 'new'},
                ],
              }),
            ),
            updatedAt: DateTime.now(),
          ),
        );

    await syncFor(_Phase1bBackend()).run(10, deviceId: 'device-fixed-uuid');

    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals('w10_table_7')))
        .getSingle();
    expect(table.localId, 'w10_table_7');
    expect(table.serverId, 7);
    expect(table.name, 'طاولة 7');
    expect(table.status, 'occupied');
    final payload = jsonDecode(table.payloadJson) as Map;
    expect(payload['session_client_id'], 'sess-local-1');
    expect(payload['session_open'], isTrue);
    expect(payload['opened_at'], '2026-01-01T10:00:00Z');
    expect(payload['orders'], [
      {'local_id': 'ord-pending', 'status': 'new'},
    ]);
    expect(payload['qr_token'], 'qr-7');
    expect(payload.containsKey('session_id'), isFalse);
    expect(payload.containsKey('lines'), isFalse);
  });

  test('10 11 12 cursor is integer anchor with limit 0 and never a timestamp', () async {
    expect(
      () => db.writeCursor(10, '2026-09-07T12:00:00.000Z'),
      throwsA(isA<StateError>()),
    );
    expect(SyncCursor.isInteger('118'), isTrue);
    expect(SyncCursor.isInteger('2026-09-07T12:00:00.000Z'), isFalse);

    final backend = _Phase1bBackend()..serverCursor = 118;
    final result = await syncFor(backend).run(10, deviceId: 'device-fixed-uuid');
    expect(result.cursor, 118);
    expect(await db.readCursor(10), '118');
    expect(SyncCursor.isInteger((await db.readCursor(10))!), isTrue);
    expect(await db.readMeta(10, SyncMetaKeys.lastPullAt), contains('T'));
    expect(backend.pullCalls, 1);
    expect(backend.pullBodies.single['device_id'], 'device-fixed-uuid');
    expect(backend.pullBodies.single['cursor'], 0);
    expect(backend.pullBodies.single['limit'], 0);
    expect(backend.pullBodies.single['limit'], isNot(isA<String>()));
  });

  test('13 network failure preserves previous cursor', () async {
    await db.writeCursor(10, '42');
    final backend = _Phase1bBackend()..failItemsNetwork = true;
    await expectLater(
      syncFor(backend).run(10, deviceId: 'device-fixed-uuid'),
      throwsA(isA<ApiException>().having((e) => e.isNetwork, 'network', isTrue)),
    );
    expect(await db.readCursor(10), '42');
    expect(await db.hasInitialSync(10), isFalse);
  });

  test('14 partial catalog failure does not mark initial sync complete', () async {
    await db.writeCursor(10, '42');
    final backend = _Phase1bBackend()
      ..productCount = 250
      ..failItemPage = 2;
    await expectLater(
      syncFor(backend).run(10, deviceId: 'device-fixed-uuid'),
      throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 500)),
    );
    expect(await db.hasInitialSync(10), isFalse);
    expect(await db.readCursor(10), '42');
    expect(await db.productCount(10), 0);
    expect(backend.pullCalls, 0);
  });

  test('pull failure after snapshot does not advance cursor or complete', () async {
    await db.writeCursor(10, '42');
    final backend = _Phase1bBackend()..failPullNetwork = true;
    await expectLater(
      syncFor(backend).run(10, deviceId: 'device-fixed-uuid'),
      throwsA(isA<ApiException>().having((e) => e.isNetwork, 'network', isTrue)),
    );
    expect(await db.productCount(10), 2);
    expect(await db.hasInitialSync(10), isFalse);
    expect(await db.readCursor(10), '42');
  });

  test('15 restart after sync reads SQLite without HTTP', () async {
    final backend = _Phase1bBackend();
    await syncFor(backend).run(10, deviceId: 'device-fixed-uuid');
    final catalogCalls = backend.itemPages.length;
    final restart = InitialSyncService(
      db,
      CashierApiClient(fakeDio(backend)),
      deviceId: 'device-fixed-uuid',
    );
    final cached = await restart.ensureReady(10);
    expect(cached.ready, isTrue);
    expect(cached.fromCache, isTrue);
    expect(cached.productCount, 2);
    expect(backend.itemPages.length, catalogCalls);

    final products = await CatalogRepository(db).products(10);
    expect(products, isNotEmpty);
    final tables = await CatalogRepository(db).tables(10);
    expect(tables, isNotEmpty);
  });

  test('16 offline POS still works and 900001 data is not mixed', () async {
    final created = await localAuth.bootstrapStore(
      storeName: 'محلي',
      adminName: 'مدير',
      username: 'admin',
      pin: '1234',
      taxRate: 15,
    );
    final localProductId = await CatalogAdminService(db).createProduct(
      workspaceId: PosMode.standaloneWorkspaceId,
      name: 'صنف محلي',
      price: 9,
      permissions: LocalAuthService.adminPermissions,
    );
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: 'w900001_table_1',
            workspaceId: PosMode.standaloneWorkspaceId,
            serverId: const Value(1),
            name: 'محلية',
            updatedAt: DateTime.now(),
          ),
        );

    await syncFor(_Phase1bBackend()).run(10, deviceId: 'device-fixed-uuid');

    expect(await db.productCount(PosMode.standaloneWorkspaceId), 1);
    expect(await db.productCount(10), 2);
    final localProduct = await (db.select(db.localProducts)
          ..where((t) => t.localId.equals(localProductId)))
        .getSingle();
    expect(localProduct.workspaceId, PosMode.standaloneWorkspaceId);
    expect(localProduct.name, 'صنف محلي');

    final standaloneTable = await (db.select(db.localTables)
          ..where((t) => t.localId.equals('w900001_table_1')))
        .getSingle();
    expect(standaloneTable.workspaceId, PosMode.standaloneWorkspaceId);

    final mappingBefore = await CashierCloudLinkService.catalogWorkspaceId(
      localStoreWorkspaceId: PosMode.standaloneWorkspaceId,
      link: const CloudLinkSnapshot(
        token: 'sanctum',
        workspaceId: 10,
        userId: 3,
        deviceId: 'device-fixed-uuid',
        posEnabled: true,
        deviceRegistered: true,
      ),
      db: db,
    );
    expect(mappingBefore, 10);

    final mappingUnsynced = await CashierCloudLinkService.catalogWorkspaceId(
      localStoreWorkspaceId: PosMode.standaloneWorkspaceId,
      link: const CloudLinkSnapshot(
        token: 'sanctum',
        workspaceId: 99,
        userId: 3,
        deviceId: 'device-fixed-uuid',
        posEnabled: true,
        deviceRegistered: true,
      ),
      db: db,
    );
    expect(mappingUnsynced, PosMode.standaloneWorkspaceId);

    final shifts = ShiftService(db);
    final localShift = await shifts.open(
      workspaceId: PosMode.standaloneWorkspaceId,
      userId: created.user.localId,
      openingCash: 50,
      permissions: LocalAuthService.adminPermissions,
    );
    final checkout = CheckoutService(
      db,
      StockEngine(db),
      DocumentNumberService(db),
      SyncQueueRepository(db),
    );
    final localSale = await checkout.execute(
      CheckoutCommand(
        workspaceId: PosMode.standaloneWorkspaceId,
        deviceId: 'dev-1',
        storeId: created.store.localId,
        permissions: LocalAuthService.adminPermissions,
        clientReference: 'local-sale',
        orderType: 'takeaway',
        lines: [
          PricedLine(
            productLocalId: localProductId,
            name: 'صنف محلي',
            quantity: 1,
            unitPrice: 9,
          ),
        ],
        payments: const [PaymentTender(method: 'cash', amount: 9, tendered: 9)],
        createdByUserId: created.user.localId,
        shiftLocalId: localShift,
        connected: false,
      ),
    );
    expect(localSale.total, 9);

    final cloudShift = await shifts.open(
      workspaceId: 10,
      userId: created.user.localId,
      openingCash: 20,
      permissions: LocalAuthService.adminPermissions,
    );
    final cloudSale = await checkout.execute(
      CheckoutCommand(
        workspaceId: 10,
        deviceId: 'dev-1',
        storeId: created.store.localId,
        permissions: LocalAuthService.adminPermissions,
        clientReference: 'cloud-sale',
        orderType: 'takeaway',
        lines: [
          const PricedLine(
            productLocalId: 'w10_prod_15',
            productServerId: 15,
            name: 'برجر',
            quantity: 1,
            unitPrice: 12.5,
          ),
        ],
        payments: const [
          PaymentTender(method: 'cash', amount: 12.5, tendered: 12.5),
        ],
        createdByUserId: created.user.localId,
        shiftLocalId: cloudShift,
        connected: false,
      ),
    );
    expect(cloudSale.total, 12.5);
    final cloudOrder = await (db.select(db.localOrders)
          ..where((t) => t.localId.equals('cloud-sale')))
        .getSingle();
    expect(cloudOrder.workspaceId, 10);
    expect(cloudOrder.syncStatus, 'pending');
    final queued = await db.select(db.syncQueueItems).get();
    expect(queued.map((r) => r.entityType).toSet(), {'order', 'invoice'});
    final orderRow = queued.singleWhere((r) => r.entityType == 'order');
    expect(orderRow.operation, 'create');
    expect(orderRow.clientReference, 'cloud-sale');
    final invoiceRow = queued.singleWhere((r) => r.entityType == 'invoice');
    expect(invoiceRow.operation, 'create');
  });

  test('401 403 422 errors stay visible', () async {
    await expectLater(
      syncFor(_Phase1bBackend()..bootstrapStatus = 401)
          .run(10, deviceId: 'device-fixed-uuid'),
      throwsA(
        isA<ApiException>().having((e) => e.isUnauthorized, '401', isTrue),
      ),
    );
    await expectLater(
      syncFor(_Phase1bBackend()..categoriesStatus = 403)
          .run(10, deviceId: 'device-fixed-uuid'),
      throwsA(isA<ApiException>().having((e) => e.isForbidden, '403', isTrue)),
    );
    await expectLater(
      syncFor(_Phase1bBackend()..pullStatus = 422)
          .run(10, deviceId: 'device-fixed-uuid'),
      throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 422)),
    );
  });

  test('refuses to write Laravel snapshot into standalone 900001', () async {
    await expectLater(
      syncFor(_Phase1bBackend()).run(PosMode.standaloneWorkspaceId),
      throwsA(isA<ApiException>().having((e) => e.statusCode, 'status', 422)),
    );
  });

  test('product local id is not replaced on refresh', () async {
    await db.into(db.localCategories).insert(
          LocalCategoriesCompanion.insert(
            localId: 'custom-cat-1',
            workspaceId: 10,
            serverId: const Value(1),
            name: 'قديم',
            updatedAt: DateTime.now(),
          ),
        );
    await db.into(db.localProducts).insert(
          LocalProductsCompanion.insert(
            localId: 'custom-prod-15',
            workspaceId: 10,
            serverId: const Value(15),
            name: 'قديم',
            price: const Value(100),
            updatedAt: DateTime.now(),
          ),
        );
    await syncFor(_Phase1bBackend()).run(10, deviceId: 'device-fixed-uuid');
    final product = await (db.select(db.localProducts)
          ..where((t) => t.serverId.equals(15)))
        .getSingle();
    expect(product.localId, 'custom-prod-15');
    expect(product.name, 'برجر');
    final category = await (db.select(db.localCategories)
          ..where((t) => t.serverId.equals(1)))
        .getSingle();
    expect(category.localId, 'custom-cat-1');
    expect(product.categoryLocalId, 'custom-cat-1');
  });
}

Dio fakeDio(_Phase1bBackend backend) {
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
      onRequest: (options, handler) => backend.handle(options, handler),
    ),
  );
  return dio;
}

class _Phase1bBackend {
  bool posEnabled = true;
  int productCount = 2;
  int serverCursor = 118;
  int bootstrapCalls = 0;
  int bootstrapStatus = 200;
  int categoriesStatus = 200;
  int pullStatus = 200;
  int? failItemPage;
  bool failItemsNetwork = false;
  bool failPullNetwork = false;
  final itemPages = <int>[];
  final itemPerPage = <int>[];
  final pullBodies = <Map<String, dynamic>>[];
  int pullCalls = 0;

  void handle(RequestOptions options, RequestInterceptorHandler handler) {
    final path = options.path.split('?').first;
    if (path.endsWith('/bootstrap')) {
      bootstrapCalls++;
      if (bootstrapStatus != 200) {
        _reject(options, handler, bootstrapStatus, 'انتهت الجلسة. سجّل الدخول مجددًا.');
        return;
      }
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {
              'user': {'id': 3, 'name': 'مالك'},
              'workspace': {'id': 10, 'name': 'المتجر'},
              'pos_enabled': posEnabled,
              'entitlements': {'pos': posEnabled},
              'permissions': {
                'pos.use': true,
                'orders.create': true,
                'tables.manage': true,
              },
              'settings': {
                'tax_rate': 15,
                'currency': 'SAR',
                'sound_enabled': true,
                'enable_delivery': true,
              },
              'channel_stats': {'total': 9},
            },
          },
        ),
      );
      return;
    }
    if (path.endsWith('/catalog/categories')) {
      if (categoriesStatus != 200) {
        _reject(options, handler, categoriesStatus, 'لا تملك صلاحية تنفيذ هذه العملية.');
        return;
      }
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {
              'categories': [
                {'id': 1, 'name': 'وجبات', 'sort_order': 1, 'is_active': true},
                {'id': 2, 'name': 'مشروبات', 'sort_order': 2, 'is_active': true},
              ],
            },
          },
        ),
      );
      return;
    }
    if (path.endsWith('/catalog/items')) {
      if (failItemsNetwork) {
        handler.reject(
          DioException(
            requestOptions: options,
            type: DioExceptionType.connectionTimeout,
          ),
        );
        return;
      }
      final page = int.tryParse('${options.queryParameters['page'] ?? 1}') ?? 1;
      var perPage =
          int.tryParse('${options.queryParameters['per_page'] ?? 50}') ?? 50;
      perPage = perPage.clamp(1, 100);
      itemPages.add(page);
      itemPerPage.add(perPage);
      if (failItemPage == page) {
        _reject(options, handler, 500, 'خطأ في الخادم. حاول لاحقًا.');
        return;
      }
      final lastPage = productCount == 0
          ? 1
          : ((productCount + perPage - 1) ~/ perPage);
      final start = (page - 1) * perPage;
      final items = <Map<String, dynamic>>[];
      if (productCount <= 2) {
        items.addAll([
          {
            'id': 15,
            'name': 'برجر',
            'sku': 'BRG',
            'barcode': '111',
            'item_type': 'وجبات',
            'description': 'برجر لحم',
            'price': 12.50,
            'currency': 'SAR',
            'is_active': true,
            'sort_order': 1,
            'pos_item_category_id': 1,
            'product_id': 80,
            'stock': 4,
            'updated_at': '2026-09-07T10:00:00Z',
          },
          {
            'id': 16,
            'name': 'عصير',
            'price': 5,
            'currency': 'SAR',
            'is_active': true,
            'pos_item_category_id': 2,
          },
        ]);
      } else {
        for (var i = start + 1; i <= productCount && items.length < perPage; i++) {
          items.add({
            'id': i,
            'name': 'صنف $i',
            'price': 1.0,
            'currency': 'SAR',
            'is_active': true,
            'pos_item_category_id': 1,
          });
        }
      }
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {'items': items},
            'meta': {
              'current_page': page,
              'per_page': perPage,
              'total': productCount,
              'last_page': lastPage,
            },
          },
        ),
      );
      return;
    }
    if (path.endsWith('/tables') &&
        options.method.toUpperCase() == 'GET') {
      final page = int.tryParse('${options.queryParameters['page'] ?? 1}') ?? 1;
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {
              'tables': [
                {
                  'id': 7,
                  'name': 'طاولة 7',
                  'status': 'occupied',
                  'session_id': 99,
                  'session_status': 'open',
                  'qr_token': 'qr-7',
                  'menu_url': 'http://example.test/menu/t/qr-7',
                  'lines': [
                    {'id': 1, 'name': 'طلب خادم', 'quantity': 2},
                  ],
                  'orders': [
                    {'id': 50, 'total': 20},
                  ],
                  'total': 20,
                },
                {
                  'id': 8,
                  'name': 'طاولة 8',
                  'status': 'available',
                  'qr_token': 'qr-8',
                  'menu_url': 'http://example.test/menu/t/qr-8',
                },
              ],
              'generated_at': '2026-09-07T10:00:00Z',
            },
            'meta': {
              'current_page': page,
              'per_page': 100,
              'total': 2,
              'last_page': 1,
            },
          },
        ),
      );
      return;
    }
    if (path.endsWith('/sync/pull')) {
      pullCalls++;
      final body = Map<String, dynamic>.from(options.data as Map);
      pullBodies.add(body);
      if (failPullNetwork) {
        handler.reject(
          DioException(
            requestOptions: options,
            type: DioExceptionType.connectionError,
          ),
        );
        return;
      }
      if (pullStatus != 200) {
        _reject(options, handler, pullStatus, 'تعذر التحقق من البيانات المرسلة.');
        return;
      }
      handler.resolve(
        Response(
          requestOptions: options,
          statusCode: 200,
          data: {
            'success': true,
            'data': {
              'cursor': serverCursor,
              'server_cursor': serverCursor,
              'has_more': false,
              'changes': [],
              'device_id': body['device_id'],
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

  void _reject(
    RequestOptions options,
    RequestInterceptorHandler handler,
    int status,
    String message,
  ) {
    handler.reject(
      DioException(
        requestOptions: options,
        type: DioExceptionType.badResponse,
        response: Response(
          requestOptions: options,
          statusCode: status,
          data: {'success': false, 'message': message},
        ),
      ),
    );
  }
}
