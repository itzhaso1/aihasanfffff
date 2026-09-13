import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/api/cashier_api.dart';
import 'package:hasim_cashier/core/api/cashier_network_policy.dart';
import 'package:hasim_cashier/core/config/app_config.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/pos/application/checkout_service.dart';
import 'package:hasim_cashier/core/pos/application/document_numbers.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/application/shift_service.dart';
import 'package:hasim_cashier/core/pos/application/stock_engine.dart';
import 'package:hasim_cashier/core/pos/domain/pricing_service.dart';
import 'package:hasim_cashier/core/pos/pos_errors.dart';
import 'package:hasim_cashier/core/pos/pos_mode.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late CheckoutService checkout;
  late SyncQueueRepository queue;
  late ShiftService shifts;
  late LocalAuthService auth;

  const workspaceId = 10;
  const deviceId = 'POS-2B';
  const productLocalId = 'w10_latte';
  const productServerId = 9001;

  late String storeId;
  late String userId;
  late String shiftId;

  setUp(() async {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
    checkout = CheckoutService(
      db,
      StockEngine(db),
      DocumentNumberService(db),
      queue,
    );
    shifts = ShiftService(db);
    auth = LocalAuthService(db);

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
    await db
        .into(db.localStores)
        .insert(
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
    await db
        .into(db.localProducts)
        .insert(
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

  Future<CheckoutResult> sellTakeaway({
    required String clientReference,
    double unitPrice = 10,
    double orderDiscount = 0,
    int quantity = 1,
    int? productServer,
    String? customerLocalId,
    String orderType = 'takeaway',
    bool connected = false,
    double taxRate = 15,
    String? tableLocalId,
    int? tableServerId,
    int? workspace,
  }) {
    final lines = [
      PricedLine(
        productLocalId: productLocalId,
        productServerId: productServer ?? productServerId,
        name: 'Latte',
        quantity: quantity,
        unitPrice: unitPrice,
        taxRate: taxRate,
      ),
    ];
    final quote = const PricingService().quote(
      lines: lines,
      orderDiscountAmount: orderDiscount,
      fallbackTaxRate: taxRate,
    );
    return checkout.execute(
      CheckoutCommand(
        workspaceId: workspace ?? workspaceId,
        deviceId: deviceId,
        storeId: storeId,
        permissions: LocalAuthService.adminPermissions,
        clientReference: clientReference,
        orderType: orderType,
        lines: lines,
        payments: [
          PaymentTender(
            method: 'cash',
            amount: quote.total,
            tendered: quote.total,
          ),
        ],
        customerLocalId: customerLocalId,
        tableLocalId: tableLocalId,
        tableServerId: tableServerId,
        orderDiscountAmount: orderDiscount,
        taxRate: taxRate,
        createdByUserId: userId,
        shiftLocalId: shiftId,
        connected: connected,
      ),
    );
  }

  Future<SyncQueueItem> orderQueueRow(String localId) {
    return (db.select(db.syncQueueItems)
          ..where((t) => t.entityType.equals('order'))
          ..where((t) => t.entityId.equals(localId)))
        .getSingle();
  }

  Future<void> clearBackoff(int id) async {
    await (db.update(db.syncQueueItems)..where((t) => t.id.equals(id))).write(
      const SyncQueueItemsCompanion(nextAttemptAt: Value(null)),
    );
  }

  Map<String, dynamic> ackBatch(
    Map<String, dynamic> body, {
    String status = 'applied',
    int orderId = 4401,
    String orderNumber = 'TW-1001',
    int invoiceId = 8801,
    String invoiceNumber = 'CASH-00000001',
    int customerId = 77,
  }) {
    final ops = (body['operations'] as List).cast<Map>();
    return {
      'accepted': [
        for (final op in ops)
          {
            'id': op['id'],
            'status': status,
            'entity_id': switch (op['type']) {
              'customer.created' => customerId,
              'invoice.created' => invoiceId,
              _ => orderId,
            },
            'result': op['type'] == 'invoice.created'
                ? {
                    'invoice_id': invoiceId,
                    'id': invoiceId,
                    'invoice_number': invoiceNumber,
                    'total_amount': 11.5,
                    'currency': 'SAR',
                  }
                : op['type'] == 'customer.created'
                ? {'id': customerId}
                : {'id': orderId, 'order_number': orderNumber},
          },
      ],
      'failed': <Map<String, dynamic>>[],
    };
  }

  test(
    'A/B offline takeaway checkout persists SQLite and enqueues order.created',
    () async {
      final result = await sellTakeaway(clientReference: 'tw-offline-1');
      expect(result.orderLocalId, 'tw-offline-1');

      final order = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-offline-1'))).getSingle();
      expect(order.clientReference, 'tw-offline-1');
      expect(order.orderType, 'takeaway');
      expect(order.syncStatus, 'pending');
      expect(order.serverId, isNull);
      expect(order.subtotal, 1000);
      expect(order.totalAmount, 1150);

      final queued = await (db.select(
        db.syncQueueItems,
      )..where((t) => t.entityType.equals('order'))).get();
      expect(queued, hasLength(1));
      expect(queued.single.operation, 'create');
      expect(queued.single.status, 'pending');
      expect(queued.single.operationUuid, isNotEmpty);
      expect(queued.single.clientReference, 'tw-offline-1');

      final payload =
          jsonDecode(queued.single.payloadJson) as Map<String, dynamic>;
      expect(payload['order_type'], 'takeaway');
      expect(payload['client_reference'], 'tw-offline-1');
      expect(payload['currency'], 'SAR');
      expect(
        (payload['items'] as List).single['pos_menu_item_id'],
        productServerId,
      );
      expect((payload['items'] as List).single['unit_price'], 10);
      expect((payload['items'] as List).single['name'], 'Latte');
      expect((payload['items'] as List).single['product_name'], 'Latte');
    },
  );

  test(
    'C/D/E/F push success applied ACK stores server_id and order_number',
    () async {
      await sellTakeaway(clientReference: 'tw-ack-1');
      final queued = await orderQueueRow('tw-ack-1');

      var batches = 0;
      final engine = SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async {
          batches++;
          final ops = (body['operations'] as List).cast<Map>();
          expect(body['device_id'], deviceId);
          if (ops.any((op) => op['type'] == 'order.created')) {
            final orderOp = ops.firstWhere((op) => op['type'] == 'order.created');
            expect(orderOp['id'], queued.operationUuid);
            expect(orderOp['data']['client_reference'], 'tw-ack-1');
            expect(orderOp['data']['order_type'], 'takeaway');
          }
          return ackBatch(body);
        },
      );

      final report = await engine.pushPending(workspaceId: workspaceId);
      expect(report.synced, greaterThanOrEqualTo(1));
      expect(batches, greaterThanOrEqualTo(1));

      final order = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-ack-1'))).getSingle();
      expect(order.serverId, 4401);
      expect(order.orderNumber, 'TW-1001');
      expect(order.clientReference, 'tw-ack-1');
      expect(order.localId, 'tw-ack-1');
      expect(order.syncStatus, 'synced');

      final after = await (db.select(
        db.syncQueueItems,
      )..where((t) => t.id.equals(queued.id))).getSingle();
      expect(after.status, 'synced');
    },
  );

  test(
    'G/H/I retry after timeout uses same UUID and duplicate ACK is success',
    () async {
      await sellTakeaway(clientReference: 'tw-dup-1');
      final queued = await orderQueueRow('tw-dup-1');
      final uuid = queued.operationUuid;

      var attempts = 0;
      final engine = SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async {
          attempts++;
          final ops = (body['operations'] as List).cast<Map>();
          for (final op in ops) {
            if (op['type'] == 'order.created') {
              expect(op['id'], uuid);
            }
          }
          if (attempts == 1) {
            throw Exception('timeout');
          }
          return ackBatch(
            body,
            status: 'duplicate',
            orderId: 5502,
            orderNumber: 'TW-2002',
          );
        },
      );

      final first = await engine.pushPending(workspaceId: workspaceId);
      expect(first.keptPending, greaterThanOrEqualTo(1));
      final stillPending = await (db.select(
        db.syncQueueItems,
      )..where((t) => t.id.equals(queued.id))).getSingle();
      expect(stillPending.status, 'pending');
      expect(stillPending.operationUuid, uuid);

      await clearBackoff(queued.id);
      final second = await engine.pushPending(workspaceId: workspaceId);
      expect(second.synced, greaterThanOrEqualTo(1));
      expect(attempts, greaterThanOrEqualTo(2));

      final order = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-dup-1'))).getSingle();
      expect(order.serverId, 5502);
      expect(order.orderNumber, 'TW-2002');
      expect(order.clientReference, 'tw-dup-1');

      final done = await (db.select(
        db.syncQueueItems,
      )..where((t) => t.id.equals(queued.id))).getSingle();
      expect(done.status, 'synced');
      expect(
        await (db.select(
          db.syncQueueItems,
        )..where((t) => t.entityType.equals('order'))).get(),
        hasLength(1),
      );
    },
  );

  test(
    'J checkout does not enqueue payment or stock.movement',
    () async {
      await sellTakeaway(clientReference: 'tw-no-stock');
      final queued = await db.select(db.syncQueueItems).get();
      expect(queued.any((r) => r.entityType == 'order'), isTrue);
      expect(queued.every((r) => r.entityType != 'stock'), isTrue);
      expect(queued.every((r) => r.entityType != 'stock_movement'), isTrue);
      expect(queued.every((r) => r.entityType != 'payment'), isTrue);
    },
  );

  test('K/L/M/N snapshot money is major decimal, not cents', () async {
    await sellTakeaway(
      clientReference: 'tw-money',
      unitPrice: 10,
      orderDiscount: 2,
    );
    final queued = await orderQueueRow('tw-money');
    final payload = jsonDecode(queued.payloadJson) as Map<String, dynamic>;
    expect(payload['subtotal_amount'], 10);
    expect(payload['discount_amount'], 2);
    expect(payload['tax_amount'], 1.2);
    expect(payload['total_amount'], 9.2);
    expect(payload.containsKey('discount_percent'), isFalse);
    final item = (payload['items'] as List).single as Map<String, dynamic>;
    expect(item['unit_price'], 10);
    expect(item['discount_amount'], 0);
    expect(item['tax_amount'], 1.2);
    expect(item.containsKey('unit_price_cents'), isFalse);

    final order = await (db.select(
      db.localOrders,
    )..where((t) => t.localId.equals('tw-money'))).getSingle();
    expect(order.subtotal, 1000);
    expect(order.discountAmount, 200);
    expect(order.taxAmount, 120);
    expect(order.totalAmount, 920);
  });

  test(
    'O catalog price change after checkout does not rewrite queued snapshot',
    () async {
      await sellTakeaway(clientReference: 'tw-price-lock', unitPrice: 10);
      await (db.update(db.localProducts)
            ..where((t) => t.localId.equals(productLocalId)))
          .write(const LocalProductsCompanion(price: Value(1200)));

      final queued = await orderQueueRow('tw-price-lock');
      final payload = jsonDecode(queued.payloadJson) as Map<String, dynamic>;
      expect((payload['items'] as List).single['unit_price'], 10);
      expect(payload['total_amount'], 11.5);
      expect(payload['total_amount'], isNot(12));
    },
  );

  test('P two client_references enqueue two independent operations', () async {
    await sellTakeaway(clientReference: 'device-a-ref');
    await sellTakeaway(clientReference: 'device-b-ref');
    final queued = await (db.select(
      db.syncQueueItems,
    )..where((t) => t.entityType.equals('order'))).get();
    expect(queued, hasLength(2));
    expect(queued.map((r) => r.operationUuid).toSet(), hasLength(2));
    expect(queued.map((r) => r.clientReference).toSet(), {
      'device-a-ref',
      'device-b-ref',
    });
  });

  test(
    'Q customer.created is pushed before order.created when customer_id missing',
    () async {
      final now = DateTime.now();
      await db
          .into(db.localCustomers)
          .insert(
            LocalCustomersCompanion.insert(
              localId: 'cust-wait',
              workspaceId: workspaceId,
              name: 'Sara',
              phone: const Value('0500000002'),
              updatedAt: now,
              syncStatus: const Value('pending'),
            ),
          );
      await queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId,
        entityType: 'customer',
        entityId: 'cust-wait',
        operation: 'create',
        payload: const {'name': 'Sara', 'phone': '0500000002'},
        clientReference: 'cust-wait',
        operationUuid: 'op-cust-wait',
      );
      await sellTakeaway(
        clientReference: 'tw-wait-cust',
        customerLocalId: 'cust-wait',
      );

      final seenTypes = <String>[];
      Map<String, dynamic>? orderData;
      final engine = SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async {
          final ops = (body['operations'] as List).cast<Map>();
          for (final op in ops) {
            seenTypes.add(op['type'] as String);
            if (op['type'] == 'order.created') {
              orderData = Map<String, dynamic>.from(op['data'] as Map);
            }
          }
          return ackBatch(
            body,
            orderId: 88,
            orderNumber: 'TW-C77',
            customerId: 77,
            invoiceId: 99,
          );
        },
      );

      await engine.pushPending(workspaceId: workspaceId);
      expect(seenTypes.first, 'customer.created');
      expect(seenTypes, contains('order.created'));
      expect(
        seenTypes.indexOf('order.created'),
        greaterThan(seenTypes.indexOf('customer.created')),
      );
      expect(orderData?['customer_id'], 77);
      expect(orderData?['client_reference'], 'tw-wait-cust');
    },
  );

  test(
    'R invalid product server id still completes checkout but skips queue',
    () async {
      final result = await sellTakeaway(
        clientReference: 'tw-no-server-product',
        productServer: 0,
      );
      expect(result.orderLocalId, 'tw-no-server-product');
      final order = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-no-server-product'))).getSingle();
      expect(order.syncStatus, 'local');
      final queued = await (db.select(
        db.syncQueueItems,
      )..where((t) => t.entityType.equals('order'))).get();
      expect(queued, isEmpty);
    },
  );

  test('S invalid quantity is rejected by checkout', () async {
    expect(
      () => sellTakeaway(clientReference: 'tw-bad-qty', quantity: 0),
      throwsA(isA<InvalidDiscount>()),
    );
    expect(await db.select(db.localOrders).get(), isEmpty);
    expect(await db.select(db.syncQueueItems).get(), isEmpty);
  });

  test(
    'T HTTP 401 keeps queue pending and does not delete the order',
    () async {
      await sellTakeaway(clientReference: 'tw-401');
      final engine = SyncEngineV2(
        db,
        queue,
        postPushBatch: (_) async {
          throw ApiException('unauthenticated', statusCode: 401);
        },
      );
      final report = await engine.pushPending(workspaceId: workspaceId);
      expect(report.authRequired, isTrue);
      final queued = await orderQueueRow('tw-401');
      expect(queued.status, 'pending');
      expect(queued.operationUuid, isNotEmpty);
      final order = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-401'))).getSingle();
      expect(order.localId, 'tw-401');
      expect(order.serverId, isNull);
    },
  );

  test('U HTTP 403 marks the queue item failed permanently', () async {
    await sellTakeaway(clientReference: 'tw-403');
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (_) async {
        throw ApiException('forbidden', statusCode: 403);
      },
    );
    await engine.pushPending(workspaceId: workspaceId);
    final queued = await orderQueueRow('tw-403');
    expect(queued.status, 'failed');
    final order = await (db.select(
      db.localOrders,
    )..where((t) => t.localId.equals('tw-403'))).getSingle();
    expect(order.syncStatus, 'failed');
    expect(order.localId, 'tw-403');
  });

  test('delivery checkout enqueues order.created and invoice.created', () async {
    final result = await sellTakeaway(
      clientReference: 'delivery-in-scope',
      orderType: 'delivery',
    );
    final queued = await db.select(db.syncQueueItems).get();
    expect(queued, hasLength(2));
    expect(
      queued.map((row) => '${row.entityType}.${row.operation}').toSet(),
      {'order.create', 'invoice.create'},
    );
    final orderOp = queued.singleWhere((row) => row.entityType == 'order');
    final payload = jsonDecode(orderOp.payloadJson) as Map;
    expect(payload['order_type'], 'delivery');
    expect(payload['offline_sale'], isTrue);
    expect(payload['placed_at'], isNotNull);
    final invoiceOp = queued.singleWhere((row) => row.entityType == 'invoice');
    final invoicePayload = jsonDecode(invoiceOp.payloadJson) as Map;
    expect(invoicePayload['closed_at'], isNotNull);
    expect(invoicePayload['order_type'], 'delivery');
    expect(result.invoiceNumber, startsWith('INV-'));
  });

  test('standalone workspace 900001 never enqueues takeaway sync', () async {
    final standShift = await shifts.open(
      workspaceId: PosMode.standaloneWorkspaceId,
      userId: userId,
      openingCash: 50,
      permissions: LocalAuthService.adminPermissions,
    );
    final catalog = await (db.select(
      db.localProducts,
    )..where((t) => t.workspaceId.equals(PosMode.standaloneWorkspaceId))).get();
    // bootstrapStore does not create a product; insert one in standalone.
    final now = DateTime.now();
    await db
        .into(db.localProducts)
        .insert(
          LocalProductsCompanion.insert(
            localId: 'stand-tea',
            workspaceId: PosMode.standaloneWorkspaceId,
            name: 'Tea',
            price: const Value(500),
            taxRate: const Value(15),
            updatedAt: now,
          ),
        );
    expect(catalog, isEmpty);
    await checkout.execute(
      CheckoutCommand(
        workspaceId: PosMode.standaloneWorkspaceId,
        deviceId: 'dev-stand',
        storeId: storeId,
        permissions: LocalAuthService.adminPermissions,
        clientReference: 'stand-tw',
        orderType: 'takeaway',
        lines: const [
          PricedLine(
            productLocalId: 'stand-tea',
            productServerId: 0,
            name: 'Tea',
            quantity: 1,
            unitPrice: 5,
            taxRate: 15,
          ),
        ],
        payments: const [
          PaymentTender(method: 'cash', amount: 5.75, tendered: 5.75),
        ],
        taxRate: 15,
        createdByUserId: userId,
        shiftLocalId: standShift,
        connected: false,
      ),
    );
    final queued = await db.select(db.syncQueueItems).get();
    expect(queued, isEmpty);
  });

  test('operation UUID is stable across retries', () async {
    await sellTakeaway(clientReference: 'tw-uuid');
    final first = await orderQueueRow('tw-uuid');
    final uuid = first.operationUuid;
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (_) async => throw Exception('network'),
    );
    await engine.pushPending(workspaceId: workspaceId);
    await clearBackoff(first.id);
    await engine.pushPending(workspaceId: workspaceId);
    final again = await orderQueueRow('tw-uuid');
    expect(again.operationUuid, uuid);
    expect(again.status, 'pending');
  });

  test(
    'connected flag still completes locally without waiting on push',
    () async {
      final result = await sellTakeaway(
        clientReference: 'tw-connected-flag',
        connected: true,
      );
      expect(result.orderLocalId, 'tw-connected-flag');
      expect(result.invoiceLocalId, isNotEmpty);
      final queued = await orderQueueRow('tw-connected-flag');
      expect(queued.status, 'pending');
    },
  );

  test('CashierNetworkPolicy allows sanctum POST /sync/push in Phase 2B', () {
    expect(AppConfig.offlineOnly, isTrue);
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-cloud-token',
        path: '/sync/push',
        method: 'POST',
      ),
      isTrue,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'standalone:1',
        path: '/sync/push',
        method: 'POST',
      ),
      isFalse,
    );
    expect(
      CashierNetworkPolicy.allowRequest(
        offlineOnly: true,
        token: 'sanctum-cloud-token',
        path: '/orders',
        method: 'POST',
      ),
      isFalse,
    );
  });

  test(
    'batch path sends unpaid table kitchen tickets with takeaway sales',
    () async {
      await queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId,
        entityType: 'order',
        entityId: 'table-queued',
        operation: 'create',
        payload: {
          'order_type': 'table',
          'client_reference': 'table-queued',
          'items': [
            {
              'pos_menu_item_id': productServerId,
              'quantity': 1,
              'unit_price': 10,
            },
          ],
        },
        clientReference: 'table-queued',
      );
      await sellTakeaway(clientReference: 'tw-only-batch');
      final pushedTypes = <String>[];
      final pushedOrderTypes = <String>[];
      final engine = SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async {
          final ops = (body['operations'] as List).cast<Map>();
          for (final op in ops) {
            pushedTypes.add(op['type'] as String);
            if (op['type'] == 'order.created') {
              pushedOrderTypes.add('${op['data']['order_type']}');
            }
          }
          return {
            'accepted': [
              for (final op in ops)
                {
                  'id': op['id'],
                  'status': 'applied',
                  'entity_id': 1,
                  'result': {'id': 1, 'order_number': 'TW-X'},
                },
            ],
            'failed': <Map<String, dynamic>>[],
          };
        },
      );
      await engine.pushPending(workspaceId: workspaceId);
      expect(pushedTypes, contains('order.created'));
      expect(pushedOrderTypes, containsAll(['table', 'takeaway']));
      expect(
        pushedTypes.every(
          (t) => t == 'order.created' || t == 'invoice.created',
        ),
        isTrue,
      );
      final tableRow = await (db.select(
        db.syncQueueItems,
      )..where((t) => t.entityId.equals('table-queued'))).getSingle();
      expect(tableRow.status, 'synced');
    },
  );

  test(
    'CRITICAL e2e: offline checkout, push applied, retry duplicate, same UUID',
    () async {
      final result = await sellTakeaway(clientReference: 'tw-e2e');
      expect(result.orderLocalId, 'tw-e2e');

      final local = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-e2e'))).getSingle();
      expect(local.syncStatus, 'pending');
      expect(local.serverId, isNull);

      final queued = await orderQueueRow('tw-e2e');
      expect(queued.status, 'pending');
      final uuid = queued.operationUuid;
      var posts = 0;
      final seenIds = <String>[];

      final engine = SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async {
          posts++;
          final ops = (body['operations'] as List).cast<Map>();
          for (final op in ops) {
            if (op['type'] == 'order.created') {
              seenIds.add(op['id'] as String);
              expect(op['data']['unit_price'], isNull);
              expect(op['data']['items'][0]['unit_price'], 10);
            }
          }
          return ackBatch(
            body,
            status: posts == 1 ? 'applied' : 'duplicate',
            orderId: 900,
            orderNumber: 'TW-E2E',
            invoiceId: 901,
            invoiceNumber: 'CASH-00000901',
          );
        },
      );

      final first = await engine.pushPending(workspaceId: workspaceId);
      expect(first.synced, greaterThanOrEqualTo(1));
      final synced = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-e2e'))).getSingle();
      expect(synced.serverId, 900);
      expect(synced.orderNumber, 'TW-E2E');
      expect(synced.clientReference, 'tw-e2e');
      expect(synced.localId, 'tw-e2e');

      final postsAfterFirst = posts;
      expect(seenIds, [uuid]);
      expect(await (db.select(db.localOrders).get()), hasLength(1));

      // Queue is already synced; a retry must not send a second order operation.
      final second = await engine.pushPending(workspaceId: workspaceId);
      expect(second.synced, 0);
      expect(posts, postsAfterFirst);
      expect(seenIds, [uuid]);

      // Simulated lost ACK: requeue the same UUID and accept duplicate.
      await (db.update(
        db.syncQueueItems,
      )..where((t) => t.id.equals(queued.id))).write(
        const SyncQueueItemsCompanion(
          status: Value('pending'),
          nextAttemptAt: Value(null),
        ),
      );
      final retry = await engine.pushPending(workspaceId: workspaceId);
      expect(retry.synced, 1);
      expect(posts, postsAfterFirst);
      expect(seenIds, [uuid]);
      final afterRetry = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-e2e'))).getSingle();
      expect(afterRetry.serverId, 900);
      expect(afterRetry.clientReference, 'tw-e2e');
      expect(await db.select(db.localOrders).get(), hasLength(1));
    },
  );
}
