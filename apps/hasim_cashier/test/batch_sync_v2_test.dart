import 'package:drift/drift.dart' show Value;
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/workspace_scope.dart';
import 'package:hasim_cashier/core/network/link_policy.dart';
import 'package:hasim_cashier/core/repositories/orders_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/widgets/hasim_widgets.dart';

void main() {
  late AppDatabase db;

  setUp(() {
    db = AppDatabase.memory();
  });

  tearDown(() async {
    await db.close();
  });

  test('backoff schedule is 2, 5, 15, 30 then capped', () {
    expect(SyncQueueRepository.backoffSecondsForAttempt(1), 2);
    expect(SyncQueueRepository.backoffSecondsForAttempt(2), 5);
    expect(SyncQueueRepository.backoffSecondsForAttempt(3), 15);
    expect(SyncQueueRepository.backoffSecondsForAttempt(4), 30);
    expect(SyncQueueRepository.backoffSecondsForAttempt(8), 300);
  });

  test('batch push is idempotent and records last_push_at', () async {
    final queue = SyncQueueRepository(db);
    final now = DateTime.now();
    await db.into(db.localOrders).insert(
          LocalOrdersCompanion.insert(
            localId: 'batch-ord',
            workspaceId: 1,
            deviceId: 'POS-001',
            clientReference: 'batch-ord',
            orderType: 'takeaway',
            createdAt: now,
            updatedAt: now,
          ),
        );
    await queue.enqueue(
      workspaceId: 1,
      deviceId: 'POS-001',
      entityType: 'order',
      entityId: 'batch-ord',
      operation: 'create',
      payload: {
        'order_type': 'takeaway',
        'client_reference': 'batch-ord',
        'items': [
          {'pos_menu_item_id': 10, 'quantity': 1},
        ],
      },
      clientReference: 'batch-ord',
      operationUuid: 'op-batch-1',
    );

    var posts = 0;
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        posts++;
        expect(body['device_id'], 'POS-001');
        final ops = body['operations'] as List;
        expect(ops, hasLength(1));
        expect(ops.first['id'], 'op-batch-1');
        expect(ops.first['type'], 'order.created');
        return {
          'success': true,
          'accepted': [
            {
              'id': 'op-batch-1',
              'status': posts == 1 ? 'applied' : 'duplicate',
              'entity_id': 44,
              'result': {'id': 44},
            },
          ],
          'failed': const [],
          'server_cursor': 9,
        };
      },
      fetchChanges: (since, limit) async => {
        'cursor': since,
        'server_cursor': 9,
        'has_more': false,
        'changes': const [],
      },
    );

    final first = await engine.pushPending(workspaceId: 1);
    expect(first.synced, 1);
    expect(posts, 1);
    final order = await (db.select(db.localOrders)
          ..where((t) => t.localId.equals('batch-ord')))
        .getSingle();
    expect(order.serverId, 44);
    expect(order.syncStatus, 'synced');

    final second = await engine.pushPending(workspaceId: 1);
    expect(second.synced, 0);
    expect(posts, 1);
    expect(await db.readMeta(1, SyncMetaKeys.lastPushAt), isNotNull);
  });

  test('sale writes local stock movement without queueing stock.movement',
      () async {
    final queue = SyncQueueRepository(db);
    final orders = OrdersRepository(db, queue);
    await db.into(db.localProducts).insert(
          LocalProductsCompanion.insert(
            localId: 'w1_prod_10',
            workspaceId: 1,
            serverId: const Value(10),
            name: 'شاي',
            stock: const Value(8),
            updatedAt: DateTime.now(),
          ),
        );
    await orders.createTakeawayOrder(
      workspaceId: 1,
      deviceId: 'POS-001',
      clientReference: 'sale-1',
      items: [
        {
          'pos_menu_item_id': 10,
          'name': 'شاي',
          'quantity': 2,
          'unit_price': 5,
          'total_amount': 10,
        },
      ],
    );
    final movements = await db.select(db.localStockMovements).get();
    expect(movements, hasLength(1));
    expect(movements.single.kind, 'sale');
    expect(movements.single.syncStatus, 'local');
    final product = await (db.select(db.localProducts)
          ..where((t) => t.serverId.equals(10)))
        .getSingle();
    expect(product.stock, 6);
    final queued = await queue.pendingForWorkspace(1);
    expect(queued.every((r) => r.entityType != 'stock'), isTrue);
  });

  testWidgets('connection banner always shows online status', (tester) async {
    await tester.pumpWidget(
      const MaterialApp(
        home: Scaffold(
          body: ConnectionBanner(
            link: CashierLink.online,
            lastSyncAt: null,
          ),
        ),
      ),
    );
    expect(find.textContaining('متصل'), findsOneWidget);
    expect(find.textContaining('لم تتم بعد'), findsOneWidget);
  });

  test('order create hydrates the menu id from the local product', () async {
    final queue = SyncQueueRepository(db);
    final now = DateTime.now();
    await db.into(db.localProducts).insert(
          LocalProductsCompanion.insert(
            localId: 'prod-1',
            workspaceId: 1,
            serverId: const Value(5),
            name: 'Tea',
            updatedAt: now,
          ),
        );
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: 'table-1',
            workspaceId: 1,
            serverId: const Value(1),
            name: '1',
            updatedAt: now,
          ),
        );
    await db.into(db.localOrders).insert(
          LocalOrdersCompanion.insert(
            localId: 'ord-1',
            workspaceId: 1,
            deviceId: 'POS-001',
            clientReference: 'ord-1',
            orderType: 'table',
            tableLocalId: const Value('table-1'),
            tableServerId: const Value(1),
            syncStatus: const Value('pending'),
            createdAt: now,
            updatedAt: now,
          ),
        );
    await db.into(db.localOrderItems).insert(
          LocalOrderItemsCompanion.insert(
            localId: 'line-1',
            workspaceId: 1,
            orderLocalId: 'ord-1',
            productLocalId: const Value('prod-1'),
            name: 'Tea',
            quantity: 4,
            unitPrice: 300,
            totalAmount: 1200,
            updatedAt: now,
          ),
        );
    await db.into(db.localSessions).insert(
          LocalSessionsCompanion.insert(
            localId: 'sess-1',
            workspaceId: 1,
            serverId: const Value(8),
            tableLocalId: 'table-1',
            openedAt: now,
            createdAt: now,
            updatedAt: now,
          ),
        );
    await db.into(db.localInvoices).insert(
          LocalInvoicesCompanion.insert(
            localId: 'inv-1',
            workspaceId: 1,
            deviceId: 'POS-001',
            invoiceNumber: const Value('LOCAL-inv-1'),
            localInvoiceNumber: const Value('LOCAL-inv-1'),
            orderLocalId: const Value('ord-1'),
            totalAmount: const Value(1200),
            syncStatus: const Value('pending'),
            createdAt: now,
          ),
        );
    await queue.enqueue(
      workspaceId: 1,
      deviceId: 'POS-001',
      entityType: 'order',
      entityId: 'ord-1',
      operation: 'create',
      clientReference: 'ord-1',
      payload: {
        'order_type': 'table',
        'offline_sale': true,
        'dining_table_id': 1,
        'table_local_id': 'table-1',
        'client_reference': 'ord-1',
        'items': [
          {'pos_menu_item_id': null, 'quantity': 4, 'name': 'Tea'},
        ],
      },
    );
    await queue.enqueue(
      workspaceId: 1,
      deviceId: 'POS-001',
      entityType: 'invoice',
      entityId: 'inv-1',
      operation: 'create',
      clientReference: 'inv-1',
      payload: {
        'order_type': 'table',
        'order_local_id': 'ord-1',
        'local_invoice_number': 'LOCAL-inv-1',
      },
    );
    await queue.enqueue(
      workspaceId: 1,
      deviceId: 'POS-001',
      entityType: 'table_session',
      entityId: 'table-1',
      operation: 'close',
      clientReference: 'close-1',
      payload: {
        'table_local_id': 'table-1',
        'session_client_id': 'sess-1',
        'payment_method': 'cash',
      },
    );

    final sent = <Map<String, dynamic>>[];
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        final ops = (body['operations'] as List).cast<Map>();
        sent.addAll(ops.map(Map<String, dynamic>.from));
        return {
          'accepted': [
            for (final op in ops)
              {
                'id': op['id'],
                'entity_id': op['type'] == 'order.created'
                    ? 44
                    : op['type'] == 'invoice.created'
                    ? 55
                    : 8,
                'result': {
                  'id': op['type'] == 'order.created'
                      ? 44
                      : op['type'] == 'invoice.created'
                      ? 55
                      : 8,
                  if (op['type'] == 'invoice.created')
                    'invoice_number': 'CASH-00000021',
                },
              },
          ],
          'failed': [],
        };
      },
    );
    final report = await engine.pushPending(workspaceId: 1);
    expect(report.failed, 0);
    expect(sent.map((op) => op['type']), [
      'order.created',
      'invoice.created',
      'table_session.close',
    ]);
    final orderData = sent.first['data'] as Map;
    final orderItems = orderData['items'] as List;
    expect(orderItems.single['pos_menu_item_id'], 5);
    final closeData = sent.last['data'] as Map;
    expect(closeData['table_server_id'], 1);
    expect(closeData['session_server_id'], 8);
    final invoice = await (db.select(
      db.localInvoices,
    )..where((t) => t.localId.equals('inv-1'))).getSingle();
    expect(invoice.serverInvoiceNumber, 'CASH-00000021');
  });

  test('offline takeaway with no queue row is sent on the next sync', () async {
    final queue = SyncQueueRepository(db);
    final now = DateTime.now();
    await db.into(db.localProducts).insert(
          LocalProductsCompanion.insert(
            localId: 'prod-2',
            workspaceId: 1,
            serverId: const Value(9),
            name: 'Juice',
            updatedAt: now,
          ),
        );
    await db.into(db.localOrders).insert(
          LocalOrdersCompanion.insert(
            localId: 'tw-1',
            workspaceId: 1,
            deviceId: 'POS-001',
            clientReference: 'tw-1',
            orderType: 'takeaway',
            syncStatus: const Value('local'),
            totalAmount: const Value(1500),
            createdAt: now,
            updatedAt: now,
          ),
        );
    await db.into(db.localOrderItems).insert(
          LocalOrderItemsCompanion.insert(
            localId: 'line-tw',
            workspaceId: 1,
            orderLocalId: 'tw-1',
            productLocalId: const Value('prod-2'),
            name: 'Juice',
            quantity: 1,
            unitPrice: 1500,
            totalAmount: 1500,
            updatedAt: now,
          ),
        );
    await db.into(db.localInvoices).insert(
          LocalInvoicesCompanion.insert(
            localId: 'inv-tw',
            workspaceId: 1,
            deviceId: 'POS-001',
            invoiceNumber: const Value('INV-000001'),
            localInvoiceNumber: const Value('INV-000001'),
            orderLocalId: const Value('tw-1'),
            totalAmount: const Value(1500),
            syncStatus: const Value('local'),
            createdAt: now,
          ),
        );
    final sent = <String>[];
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        final ops = (body['operations'] as List).cast<Map>();
        sent.addAll(ops.map((op) => op['type'] as String));
        return {
          'accepted': [
            for (final op in ops)
              {
                'id': op['id'],
                'entity_id': op['type'] == 'order.created' ? 70 : 71,
                'result': {
                  'id': op['type'] == 'order.created' ? 70 : 71,
                  if (op['type'] == 'invoice.created')
                    'invoice_number': 'CASH-00000002',
                },
              },
          ],
          'failed': [],
        };
      },
    );
    await engine.pushPending(workspaceId: 1);
    expect(sent, ['order.created', 'invoice.created']);
    final order = await (db.select(
      db.localOrders,
    )..where((t) => t.localId.equals('tw-1'))).getSingle();
    expect(order.serverId, 70);
    expect(order.syncStatus, 'synced');
  });
}
