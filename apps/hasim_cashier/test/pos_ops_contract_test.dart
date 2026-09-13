import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_ids.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/application/local_data_wipe_service.dart';
import 'package:hasim_cashier/core/pos/pos_errors.dart';
import 'package:hasim_cashier/core/repositories/orders_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_conflict_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/repositories/tables_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_queue_classifier.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late SyncQueueRepository queue;
  late TablesRepository tables;
  late OrdersRepository orders;

  setUp(() {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
    tables = TablesRepository(db, queue);
    orders = OrdersRepository(db, queue);
  });

  tearDown(() async {
    await db.close();
  });

  Future<void> seedTable({int workspaceId = 1, int tableId = 5}) async {
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: LocalIds.table(workspaceId, tableId),
            workspaceId: workspaceId,
            serverId: Value(tableId),
            name: 'T$tableId',
            status: const Value('available'),
            payloadJson: Value(jsonEncode({
              'id': tableId,
              'name': 'T$tableId',
              'status': 'available',
            })),
            updatedAt: DateTime.now(),
          ),
        );
  }

  Map<String, dynamic> ackAll(Map<String, dynamic> body) {
    final ops = (body['operations'] as List).cast<Map>();
    var sessionId = 700;
    var orderId = 800;
    var invoiceId = 900;
    return {
      'accepted': [
        for (final op in ops)
          {
            'id': op['id'],
            'status': 'applied',
            'entity_id': op['type'] == 'table_session.open'
                ? ++sessionId
                : op['type'] == 'invoice.created'
                    ? ++invoiceId
                    : ++orderId,
            'result': op['type'] == 'table_session.open'
                ? {
                    'session_id': sessionId,
                    'table_id': 5,
                    'conflict': false,
                    'conflict_status': 'accepted',
                    'local_session_id':
                        (op['data'] as Map)['session_client_id'],
                    'device_id': (op['data'] as Map)['device_id'],
                  }
                : op['type'] == 'invoice.created'
                    ? {
                        'invoice_id': invoiceId,
                        'invoice_number': 'CASH-$invoiceId',
                      }
                    : {'id': orderId, 'order_number': 'ORD-$orderId'},
          },
      ],
      'failed': <Map<String, dynamic>>[],
    };
  }

  test('A/B online/offline table sale stays local-first then syncs session',
      () async {
    await seedTable();
    final opened = await tables.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 5,
    );
    expect(opened['status'], 'occupied');
    expect(opened['session_client_id'], isNotEmpty);

    await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 5,
      clientReference: 'ord-a',
      items: [
        {
          'pos_menu_item_id': 1,
          'name': 'شاي',
          'quantity': 1,
          'unit_price': 10,
          'total_amount': 10,
        },
      ],
    );

    final classified = await SyncQueueClassifier(db).classifyWorkspace(1);
    expect(
      classified.any(
        (item) =>
            item.row.entityType == 'table_session' &&
            item.bucket == SyncQueueBucket.ready,
      ),
      isTrue,
    );

    final closed = await tables.closeSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 5,
      paymentMethod: 'cash',
    );
    expect(closed['invoice'], isA<Map>());

    final seen = <String>[];
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        for (final op in (body['operations'] as List).cast<Map>()) {
          seen.add(op['type'] as String);
        }
        return ackAll(body);
      },
    );
    final report = await engine.pushPending(workspaceId: 1);
    expect(report.synced, greaterThanOrEqualTo(2));
    expect(seen, contains('table_session.open'));
    expect(seen, contains('order.created'));
  });

  test('C multiple offline orders stay on the same session', () async {
    await seedTable();
    final opened = await tables.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 5,
    );
    final sessionId = opened['session_client_id'] as String;
    await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 5,
      clientReference: 'ord-c1',
      items: [
        {
          'pos_menu_item_id': 1,
          'name': 'شاي',
          'quantity': 1,
          'unit_price': 5,
          'total_amount': 5,
        },
      ],
    );
    await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 5,
      clientReference: 'ord-c2',
      items: [
        {
          'pos_menu_item_id': 1,
          'name': 'قهوة',
          'quantity': 1,
          'unit_price': 8,
          'total_amount': 8,
        },
      ],
    );
    final localOrders = await db.select(db.localOrders).get();
    expect(localOrders, hasLength(2));
    expect(
      localOrders.every((row) => row.sessionLocalId == sessionId),
      isTrue,
    );
  });

  test('E two devices on table 5 record conflict without merging sales',
      () async {
    await seedTable();
    await tables.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-b',
      tableServerId: 5,
    );
    await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-b',
      tableId: 5,
      clientReference: 'ord-device-b',
      items: [
        {
          'pos_menu_item_id': 1,
          'name': 'شاي',
          'quantity': 1,
          'unit_price': 10,
          'total_amount': 10,
        },
      ],
    );

    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        final ops = (body['operations'] as List).cast<Map>();
        return {
          'accepted': [
            for (final op in ops)
              if (op['type'] == 'table_session.open')
                {
                  'id': op['id'],
                  'status': 'applied',
                  'entity_id': 88,
                  'result': {
                    'session_id': 88,
                    'accepted_session_id': 88,
                    'server_session_id': 77,
                    'local_session_id': (op['data'] as Map)['session_client_id'],
                    'table_id': 5,
                    'device_id': 'dev-b',
                    'conflict': true,
                    'conflict_status': 'conflict',
                    'reason': 'table_session_open_conflict_other_device',
                  },
                }
              else
                {
                  'id': op['id'],
                  'status': 'applied',
                  'entity_id': 801,
                  'result': {'id': 801},
                },
          ],
          'failed': <Map<String, dynamic>>[],
        };
      },
    );
    await engine.pushPending(workspaceId: 1);

    final conflicts = await SyncConflictRepository(db).openForWorkspace(1);
    expect(conflicts, isNotEmpty);
    final local = jsonDecode(conflicts.first.localJson) as Map;
    final server = jsonDecode(conflicts.first.serverJson) as Map;
    expect(local['table_id'], 5);
    expect(local['device_id'], 'dev-b');
    expect(server['server_session_id'], 77);
    expect(await db.select(db.localOrders).get(), hasLength(1));
  });

  test('F wipe is blocked while unsynced order exists', () async {
    await seedTable();
    await tables.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 5,
    );
    await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 5,
      clientReference: 'ord-wipe',
      items: [
        {
          'pos_menu_item_id': 1,
          'name': 'شاي',
          'quantity': 1,
          'unit_price': 10,
          'total_amount': 10,
        },
      ],
    );
    final wipe = LocalDataWipeService(db, queue: queue);
    await expectLater(
      wipe.deleteAllOrders(
        workspaceId: 1,
        permissions: LocalAuthService.adminPermissions,
      ),
      throwsA(isA<UnsyncedWipeBlocked>()),
    );
    expect(await db.select(db.localOrders).get(), hasLength(1));
  });

  test('G duplicate operation uuid does not duplicate local rows', () async {
    await seedTable();
    await tables.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 5,
    );
    final openOp = (await queue.pendingForWorkspace(1)).singleWhere(
      (row) => row.entityType == 'table_session',
    );
    final uuid = openOp.operationUuid;
    var posts = 0;
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        posts++;
        final ops = (body['operations'] as List).cast<Map>();
        expect(ops.single['id'], uuid);
        return ackAll(body);
      },
    );
    await engine.pushPending(workspaceId: 1);
    await engine.pushPending(workspaceId: 1);
    expect(posts, 1);
    expect(await db.select(db.localSessions).get(), hasLength(1));
  });
}
