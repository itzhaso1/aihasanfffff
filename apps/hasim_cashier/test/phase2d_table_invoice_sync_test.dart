import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_ids.dart';
import 'package:hasim_cashier/core/pos/application/checkout_service.dart';
import 'package:hasim_cashier/core/pos/application/document_numbers.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/application/shift_service.dart';
import 'package:hasim_cashier/core/pos/application/stock_engine.dart';
import 'package:hasim_cashier/core/pos/domain/pricing_service.dart';
import 'package:hasim_cashier/core/repositories/orders_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/repositories/tables_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_queue_classifier.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late CheckoutService checkout;
  late SyncQueueRepository queue;
  late TablesRepository tables;
  late OrdersRepository orders;
  late ShiftService shifts;

  const workspaceId = 10;
  const deviceId = 'POS-2D';
  const productLocalId = 'w10_latte';
  const productServerId = 9001;

  late String storeId;
  late String userId;
  late String shiftId;

  Future<void> seedTable(int tableId) {
    return db.into(db.localTables).insert(
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

  setUp(() async {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
    tables = TablesRepository(db, queue);
    orders = OrdersRepository(db, queue);
    checkout = CheckoutService(
      db,
      StockEngine(db),
      DocumentNumberService(db),
      queue,
      tables: tables,
    );
    shifts = ShiftService(db);
    final auth = LocalAuthService(db);
    final created = await auth.bootstrapStore(
      storeName: 'Cloud Cafe',
      adminName: 'Cashier',
      username: 'admin',
      pin: '1234',
      taxRate: 15,
    );
    storeId = created.store.localId;
    userId = created.user.localId;

    final now = DateTime.now();
    await db.into(db.localStores).insert(
      LocalStoresCompanion.insert(
        localId: 'w10-store',
        workspaceId: workspaceId,
        name: 'Cloud Cafe WS',
        currency: const Value('SAR'),
        taxRate: const Value(15),
        createdAt: now,
        updatedAt: now,
      ),
    );
    await db.into(db.localProducts).insert(
      LocalProductsCompanion.insert(
        localId: productLocalId,
        workspaceId: workspaceId,
        serverId: const Value(productServerId),
        name: 'Latte',
        sku: const Value('TW-1'),
        price: const Value(1000),
        taxRate: const Value(15),
        stock: const Value(20),
        trackStock: const Value(true),
        updatedAt: now,
      ),
    );
    await seedTable(4);
    await seedTable(5);
    shiftId = await shifts.open(
      workspaceId: workspaceId,
      userId: userId,
      openingCash: 100,
      permissions: LocalAuthService.adminPermissions,
    );
  });

  tearDown(() async {
    await db.close();
  });

  Future<CheckoutResult> sellTable({
    required String clientReference,
    int tableServerId = 4,
    double unitPrice = 10,
  }) {
    final lines = [
      PricedLine(
        productLocalId: productLocalId,
        productServerId: productServerId,
        name: 'Latte',
        quantity: 1,
        unitPrice: unitPrice,
        taxRate: 15,
      ),
    ];
    final quote = const PricingService().quote(
      lines: lines,
      fallbackTaxRate: 15,
    );
    return checkout.execute(
      CheckoutCommand(
        workspaceId: workspaceId,
        deviceId: deviceId,
        storeId: storeId,
        permissions: LocalAuthService.adminPermissions,
        clientReference: clientReference,
        orderType: 'table',
        tableLocalId: LocalIds.table(workspaceId, tableServerId),
        tableServerId: tableServerId,
        lines: lines,
        payments: [
          PaymentTender(
            method: 'cash',
            amount: quote.total,
            tendered: quote.total,
          ),
        ],
        taxRate: 15,
        createdByUserId: userId,
        shiftLocalId: shiftId,
      ),
    );
  }

  Map<String, dynamic> ackBatch(
    Map<String, dynamic> body, {
    String status = 'applied',
    int orderId = 4401,
    String orderNumber = 'TB-1001',
    int invoiceId = 8801,
    String invoiceNumber = 'CASH-00000021',
  }) {
    final ops = (body['operations'] as List).cast<Map>();
    return {
      'accepted': [
        for (final op in ops)
          {
            'id': op['id'],
            'status': status,
            'entity_id': op['type'] == 'invoice.created' ? invoiceId : orderId,
            'result': op['type'] == 'invoice.created'
                ? {
                    'invoice_id': invoiceId,
                    'id': invoiceId,
                    'invoice_number': invoiceNumber,
                    'total_amount': 11.5,
                    'currency': 'SAR',
                    'payment_method': 'cash',
                    'payment_status': 'paid',
                  }
                : {'id': orderId, 'order_number': orderNumber},
          },
      ],
      'failed': <Map<String, dynamic>>[],
    };
  }

  test('cashier table cash checkout enqueues order+invoice with offline_sale', () async {
    final result = await sellTable(clientReference: 'table-cash-1');
    expect(result.invoiceNumber, startsWith('INV-'));

    final queued = await db.select(db.syncQueueItems).get();
    expect(
      queued.map((r) => '${r.entityType}.${r.operation}').toSet(),
      containsAll({'order.create', 'invoice.create', 'table_session.open'}),
    );

    final orderOp = queued.singleWhere((r) => r.entityType == 'order');
    final payload = jsonDecode(orderOp.payloadJson) as Map<String, dynamic>;
    expect(payload['order_type'], 'table');
    expect(payload['offline_sale'], isTrue);
    expect(payload['dining_table_id'], 4);
    expect(payload['placed_at'], isNotNull);
    expect(payload['total_amount'], 11.5);
    expect((payload['items'] as List).single['unit_price'], 10);

    final counts = await SyncQueueClassifier(db).counts(workspaceId);
    expect(counts.invoicePending, 3);
    expect(counts.ready, 2);
    expect(counts.waitingParent, 1);
    expect(counts.unsupported, 0);
  });

  test('table invoice waits for order ACK then keeps local INV-*', () async {
    final result = await sellTable(clientReference: 'table-ack-1');
    final seen = <String>[];
    Map<String, dynamic>? orderData;
    Map<String, dynamic>? invoiceData;
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        final ops = (body['operations'] as List).cast<Map>();
        for (final op in ops) {
          seen.add(op['type'] as String);
          if (op['type'] == 'order.created') {
            orderData = Map<String, dynamic>.from(op['data'] as Map);
          }
          if (op['type'] == 'invoice.created') {
            invoiceData = Map<String, dynamic>.from(op['data'] as Map);
            expect(ops.any((o) => o['type'] == 'order.created'), isFalse);
          }
        }
        return ackBatch(body);
      },
    );

    final report = await engine.pushPending(workspaceId: workspaceId);
    expect(report.synced, 3);
    expect(seen, ['table_session.open', 'order.created', 'invoice.created']);
    expect(orderData?['offline_sale'], isTrue);
    expect(orderData?['dining_table_id'], 4);
    expect(invoiceData?['order_server_id'], 4401);
    expect(invoiceData?['payment_method'], 'cash');

    final invoice = await (db.select(db.localInvoices)
          ..where((t) => t.localId.equals(result.invoiceLocalId)))
        .getSingle();
    expect(invoice.localInvoiceNumber, result.invoiceNumber);
    expect(invoice.invoiceNumber, result.invoiceNumber);
    expect(invoice.serverInvoiceNumber, 'CASH-00000021');
    expect(invoice.totalAmount, 1150);
    expect(invoice.syncStatus, 'synced');

    final leftover = await SyncQueueClassifier(db).counts(workspaceId);
    expect(leftover.invoicePending, 0);
    expect(leftover.unsupported, 0);
  });

  test('retry reuses the same table order and invoice UUIDs', () async {
    final result = await sellTable(clientReference: 'table-retry-1');
    final orderOp = await (db.select(db.syncQueueItems)
          ..where((t) => t.entityType.equals('order')))
        .getSingle();
    final invoiceOp = await (db.select(db.syncQueueItems)
          ..where((t) => t.entityType.equals('invoice')))
        .getSingle();
    final orderUuid = orderOp.operationUuid;
    final invoiceUuid = invoiceOp.operationUuid;
    var phase = 'first';
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        final ops = (body['operations'] as List).cast<Map>();
        for (final op in ops) {
          if (op['type'] == 'order.created') expect(op['id'], orderUuid);
          if (op['type'] == 'invoice.created') expect(op['id'], invoiceUuid);
        }
        if (phase == 'timeout' &&
            ops.any((op) => op['type'] == 'invoice.created')) {
          throw Exception('timeout');
        }
        return ackBatch(
          body,
          status: phase == 'duplicate' ? 'duplicate' : 'applied',
        );
      },
    );

    expect((await engine.pushPending(workspaceId: workspaceId)).synced, 3);

    await (db.update(db.syncQueueItems)..where((t) => t.id.equals(invoiceOp.id)))
        .write(
      const SyncQueueItemsCompanion(
        status: Value('pending'),
        nextAttemptAt: Value(null),
      ),
    );
    final reconciled = await engine.pushPending(workspaceId: workspaceId);
    expect(reconciled.synced, 1);
    final after = await (db.select(db.syncQueueItems)
          ..where((t) => t.id.equals(invoiceOp.id)))
        .getSingle();
    expect(after.status, 'synced');
    expect(after.operationUuid, invoiceUuid);
    expect(await db.select(db.localOrders).get(), hasLength(1));
    expect(await db.select(db.localInvoices).get(), hasLength(1));
    expect(result.orderLocalId, 'table-retry-1');
  });

  test('catalog price change does not rewrite the table snapshot', () async {
    final result = await sellTable(clientReference: 'table-price-lock');
    await (db.update(db.localProducts)
          ..where((t) => t.localId.equals(productLocalId)))
        .write(const LocalProductsCompanion(price: Value(1200)));

    final orderOp = await (db.select(db.syncQueueItems)
          ..where((t) => t.entityType.equals('order')))
        .getSingle();
    final payload = jsonDecode(orderOp.payloadJson) as Map<String, dynamic>;
    expect((payload['items'] as List).single['unit_price'], 10);
    expect(payload['total_amount'], 11.5);

    await SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async => ackBatch(body),
    ).pushPending(workspaceId: workspaceId);

    final invoice = await (db.select(db.localInvoices)
          ..where((t) => t.localId.equals(result.invoiceLocalId)))
        .getSingle();
    expect(invoice.totalAmount, 1150);
  });

  test('two table client_references stay two operations', () async {
    await sellTable(clientReference: 'table-a', tableServerId: 4);
    await sellTable(clientReference: 'table-b', tableServerId: 5);
    final orderOps = await (db.select(db.syncQueueItems)
          ..where((t) => t.entityType.equals('order')))
        .get();
    expect(orderOps, hasLength(2));
    expect(orderOps.map((r) => r.clientReference).toSet(), {
      'table-a',
      'table-b',
    });
    expect(orderOps.map((r) => r.operationUuid).toSet(), hasLength(2));
  });

  test('unpaid table-board order is pushed as a kitchen ticket before cash close', () async {
    await tables.openSessionLocal(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableServerId: 4,
    );
    await orders.createTableOrder(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableId: 4,
      clientReference: 'board-unpaid',
      items: [
        {
          'pos_menu_item_id': productServerId,
          'product_local_id': productLocalId,
          'name': 'Latte',
          'quantity': 1,
          'unit_price': 10,
          'total_amount': 10,
        },
      ],
    );
    expect(
      (await SyncQueueClassifier(db).classifyWorkspace(workspaceId))
          .where((c) => c.row.entityType == 'order')
          .single
          .bucket,
      SyncQueueBucket.ready,
    );

    final kitchenPush = <String>[];
    Map<String, dynamic>? kitchenOrder;
    await SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        for (final op in (body['operations'] as List).cast<Map>()) {
          kitchenPush.add(op['type'] as String);
          if (op['type'] == 'order.created') {
            kitchenOrder = Map<String, dynamic>.from(op['data'] as Map);
          }
        }
        return ackBatch(body, orderId: 7701);
      },
    ).pushPending(workspaceId: workspaceId);
    expect(kitchenPush, ['table_session.open', 'order.created']);
    expect(kitchenOrder?['offline_sale'], isTrue);
    expect(kitchenOrder?['dining_table_id'], 4);
    expect(kitchenOrder?['payment_status'], 'unpaid');
    expect(kitchenOrder?['session_local_id'], isNotNull);
    expect(kitchenPush, isNot(contains('invoice.created')));

    await (db.update(db.localProducts)
          ..where((t) => t.localId.equals(productLocalId)))
        .write(const LocalProductsCompanion(price: Value(1200)));

    final closed = await tables.closeSessionLocal(
      workspaceId: workspaceId,
      deviceId: deviceId,
      tableServerId: 4,
      paymentMethod: 'cash',
    );
    expect(closed['invoice'], isA<Map>());

    final invoicePush = <String>[];
    await SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        for (final op in (body['operations'] as List).cast<Map>()) {
          invoicePush.add(op['type'] as String);
        }
        return ackBatch(body, orderId: 7701, invoiceId: 9901);
      },
    ).pushPending(workspaceId: workspaceId);

    expect(invoicePush, contains('invoice.created'));
    expect(invoicePush.where((t) => t == 'order.created'), isEmpty);
    expect(
      (await SyncQueueClassifier(db).counts(workspaceId)).invoicePending,
      0,
    );
  });
}
