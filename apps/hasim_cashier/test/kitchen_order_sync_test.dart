import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_ids.dart';
import 'package:hasim_cashier/core/pos/application/kitchen_local_service.dart';
import 'package:hasim_cashier/core/repositories/orders_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/repositories/tables_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_pull_applier.dart';
import 'package:hasim_cashier/core/sync/sync_queue_classifier.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase cashier;
  late AppDatabase kitchen;
  late SyncQueueRepository queue;
  late OrdersRepository orders;
  late TablesRepository tables;

  const workspaceId = 10;
  const deviceId = 'POS-KITCHEN';
  const productLocalId = 'w10_burger';
  const productServerId = 42;

  Future<void> _seedCatalog(AppDatabase db) async {
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
            localId: LocalIds.table(workspaceId, 5),
            workspaceId: workspaceId,
            serverId: const Value(5),
            name: 'طاولة 5',
            updatedAt: now,
          ),
        );
  }

  Future<Map<String, dynamic>> _pushKitchenOrders() async {
    final captured = <Map<String, dynamic>>[];
    await SyncEngineV2(
      cashier,
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
                'entity_id': 800 + i,
                'result': {'id': 800 + i, 'order_number': 'K-${800 + i}'},
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
    };
  }

  Future<void> _pullOntoKitchen(
    List<Map<String, dynamic>> orderData, {
    int fromCursor = 0,
  }) async {
    var version = fromCursor;
    final changes = <Map<String, dynamic>>[
      for (final data in orderData)
        {
          'version': ++version,
          'entity': 'order',
          'operation': 'create',
          'id': data['id'] ?? version + 40,
          'data': data,
        },
    ];
    await SyncPullApplier(kitchen).applyBatch(
      workspaceId: workspaceId,
      fromCursor: fromCursor,
      responseCursor: version,
      changes: changes,
    );
  }

  setUp(() async {
    cashier = AppDatabase.memory();
    kitchen = AppDatabase.memory();
    queue = SyncQueueRepository(cashier);
    tables = TablesRepository(cashier, queue);
    orders = OrdersRepository(cashier, queue);
    await _seedCatalog(cashier);
    await _seedCatalog(kitchen);
  });

  tearDown(() async {
    await cashier.close();
    await kitchen.close();
  });

  test('TEST 1 unpaid takeaway reaches kitchen without an invoice', () async {
    await orders.createTakeawayOrder(
      workspaceId: workspaceId,
      deviceId: deviceId,
      clientReference: 'tw-burger-2',
      items: [
        {
          'pos_menu_item_id': productServerId,
          'product_local_id': productLocalId,
          'name': 'برجر',
          'quantity': 2,
          'unit_price': 15,
          'total_amount': 30,
        },
      ],
    );

    final local = await (cashier.select(cashier.localOrders)
          ..where((t) => t.clientReference.equals('tw-burger-2')))
        .getSingle();
    expect(local.paymentStatus, 'unpaid');
    expect(local.posStatus, 'new');
    expect(
      (await SyncQueueClassifier(cashier).classifyWorkspace(workspaceId))
          .single
          .bucket,
      SyncQueueBucket.ready,
    );

    final pushed = await _pushKitchenOrders();
    expect(pushed['types'], ['order.created']);
    final kitchenPayload =
        (pushed['orders'] as List).cast<Map<String, dynamic>>().single;
    expect(kitchenPayload['order_type'], 'takeaway');
    expect(kitchenPayload['client_reference'], 'tw-burger-2');

    await _pullOntoKitchen([
      {
        ...kitchenPayload,
        'id': 801,
        'pos_status': 'new',
        'payment_status': 'pending',
        'items': [
          {
            'id': 1,
            'pos_menu_item_id': productServerId,
            'product_name': 'برجر',
            'quantity': 2,
          },
        ],
      },
    ]);

    final tickets =
        await KitchenLocalService(kitchen).watchActive(workspaceId).first;
    expect(tickets, hasLength(1));
    expect(tickets.single['items'].single['name'], 'برجر');
    expect(tickets.single['items'].single['quantity'], 2);
    expect(await (kitchen.select(kitchen.localInvoices)).get(), isEmpty);
    expect(await (kitchen.select(kitchen.localPayments)).get(), isEmpty);
  });

  test('TEST 2 unpaid table ticket carries table name and sitting id', () async {
    await tables.openSessionLocal(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableServerId: 5,
    );
    await orders.createTableOrder(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableId: 5,
      clientReference: 'table-pizza-1',
      items: [
        {
          'pos_menu_item_id': productServerId,
          'product_local_id': productLocalId,
          'name': 'بيتزا',
          'quantity': 1,
          'unit_price': 20,
          'total_amount': 20,
        },
      ],
    );

    final pushed = await _pushKitchenOrders();
    // The sitting must reach Laravel before (or with) its first ticket so the
    // order attaches to the same session instead of opening a second one.
    expect(pushed['types'], ['table_session.open', 'order.created']);
    final payload =
        (pushed['orders'] as List).cast<Map<String, dynamic>>().single;
    expect(payload['dining_table_id'], 5);
    expect(payload['table_name'], 'طاولة 5');
    expect(payload['session_local_id'], isNotNull);
    expect(payload['payment_status'], 'unpaid');

    await _pullOntoKitchen([
      {
        ...payload,
        'id': 910,
        'pos_status': 'new',
        'payment_status': 'pending',
        'items': [
          {
            'id': 9,
            'pos_menu_item_id': productServerId,
            'product_name': 'بيتزا',
            'quantity': 1,
          },
        ],
      },
    ]);

    final tickets =
        await KitchenLocalService(kitchen).watchActive(workspaceId).first;
    expect(tickets.single['table']['name'], 'طاولة 5');
    expect(tickets.single['items'].single['name'], 'بيتزا');
  });

  test('TEST 3 unpaid delivery reaches the kitchen board', () async {
    final now = DateTime.now();
    await cashier.into(cashier.localOrders).insert(
          LocalOrdersCompanion.insert(
            localId: 'del-1',
            workspaceId: workspaceId,
            deviceId: deviceId,
            clientReference: 'del-1',
            orderType: 'delivery',
            posStatus: const Value('new'),
            paymentStatus: const Value('unpaid'),
            createdAt: now,
            updatedAt: now,
          ),
        );
    await queue.enqueue(
      workspaceId: workspaceId,
      deviceId: deviceId,
      entityType: 'order',
      entityId: 'del-1',
      operation: 'create',
      payload: {
        'order_type': 'delivery',
        'client_reference': 'del-1',
        'pos_status': 'new',
        'payment_status': 'unpaid',
        'items': [
          {
            'pos_menu_item_id': productServerId,
            'quantity': 1,
            'name': 'برجر',
          },
        ],
      },
      clientReference: 'del-1',
    );

    expect(
      (await SyncQueueClassifier(cashier).classifyWorkspace(workspaceId))
          .single
          .bucket,
      SyncQueueBucket.ready,
    );
    final pushed = await _pushKitchenOrders();
    expect(
      (pushed['orders'] as List).single['order_type'],
      'delivery',
    );

    await _pullOntoKitchen([
      {
        'id': 330,
        'client_reference': 'del-1',
        'order_type': 'delivery',
        'pos_status': 'new',
        'payment_status': 'pending',
        'items': [
          {'id': 3, 'product_name': 'برجر', 'quantity': 1},
        ],
      },
    ]);
    final tickets =
        await KitchenLocalService(kitchen).watchActive(workspaceId).first;
    expect(tickets.single['order_type'], 'delivery');
  });

  test('TEST 4 offline cashier keeps the ticket local until a later push', () async {
    await orders.createTakeawayOrder(
      workspaceId: workspaceId,
      deviceId: deviceId,
      clientReference: 'offline-tw',
      items: [
        {
          'pos_menu_item_id': productServerId,
          'product_local_id': productLocalId,
          'name': 'برجر',
          'quantity': 1,
          'unit_price': 15,
          'total_amount': 15,
        },
      ],
    );
    expect(
      await KitchenLocalService(kitchen).watchActive(workspaceId).first,
      isEmpty,
    );
    expect(await queue.pendingCount(workspaceId), 1);

    final pushed = await _pushKitchenOrders();
    await _pullOntoKitchen([
      {
        ...(pushed['orders'] as List).single as Map<String, dynamic>,
        'id': 401,
        'pos_status': 'new',
        'items': [
          {'id': 4, 'product_name': 'برجر', 'quantity': 1},
        ],
      },
    ]);
    expect(
      await KitchenLocalService(kitchen).watchActive(workspaceId).first,
      hasLength(1),
    );
  });

  test('TEST 5 duplicate pull keeps a single kitchen ticket', () async {
    const change = {
      'version': 1,
      'entity': 'order',
      'operation': 'create',
      'id': 55,
      'data': {
        'id': 55,
        'client_reference': 'dup-1',
        'order_type': 'takeaway',
        'pos_status': 'new',
        'payment_status': 'pending',
        'items': [
          {'id': 1, 'product_name': 'برجر', 'quantity': 2},
        ],
      },
    };
    final applier = SyncPullApplier(kitchen);
    await applier.applyBatch(
      workspaceId: workspaceId,
      fromCursor: 0,
      responseCursor: 1,
      changes: [change],
    );
    await applier.applyBatch(
      workspaceId: workspaceId,
      fromCursor: 1,
      responseCursor: 2,
      changes: [
        {
          ...change,
          'version': 2,
          'operation': 'update',
          'data': {
            ...change['data']! as Map<String, dynamic>,
            'pos_status': 'accepted',
          },
        },
      ],
    );
    final rows = await (kitchen.select(kitchen.localOrders)).get();
    expect(rows, hasLength(1));
    expect(rows.single.posStatus, 'accepted');
    expect(
      await KitchenLocalService(kitchen).watchActive(workspaceId).first,
      hasLength(1),
    );
  });

  test('TEST 6 kitchen pull never writes invoices or payments', () async {
    await _pullOntoKitchen([
      {
        'id': 77,
        'client_reference': 'no-inv',
        'order_type': 'table',
        'dining_table_id': 5,
        'table_name': 'طاولة 5',
        'pos_status': 'new',
        'payment_status': 'pending',
        'items': [
          {'id': 8, 'product_name': 'برجر', 'quantity': 1},
        ],
      },
    ]);
    expect(await (kitchen.select(kitchen.localInvoices)).get(), isEmpty);
    expect(await (kitchen.select(kitchen.localPayments)).get(), isEmpty);
    expect(await (kitchen.select(kitchen.localOrders)).get(), hasLength(1));

    await _pullOntoKitchen(
      [
        {
          'id': 79,
          'client_reference': 'paid-tw',
          'order_type': 'takeaway',
          'pos_status': 'completed',
          'payment_status': 'paid',
          'items': [
            {'id': 81, 'product_name': 'برجر', 'quantity': 2},
          ],
        },
      ],
      fromCursor: 1,
    );
    expect(await (kitchen.select(kitchen.localInvoices)).get(), isEmpty);
    expect(await (kitchen.select(kitchen.localPayments)).get(), isEmpty);
    final tickets =
        await KitchenLocalService(kitchen).watchActive(workspaceId).first;
    expect(tickets, hasLength(2));
    expect(
      tickets.map((t) => t['pos_status']),
      everyElement(isNot('completed')),
    );
  });

  test('TEST 7 closed sitting leaves the board before the next sitting', () async {
    await _pullOntoKitchen([
      {
        'id': 1,
        'client_reference': 'sit1-burger',
        'order_type': 'table',
        'dining_table_id': 5,
        'table_name': 'طاولة 5',
        'session_local_id': 'sitting-1',
        'pos_status': 'new',
        'items': [
          {'id': 11, 'product_name': 'برجر', 'quantity': 1},
        ],
      },
      {
        'id': 2,
        'client_reference': 'sit1-cola',
        'order_type': 'table',
        'dining_table_id': 5,
        'table_name': 'طاولة 5',
        'session_local_id': 'sitting-1',
        'pos_status': 'new',
        'items': [
          {'id': 12, 'product_name': 'كولا', 'quantity': 1},
        ],
      },
    ]);
    expect(
      await KitchenLocalService(kitchen).watchActive(workspaceId).first,
      hasLength(2),
    );

    await SyncPullApplier(kitchen).applyBatch(
      workspaceId: workspaceId,
      fromCursor: 2,
      responseCursor: 4,
      changes: [
        {
          'version': 3,
          'entity': 'order',
          'operation': 'update',
          'id': 1,
          'data': {
            'id': 1,
            'client_reference': 'sit1-burger',
            'order_type': 'table',
            'pos_status': 'completed',
            'payment_status': 'paid',
            'items': [
              {'id': 11, 'product_name': 'برجر', 'quantity': 1},
            ],
          },
        },
        {
          'version': 4,
          'entity': 'order',
          'operation': 'update',
          'id': 2,
          'data': {
            'id': 2,
            'client_reference': 'sit1-cola',
            'order_type': 'table',
            'pos_status': 'completed',
            'payment_status': 'paid',
            'items': [
              {'id': 12, 'product_name': 'كولا', 'quantity': 1},
            ],
          },
        },
      ],
    );
    expect(
      await KitchenLocalService(kitchen).watchActive(workspaceId).first,
      isEmpty,
    );

    await _pullOntoKitchen(
      [
        {
          'id': 3,
          'client_reference': 'sit2-pizza',
          'order_type': 'table',
          'dining_table_id': 5,
          'table_name': 'طاولة 5',
          'session_local_id': 'sitting-2',
          'pos_status': 'new',
          'items': [
            {'id': 13, 'product_name': 'بيتزا', 'quantity': 1},
          ],
        },
      ],
      fromCursor: 4,
    );
    final tickets =
        await KitchenLocalService(kitchen).watchActive(workspaceId).first;
    expect(tickets, hasLength(1));
    expect(tickets.single['items'].single['name'], 'بيتزا');
    expect(
      tickets.map((t) => t['items'].single['name']),
      isNot(contains('برجر')),
    );
  });

  test('TEST 8 table-menu Laravel payload without client_reference reaches kitchen',
      () async {
    await SyncPullApplier(kitchen).applyBatch(
      workspaceId: workspaceId,
      fromCursor: 0,
      responseCursor: 2,
      changes: [
        {
          'version': 1,
          'entity': 'table',
          'operation': 'update',
          'id': 5,
          'data': {
            'id': 5,
            'name': 'طاولة 5',
            'status': 'occupied',
          },
        },
        {
          'version': 2,
          'entity': 'order',
          'operation': 'update',
          'id': 501,
          'data': {
            'id': 501,
            'client_reference': null,
            'order_type': 'table',
            'dining_table_id': 5,
            'table_name': 'طاولة 5',
            'table_session_id': 88,
            'pos_status': 'new',
            'payment_status': 'pending',
            'items': [
              {
                'id': 9,
                'product_name': 'برجر',
                'quantity': 1,
                'unit_price': 10,
                'total_amount': 10,
              },
            ],
          },
        },
      ],
    );
    final tickets =
        await KitchenLocalService(kitchen).watchActive(workspaceId).first;
    expect(tickets, hasLength(1));
    expect(tickets.single['items'].single['name'], 'برجر');
    expect(tickets.single['pos_status'], 'new');
  });

  test('TEST 9 UUID table master plus occupied update does not drop the ticket',
      () async {
    final now = DateTime.now();
    await kitchen.into(kitchen.localTables).insert(
          LocalTablesCompanion.insert(
            localId: 'uuid-table-9',
            workspaceId: workspaceId,
            serverId: const Value(9),
            name: 'طاولة 9',
            updatedAt: now,
          ),
        );

    await SyncPullApplier(kitchen).applyBatch(
      workspaceId: workspaceId,
      fromCursor: 0,
      responseCursor: 2,
      changes: [
        {
          'version': 1,
          'entity': 'order',
          'operation': 'create',
          'id': 777,
          'data': {
            'id': 777,
            'client_reference': null,
            'order_type': 'table',
            'dining_table_id': 9,
            'table_name': 'طاولة 9',
            'pos_status': 'new',
            'payment_status': 'pending',
            'items': [
              {
                'id': 70,
                'product_name': 'كولا',
                'quantity': 1,
              },
            ],
          },
        },
        {
          'version': 2,
          'entity': 'table',
          'operation': 'update',
          'id': 9,
          'data': {
            'id': 9,
            'name': 'طاولة 9',
            'status': 'occupied',
          },
        },
      ],
    );

    final tickets =
        await KitchenLocalService(kitchen).watchActive(workspaceId).first;
    expect(tickets, hasLength(1));
    expect(tickets.single['items'].single['name'], 'كولا');
    final tables = await (kitchen.select(kitchen.localTables)
          ..where((t) => t.serverId.equals(9)))
        .get();
    expect(tables, hasLength(1));
    expect(tables.single.localId, 'uuid-table-9');
  });
}
