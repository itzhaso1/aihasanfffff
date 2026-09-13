import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_ids.dart';
import 'package:hasim_cashier/core/pos/application/kitchen_local_service.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/table_session_orders.dart';
import 'package:hasim_cashier/core/repositories/local_finance_repository.dart';
import 'package:hasim_cashier/core/repositories/orders_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/repositories/tables_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_pull_applier.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase cashier;
  late AppDatabase kitchen;
  late SyncQueueRepository cashierQueue;
  late SyncQueueRepository kitchenQueue;
  late OrdersRepository orders;
  late TablesRepository tables;
  late LocalFinanceRepository finance;
  late KitchenLocalService kitchenBoard;
  late KitchenLocalService cashierKitchen;

  const workspaceId = 10;
  const deviceId = 'POS-CASHIER';
  const kitchenDeviceId = 'POS-KITCHEN';
  const productLocalId = 'w10_burger';
  const productServerId = 42;
  const tableId = 5;

  final burger = {
    'pos_menu_item_id': productServerId,
    'product_local_id': productLocalId,
    'name': 'برجر',
    'quantity': 1,
    'unit_price': 15,
    'total_amount': 15,
  };

  Future<void> seedCatalog(AppDatabase db) async {
    final now = DateTime.now();
    await db.into(db.localProducts).insert(
          LocalProductsCompanion.insert(
            localId: productLocalId,
            workspaceId: workspaceId,
            serverId: const Value(productServerId),
            name: 'برجر',
            price: const Value(1500),
            updatedAt: now,
          ),
        );
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: LocalIds.table(workspaceId, tableId),
            workspaceId: workspaceId,
            serverId: const Value(tableId),
            name: 'طاولة 5',
            status: const Value('available'),
            payloadJson: Value(
              jsonEncode({
                'id': tableId,
                'name': 'طاولة 5',
                'status': 'available',
              }),
            ),
            updatedAt: now,
          ),
        );
  }

  Future<Map<String, dynamic>> createFromCashier({
    required String clientReference,
    List<Map<String, dynamic>>? items,
  }) {
    return orders.createCashierTableOrder(
      tables: tables,
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableId: tableId,
      clientReference: clientReference,
      items: items ?? [burger],
    );
  }

  Future<Map<String, dynamic>> pushOrders(AppDatabase db, SyncQueueRepository queue) async {
    final captured = <Map<String, dynamic>>[];
    await SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        final ops = (body['operations'] as List).cast<Map>();
        for (final op in ops) {
          captured.add(Map<String, dynamic>.from(op));
        }
        return {
          'accepted': [
            for (var i = 0; i < ops.length; i++)
              {
                'id': ops[i]['id'],
                'status': 'applied',
                'entity_id': 900 + i,
                'result': {
                  'id': 900 + i,
                  'order_number': 'K-${900 + i}',
                  'pos_status': ops[i]['data']?['pos_status'] ?? 'new',
                },
              },
          ],
          'failed': <Map<String, dynamic>>[],
        };
      },
    ).pushPending(workspaceId: workspaceId);
    return {
      'types': [for (final op in captured) op['type']],
      'orders': [
        for (final op in captured)
          if (op['type'] == 'order.created')
            Map<String, dynamic>.from(op['data'] as Map),
      ],
      'updates': [
        for (final op in captured)
          if (op['type'] == 'order.updated')
            Map<String, dynamic>.from(op['data'] as Map),
      ],
    };
  }

  Future<void> pullKitchenOrder(
    Map<String, dynamic> data, {
    int fromCursor = 0,
    String operation = 'create',
    int? id,
  }) async {
    final serverId = id ?? (data['id'] as num?)?.toInt() ?? fromCursor + 900;
    await SyncPullApplier(kitchen).applyBatch(
      workspaceId: workspaceId,
      fromCursor: fromCursor,
      responseCursor: fromCursor + 1,
      changes: [
        {
          'version': fromCursor + 1,
          'entity': 'order',
          'operation': operation,
          'id': serverId,
          'data': {
            ...data,
            'id': serverId,
            'pos_status': data['pos_status'] ?? 'new',
            'payment_status': data['payment_status'] ?? 'pending',
            'items': data['items'] ??
                [
                  {
                    'id': 1,
                    'pos_menu_item_id': productServerId,
                    'product_name': 'برجر',
                    'quantity': 1,
                  },
                ],
          },
        },
      ],
    );
  }

  setUp(() async {
    cashier = AppDatabase.memory();
    kitchen = AppDatabase.memory();
    cashierQueue = SyncQueueRepository(cashier);
    kitchenQueue = SyncQueueRepository(kitchen);
    tables = TablesRepository(cashier, cashierQueue);
    orders = OrdersRepository(cashier, cashierQueue);
    finance = LocalFinanceRepository(cashier);
    cashierKitchen = KitchenLocalService(cashier);
    kitchenBoard = KitchenLocalService(
      kitchen,
      queue: kitchenQueue,
      deviceId: () async => kitchenDeviceId,
    );
    await seedCatalog(cashier);
    await seedCatalog(kitchen);
  });

  tearDown(() async {
    await cashier.close();
    await kitchen.close();
  });

  test('TEST 1 cashier table create starts NEW unpaid without an invoice', () async {
    final created = await createFromCashier(clientReference: 'cashier-table-1');
    expect(created['pos_status'], 'new');
    expect(created['payment_status'], 'unpaid');
    expect(created['order_type'], 'table');

    final stored = await (cashier.select(cashier.localOrders)
          ..where((t) => t.clientReference.equals('cashier-table-1')))
        .getSingle();
    expect(stored.posStatus, 'new');
    expect(stored.paymentStatus, 'unpaid');
    expect(stored.completedAt, isNull);

    expect(await (cashier.select(cashier.localInvoices)).get(), isEmpty);
    expect(await (cashier.select(cashier.localPayments)).get(), isEmpty);

    final queued = await cashier.select(cashier.syncQueueItems).get();
    expect(
      queued.map((row) => '${row.entityType}.${row.operation}'),
      isNot(contains('invoice.create')),
    );
    final orderOp = queued.singleWhere((row) => row.entityType == 'order');
    final payload = jsonDecode(orderOp.payloadJson) as Map<String, dynamic>;
    expect(payload['pos_status'], 'new');
    expect(payload['payment_status'], 'unpaid');
    expect(payload['offline_sale'], isTrue);
    expect(payload['order_type'], 'table');

    final open = await orders.listOpenForTable(
      workspaceId: workspaceId,
      tableId: tableId,
    );
    expect(open, hasLength(1));
    expect(open.single['pos_status'], 'new');
    expect(isPaidTableOrder(open.single), isFalse);

    final board = await cashierKitchen.watchBoard(workspaceId).first;
    expect(board.active, hasLength(1));
    expect(board.active.single['pos_status'], 'new');
    expect(board.delivered, isEmpty);
  });

  test('TEST 2 kitchen device receives the ticket as Active not Completed', () async {
    await createFromCashier(clientReference: 'cashier-table-2');
    final pushed = await pushOrders(cashier, cashierQueue);
    expect(pushed['types'], contains('order.created'));
    expect(pushed['types'], isNot(contains('invoice.created')));
    final payload = (pushed['orders'] as List).cast<Map<String, dynamic>>().single;
    expect(payload['pos_status'], 'new');
    expect(payload['payment_status'], 'unpaid');

    await pullKitchenOrder({
      ...payload,
      'id': 910,
      'pos_status': 'new',
      'payment_status': 'pending',
    });

    final board = await kitchenBoard.watchBoard(workspaceId).first;
    expect(board.active, hasLength(1));
    expect(board.active.single['pos_status'], 'new');
    expect(board.delivered, isEmpty);
    expect(await (kitchen.select(kitchen.localInvoices)).get(), isEmpty);
  });

  test('TEST 3 / 4 kitchen READY then DELIVERED syncs and leaves Active', () async {
    await createFromCashier(clientReference: 'cashier-table-3');
    final pushed = await pushOrders(cashier, cashierQueue);
    final payload = (pushed['orders'] as List).cast<Map<String, dynamic>>().single;
    await pullKitchenOrder({...payload, 'id': 501, 'pos_status': 'new'});

    final kitchenOrder = (await (kitchen.select(kitchen.localOrders)).get()).single;
    await kitchenBoard.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: kitchenOrder.localId,
      status: 'preparing',
      permissions: LocalAuthService.adminPermissions,
    );
    expect(
      (await kitchenBoard.watchBoard(workspaceId).first).active.single['pos_status'],
      'preparing',
    );

    await kitchenBoard.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: kitchenOrder.localId,
      status: 'ready',
      permissions: LocalAuthService.adminPermissions,
    );
    final readyPush = await pushOrders(kitchen, kitchenQueue);
    expect(readyPush['types'], contains('order.updated'));
    expect(
      (readyPush['updates'] as List).cast<Map<String, dynamic>>().single['pos_status'],
      'ready',
    );

    await SyncPullApplier(cashier).applyBatch(
      workspaceId: workspaceId,
      fromCursor: 0,
      responseCursor: 1,
      changes: [
        {
          'version': 1,
          'entity': 'order',
          'operation': 'update',
          'id': 501,
          'data': {
            'id': 501,
            'client_reference': 'cashier-table-3',
            'order_type': 'table',
            'pos_status': 'ready',
            'payment_status': 'unpaid',
            'kitchen_status': true,
          },
        },
      ],
    );
    expect(
      (await cashierKitchen.watchBoard(workspaceId).first).active.single['pos_status'],
      'ready',
    );

    await kitchenBoard.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: kitchenOrder.localId,
      status: 'delivered',
      permissions: LocalAuthService.adminPermissions,
    );
    await pushOrders(kitchen, kitchenQueue);
    await SyncPullApplier(cashier).applyBatch(
      workspaceId: workspaceId,
      fromCursor: 1,
      responseCursor: 2,
      changes: [
        {
          'version': 2,
          'entity': 'order',
          'operation': 'update',
          'id': 501,
          'data': {
            'id': 501,
            'client_reference': 'cashier-table-3',
            'order_type': 'table',
            'pos_status': 'delivered',
            'payment_status': 'unpaid',
            'kitchen_status': true,
          },
        },
      ],
    );

    final kitchenAfter = await kitchenBoard.watchBoard(workspaceId).first;
    final cashierAfter = await cashierKitchen.watchBoard(workspaceId).first;
    expect(kitchenAfter.active, isEmpty);
    expect(cashierAfter.active, isEmpty);
    expect(kitchenAfter.delivered.single['pos_status'], 'delivered');
    expect(cashierAfter.delivered.single['pos_status'], 'delivered');
  });

  test('TEST 5 later table close writes one invoice and does not duplicate', () async {
    await createFromCashier(clientReference: 'cashier-table-pay');
    expect(await finance.listInvoices(workspaceId: workspaceId), isEmpty);

    final closed = await tables.closeSessionLocal(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableServerId: tableId,
      paymentMethod: 'cash',
    );
    expect(closed['invoice'], isNotNull);
    expect(closed['freed_only'], isNot(isTrue));

    final invoices = await finance.listInvoices(workspaceId: workspaceId);
    expect(invoices, hasLength(1));

    final closedAgain = await tables.closeSessionLocal(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableServerId: tableId,
      paymentMethod: 'cash',
    );
    expect(closedAgain['freed_only'], isTrue);
    expect(closedAgain['invoice'], isNull);
    expect(await finance.listInvoices(workspaceId: workspaceId), hasLength(1));
  });

  test('TEST 6 table session add still starts NEW and stays isolated', () async {
    await tables.openSessionLocal(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableServerId: tableId,
    );
    await orders.createTableOrder(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableId: tableId,
      clientReference: 'session-add-1',
      items: [burger],
    );
    final open = await orders.listOpenForTable(
      workspaceId: workspaceId,
      tableId: tableId,
    );
    expect(open.single['pos_status'], 'new');
    expect(open.single['payment_status'], 'unpaid');

    await tables.closeSessionLocal(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableServerId: tableId,
      paymentMethod: 'cash',
    );
    await tables.openSessionLocal(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableServerId: tableId,
    );
    expect(
      await orders.listOpenForTable(workspaceId: workspaceId, tableId: tableId),
      isEmpty,
    );
    final sitting2 = await tables.getTable(workspaceId, tableId);
    expect(asMapList(sitting2?['orders']), isEmpty);
  });

  test('TEST 7 offline cashier table create is Active locally then syncs', () async {
    await createFromCashier(clientReference: 'cashier-offline-1');
    final localBoard = await cashierKitchen.watchBoard(workspaceId).first;
    expect(localBoard.active.single['pos_status'], 'new');
    expect(await kitchenBoard.watchActive(workspaceId).first, isEmpty);

    final pushed = await pushOrders(cashier, cashierQueue);
    await pullKitchenOrder({
      ...(pushed['orders'] as List).cast<Map<String, dynamic>>().single,
      'id': 770,
      'pos_status': 'new',
    });
    expect(
      (await kitchenBoard.watchBoard(workspaceId).first).active.single['pos_status'],
      'new',
    );
  });

  test('TEST 8 retry of the same clientReference does not duplicate', () async {
    await createFromCashier(clientReference: 'cashier-retry-1');
    await createFromCashier(clientReference: 'cashier-retry-1');

    final rows = await (cashier.select(cashier.localOrders)
          ..where((t) => t.clientReference.equals('cashier-retry-1')))
        .get();
    expect(rows, hasLength(1));
    expect(rows.single.posStatus, 'new');

    final orderOps = (await cashier.select(cashier.syncQueueItems).get())
        .where((row) => row.entityType == 'order' && row.operation == 'create');
    expect(orderOps, hasLength(1));
    expect(await (cashier.select(cashier.localInvoices)).get(), isEmpty);
  });
}

List<Map<String, dynamic>> asMapList(dynamic raw) {
  if (raw is! List) return const [];
  return [
    for (final row in raw)
      if (row is Map) Map<String, dynamic>.from(row),
  ];
}
