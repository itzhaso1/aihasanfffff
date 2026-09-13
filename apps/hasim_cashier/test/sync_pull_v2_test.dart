import 'dart:convert';

import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_ids.dart';
import 'package:hasim_cashier/core/local_db/workspace_scope.dart';
import 'package:hasim_cashier/core/pos/table_session_orders.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_pull_applier.dart';

void main() {
  late AppDatabase db;
  late SyncQueueRepository queue;

  setUp(() {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  test('pull applies product/category/table and advances cursor atomically',
      () async {
    await db.writeCursor(1, '10');
    final engine = SyncEngineV2(
      db,
      queue,
      fetchChanges: (since, limit) async {
        expect(since, 10);
        return {
          'cursor': 13,
          'server_cursor': 13,
          'has_more': false,
          'changes': [
            {
              'version': 11,
              'entity': 'category',
              'operation': 'create',
              'id': 5,
              'data': {'id': 5, 'name': 'مشروبات', 'is_active': true},
            },
            {
              'version': 12,
              'entity': 'product',
              'operation': 'create',
              'id': 9,
              'data': {
                'id': 9,
                'name': 'شاي',
                'price': 5,
                'pos_item_category_id': 5,
                'is_active': true,
              },
            },
            {
              'version': 13,
              'entity': 'table',
              'operation': 'create',
              'id': 3,
              'data': {'id': 3, 'name': 'T1', 'status': 'available'},
            },
          ],
        };
      },
    );

    final result = await engine.pullChanges(workspaceId: 1, deviceId: 'dev');
    expect(result.pulled, 3);
    expect(result.cursor, 13);
    expect(await db.readCursor(1), '13');

    final product = await (db.select(db.localProducts)
          ..where((t) => t.localId.equals(LocalIds.product(1, 9))))
        .getSingle();
    expect(product.name, 'شاي');
    expect(product.workspaceId, 1);

    final category = await (db.select(db.localCategories)
          ..where((t) => t.localId.equals(LocalIds.category(1, 5))))
        .getSingle();
    expect(category.name, 'مشروبات');

    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(LocalIds.table(1, 3))))
        .getSingle();
    expect(table.name, 'T1');
  });

  test('failed pull batch does not advance cursor or drop pending queue',
      () async {
    await db.writeCursor(1, '20');
    await queue.enqueue(
      workspaceId: 1,
      deviceId: 'dev',
      entityType: 'order',
      entityId: 'KEEP',
      operation: 'create',
      payload: {'client_reference': 'KEEP'},
      clientReference: 'KEEP',
    );

    final engine = SyncEngineV2(
      db,
      queue,
      fetchChanges: (since, limit) async {
        throw Exception('network while pulling');
      },
    );
    final result = await engine.syncBidirectional(workspaceId: 1);
    expect(result.pullFailed, isTrue);
    expect(await db.readCursor(1), '20');
    expect(await queue.pendingCount(1), 1);
  });

  test('delete change soft-deletes local product', () async {
    await db.into(db.localProducts).insert(
          LocalProductsCompanion.insert(
            localId: LocalIds.product(1, 8),
            workspaceId: 1,
            serverId: const Value(8),
            name: 'قديم',
            updatedAt: DateTime.now(),
          ),
        );
    await SyncPullApplier(db).applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 1,
      changes: [
        {
          'version': 1,
          'entity': 'product',
          'operation': 'delete',
          'id': 8,
          'data': {'id': 8, 'deleted': true},
        },
      ],
    );
    final row = await (db.select(db.localProducts)
          ..where((t) => t.localId.equals(LocalIds.product(1, 8))))
        .getSingle();
    expect(row.isDeleted, isTrue);
    expect(await db.readCursor(1), '1');
  });

  test('push then pull keeps pending on pull failure', () async {
    await db.into(db.localOrders).insert(
          LocalOrdersCompanion.insert(
            localId: 'P1',
            workspaceId: 1,
            deviceId: 'dev',
            clientReference: 'P1',
            orderType: 'table',
            tableServerId: const Value(7),
            createdAt: DateTime.now(),
            updatedAt: DateTime.now(),
          ),
        );
    await queue.enqueue(
      workspaceId: 1,
      deviceId: 'dev',
      entityType: 'order',
      entityId: 'P1',
      operation: 'create',
      payload: {
        'order_type': 'table',
        'dining_table_id': 7,
        'client_reference': 'P1',
        'items': [
          {'pos_menu_item_id': 1, 'quantity': 1},
        ],
      },
      clientReference: 'P1',
    );

    final engine = SyncEngineV2(
      db,
      queue,
      postOrder: (payload, key) async => {'id': 99},
      fetchChanges: (since, limit) async {
        throw Exception('pull down');
      },
    );

    final result = await engine.syncBidirectional(workspaceId: 1);
    expect(result.synced, 1);
    expect(result.pullFailed, isTrue);
    final order = await (db.select(db.localOrders)
          ..where((t) => t.localId.equals('P1')))
        .getSingle();
    expect(order.serverId, 99);
    expect(order.syncStatus, 'synced');
    // No cursor written because pull failed before apply.
    expect(await db.readCursor(1), isNull);
  });

  test('workspace B pull data never applied under workspace A cursor', () async {
    final engine = SyncEngineV2(
      db,
      queue,
      fetchChanges: (since, limit) async => {
        'cursor': 1,
        'server_cursor': 1,
        'has_more': false,
        'changes': [
          {
            'version': 1,
            'entity': 'product',
            'operation': 'create',
            'id': 100,
            'data': {'id': 100, 'name': 'B-only', 'price': 1},
          },
        ],
      },
    );
    await engine.pullChanges(workspaceId: 2);
    expect(await (db.select(db.localProducts)
          ..where((t) => t.workspaceId.equals(1)))
        .get(), isEmpty);
    expect(await (db.select(db.localProducts)
          ..where((t) => t.workspaceId.equals(2)))
        .get(), hasLength(1));
  });

  Map<String, dynamic> qrOrderChange({
    int version = 1,
    int orderId = 88,
    int tableId = 3,
    int sessionId = 17,
  }) {
    return {
      'version': version,
      'entity': 'order',
      'operation': 'create',
      'id': orderId,
      'data': {
        'id': orderId,
        'client_reference': 'qr-$orderId',
        'dining_table_id': tableId,
        'table_session_id': sessionId,
        'table_name': 'T$tableId',
        'source': 'qr_menu',
        'order_type': 'table',
        'pos_status': 'new',
        'payment_status': 'unpaid',
        'order_number': 'QR-$orderId',
        'subtotal': 12,
        'tax_amount': 0,
        'discount_amount': 0,
        'total_amount': 12,
        'placed_at': '2026-09-09T12:00:00.000Z',
        'items': [
          {
            'id': 501,
            'pos_menu_item_id': 9,
            'product_name': 'شاي',
            'quantity': 1,
            'unit_price': 12,
            'total_amount': 12,
          },
        ],
      },
    };
  }

  test('qr menu pull occupies the matching table without duplicating on retry',
      () async {
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: LocalIds.table(1, 3),
            workspaceId: 1,
            serverId: const Value(3),
            name: 'T3',
            status: const Value('available'),
            payloadJson: Value(jsonEncode({
              'id': 3,
              'name': 'T3',
              'status': 'available',
            })),
            updatedAt: DateTime.now(),
          ),
        );

    final applier = SyncPullApplier(db);
    await applier.applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 1,
      changes: [qrOrderChange()],
    );

    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(LocalIds.table(1, 3))))
        .getSingle();
    expect(table.status, 'occupied');
    expect(table.sessionServerId, 17);
    final payload = jsonDecode(table.payloadJson) as Map<String, dynamic>;
    expect(payload['session_open'], isTrue);
    expect(payload['session_client_id'], isNotEmpty);
    expect(payload['opened_at'], isNotNull);
    final orders = (payload['orders'] as List).cast<dynamic>();
    expect(orders, hasLength(1));

    final localOrder = await (db.select(db.localOrders)
          ..where((t) => t.clientReference.equals('qr-88')))
        .getSingle();
    expect(localOrder.tableServerId, 3);
    expect(localOrder.sessionLocalId, payload['session_client_id']);
    expect(
      isOrderInOpenTableSession(
        posStatus: localOrder.posStatus,
        paymentStatus: localOrder.paymentStatus,
        createdAt: localOrder.createdAt,
        openedAt: DateTime.parse(payload['opened_at'].toString()),
        orderSessionLocalId: localOrder.sessionLocalId,
        currentSessionLocalId: '${payload['session_client_id']}',
      ),
      isTrue,
    );

    await applier.applyBatch(
      workspaceId: 1,
      fromCursor: 1,
      responseCursor: 2,
      changes: [qrOrderChange(version: 2)],
    );
    final again = jsonDecode(
      (await (db.select(db.localTables)
            ..where((t) => t.localId.equals(LocalIds.table(1, 3))))
          .getSingle())
          .payloadJson,
    ) as Map<String, dynamic>;
    expect((again['orders'] as List), hasLength(1));
    expect(await db.select(db.localOrders).get(), hasLength(1));
  });

  test('table pull with open session occupies even when Laravel status is available',
      () async {
    await SyncPullApplier(db).applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 1,
      changes: [
        {
          'version': 1,
          'entity': 'table',
          'operation': 'update',
          'id': 9,
          'data': {
            'id': 9,
            'name': 'VIP',
            'status': 'available',
            'session_id': 22,
            'session_open': true,
            'opened_at': '2026-09-09T11:00:00.000Z',
          },
        },
      ],
    );
    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(LocalIds.table(1, 9))))
        .getSingle();
    expect(table.status, 'occupied');
    expect(table.sessionServerId, 22);
    final payload = jsonDecode(table.payloadJson) as Map<String, dynamic>;
    expect(payload['session_open'], isTrue);
    expect(payload['opened_at'], '2026-09-09T11:00:00.000Z');
  });

  test('pending local close is not overwritten by a stale occupied table pull',
      () async {
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: LocalIds.table(1, 4),
            workspaceId: 1,
            serverId: const Value(4),
            name: 'T4',
            status: const Value('available'),
            payloadJson: Value(jsonEncode({
              'id': 4,
              'status': 'available',
              'opened_at': null,
            })),
            updatedAt: DateTime.now(),
          ),
        );
    await queue.enqueue(
      workspaceId: 1,
      deviceId: 'dev',
      entityType: 'table_session',
      entityId: LocalIds.table(1, 4),
      operation: 'close',
      payload: {'table_server_id': 4},
      clientReference: 'close-1',
    );

    await SyncPullApplier(db).applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 1,
      changes: [
        {
          'version': 1,
          'entity': 'table',
          'operation': 'update',
          'id': 4,
          'data': {
            'id': 4,
            'name': 'T4',
            'status': 'occupied',
            'session_id': 55,
            'session_open': true,
            'opened_at': '2026-09-09T08:00:00.000Z',
          },
        },
      ],
    );

    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(LocalIds.table(1, 4))))
        .getSingle();
    expect(table.status, 'available');
    expect(table.sessionServerId, isNull);
    final payload = jsonDecode(table.payloadJson) as Map<String, dynamic>;
    expect(payload['opened_at'], isNull);
    expect(payload['session_open'], isFalse);
  });

  Future<void> seedOccupied({
    required int tableId,
    required int? sessionServerId,
    required String sessionClientId,
  }) async {
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: LocalIds.table(1, tableId),
            workspaceId: 1,
            serverId: Value(tableId),
            name: 'T$tableId',
            status: const Value('occupied'),
            sessionServerId: Value(sessionServerId),
            payloadJson: Value(jsonEncode({
              'id': tableId,
              'status': 'occupied',
              'session_open': true,
              'session_id': sessionServerId,
              'session_client_id': sessionClientId,
              'opened_at': '2026-09-09T09:00:00.000Z',
            })),
            updatedAt: DateTime.now(),
          ),
        );
    await db.into(db.localSessions).insert(
          LocalSessionsCompanion.insert(
            localId: sessionClientId,
            workspaceId: 1,
            tableLocalId: LocalIds.table(1, tableId),
            status: const Value('open'),
            openedAt: DateTime.parse('2026-09-09T09:00:00.000Z'),
            createdAt: DateTime.now(),
            updatedAt: DateTime.now(),
          ),
        );
  }

  Map<String, dynamic> closedTableChange(int tableId, {int version = 1}) => {
        'version': version,
        'entity': 'table',
        'operation': 'update',
        'id': tableId,
        'data': {
          'id': tableId,
          'name': 'T$tableId',
          'status': 'available',
          'session_id': null,
          'session_open': false,
          'opened_at': null,
        },
      };

  test('re-delivered older table/order versions never regress applied state',
      () async {
    final applier = SyncPullApplier(db);
    final order = qrOrderChange(version: 2);
    await applier.applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 3,
      changes: [
        {
          'version': 1,
          'entity': 'table',
          'operation': 'update',
          'id': 3,
          'data': {
            'id': 3,
            'name': 'T3',
            'status': 'occupied',
            'session_id': 17,
            'session_open': true,
            'opened_at': '2026-09-09T12:00:00.000Z',
          },
        },
        order,
        {
          'version': 3,
          'entity': 'order',
          'operation': 'update',
          'id': 88,
          'data': {
            ...order['data'] as Map<String, dynamic>,
            'pos_status': 'completed',
            'payment_status': 'paid',
          },
        },
      ],
    );
    await applier.applyBatch(
      workspaceId: 1,
      fromCursor: 3,
      responseCursor: 4,
      changes: [closedTableChange(3, version: 4)],
    );
    final sessionsAfter = await db.select(db.localSessions).get();
    expect(sessionsAfter, hasLength(1));
    expect(sessionsAfter.single.serverId, 17);
    expect(sessionsAfter.single.status, 'closed');

    // Cursor rollback: history comes back in order from version 1.
    await applier.applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 2,
      changes: [
        {
          'version': 1,
          'entity': 'table',
          'operation': 'update',
          'id': 3,
          'data': {
            'id': 3,
            'name': 'T3',
            'status': 'occupied',
            'session_id': 17,
            'session_open': true,
            'opened_at': '2026-09-09T12:00:00.000Z',
          },
        },
        order,
      ],
    );

    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(LocalIds.table(1, 3))))
        .getSingle();
    expect(table.status, 'available');
    expect(table.sessionServerId, isNull);
    expect(table.serverVersion, 4);
    final local = await (db.select(db.localOrders)
          ..where((t) => t.clientReference.equals('qr-88')))
        .getSingle();
    expect(local.posStatus, 'completed');
    expect(local.paymentStatus, 'paid');
    expect(local.serverVersion, 3);
    expect(await db.select(db.localOrders).get(), hasLength(1));
    final sessions = await db.select(db.localSessions).get();
    expect(sessions, hasLength(1));
    expect(sessions.single.status, 'closed');
  });

  test('Laravel-side close frees an acknowledged local sitting', () async {
    await seedOccupied(
      tableId: 6,
      sessionServerId: 61,
      sessionClientId: 'sess-acked',
    );

    await SyncPullApplier(db).applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 1,
      changes: [closedTableChange(6)],
    );

    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(LocalIds.table(1, 6))))
        .getSingle();
    expect(table.status, 'available');
    expect(table.sessionServerId, isNull);
    final payload = jsonDecode(table.payloadJson) as Map<String, dynamic>;
    expect(payload['session_open'], isFalse);
    expect(payload['session_client_id'], isNull);
    final session = await (db.select(db.localSessions)
          ..where((t) => t.localId.equals('sess-acked')))
        .getSingle();
    expect(session.status, 'closed');
    expect(session.closedAt, isNotNull);
  });

  test('unacknowledged offline open survives a stale available pull', () async {
    await seedOccupied(
      tableId: 7,
      sessionServerId: null,
      sessionClientId: 'sess-offline',
    );
    await queue.enqueue(
      workspaceId: 1,
      deviceId: 'dev',
      entityType: 'table_session',
      entityId: LocalIds.table(1, 7),
      operation: 'open',
      payload: {'table_server_id': 7, 'session_client_id': 'sess-offline'},
      clientReference: 'open-7',
    );

    await SyncPullApplier(db).applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 1,
      changes: [closedTableChange(7)],
    );

    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(LocalIds.table(1, 7))))
        .getSingle();
    expect(table.status, 'occupied');
    final payload = jsonDecode(table.payloadJson) as Map<String, dynamic>;
    expect(payload['session_client_id'], 'sess-offline');
    expect(payload['session_open'], isTrue);
    final session = await (db.select(db.localSessions)
          ..where((t) => t.localId.equals('sess-offline')))
        .getSingle();
    expect(session.status, 'open');
  });

  test('Laravel-side reopen after close starts a fresh local sitting',
      () async {
    await seedOccupied(
      tableId: 8,
      sessionServerId: 81,
      sessionClientId: 'sess-first',
    );
    final applier = SyncPullApplier(db);
    await applier.applyBatch(
      workspaceId: 1,
      fromCursor: 0,
      responseCursor: 1,
      changes: [closedTableChange(8)],
    );
    await applier.applyBatch(
      workspaceId: 1,
      fromCursor: 1,
      responseCursor: 2,
      changes: [
        {
          'version': 2,
          'entity': 'table',
          'operation': 'update',
          'id': 8,
          'data': {
            'id': 8,
            'name': 'T8',
            'status': 'available',
            'session_id': 82,
            'session_open': true,
            'opened_at': '2026-09-09T13:00:00.000Z',
          },
        },
      ],
    );

    final table = await (db.select(db.localTables)
          ..where((t) => t.localId.equals(LocalIds.table(1, 8))))
        .getSingle();
    expect(table.status, 'occupied');
    expect(table.sessionServerId, 82);
    final payload = jsonDecode(table.payloadJson) as Map<String, dynamic>;
    expect(payload['opened_at'], '2026-09-09T13:00:00.000Z');
    final open = await (db.select(db.localSessions)
          ..where((t) =>
              t.tableLocalId.equals(LocalIds.table(1, 8)) &
              t.status.equals('open')))
        .get();
    expect(open, hasLength(1));
    expect(open.single.localId, isNot('sess-first'));
    expect(
      open.single.openedAt.toUtc().toIso8601String(),
      '2026-09-09T13:00:00.000Z',
    );
  });
}
