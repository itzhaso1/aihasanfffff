import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/pos/application/checkout_service.dart';
import 'package:hasim_cashier/core/pos/application/document_numbers.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/application/shift_service.dart';
import 'package:hasim_cashier/core/pos/application/stock_engine.dart';
import 'package:hasim_cashier/core/pos/domain/pricing_service.dart';
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
  const deviceId = 'POS-2C1';
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
    String orderType = 'takeaway',
    String? tableLocalId,
    int? tableServerId,
    String paymentMethod = 'cash',
  }) {
    final lines = [
      PricedLine(
        productLocalId: productLocalId,
        productServerId: productServerId,
        name: 'Latte',
        quantity: quantity,
        unitPrice: unitPrice,
        taxRate: 15,
      ),
    ];
    final quote = const PricingService().quote(
      lines: lines,
      orderDiscountAmount: orderDiscount,
      fallbackTaxRate: 15,
    );
    return checkout.execute(
      CheckoutCommand(
        workspaceId: workspaceId,
        deviceId: deviceId,
        storeId: storeId,
        permissions: LocalAuthService.adminPermissions,
        clientReference: clientReference,
        orderType: orderType,
        lines: lines,
        payments: [
          PaymentTender(
            method: paymentMethod,
            amount: quote.total,
            tendered: quote.total,
          ),
        ],
        tableLocalId: tableLocalId,
        tableServerId: tableServerId,
        orderDiscountAmount: orderDiscount,
        taxRate: 15,
        createdByUserId: userId,
        shiftLocalId: shiftId,
        connected: false,
      ),
    );
  }

  Future<SyncQueueItem> invoiceQueueRow(String invoiceLocalId) {
    return (db.select(db.syncQueueItems)
          ..where((t) => t.entityType.equals('invoice'))
          ..where((t) => t.entityId.equals(invoiceLocalId)))
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
                  }
                : {'id': orderId, 'order_number': orderNumber},
          },
      ],
      'failed': <Map<String, dynamic>>[],
    };
  }

  test(
    'checkout enqueues takeaway order.created then invoice.created, not payment',
    () async {
      final result = await sellTakeaway(clientReference: 'tw-inv-1');
      final queued = await db.select(db.syncQueueItems).get();
      expect(queued.map((r) => r.entityType).toSet(), {'order', 'invoice'});
      expect(queued.every((r) => r.entityType != 'payment'), isTrue);
      expect(queued.every((r) => r.operation == 'create'), isTrue);

      final invoiceOp = await invoiceQueueRow(result.invoiceLocalId);
      expect(invoiceOp.status, 'pending');
      expect(invoiceOp.operationUuid, isNotEmpty);
      final payload =
          jsonDecode(invoiceOp.payloadJson) as Map<String, dynamic>;
      expect(payload['order_type'], 'takeaway');
      expect(payload['order_local_id'], 'tw-inv-1');
      expect(payload['local_invoice_number'], result.invoiceNumber);
      expect(payload['payment_method'], 'cash');
      expect(payload['total_amount'], 11.5);
      expect(payload.containsKey('discount_percent'), isFalse);
    },
  );

  test(
    'order sync followed by invoice sync links CASH-* without rewriting INV-*',
    () async {
      final result = await sellTakeaway(clientReference: 'tw-ack-inv');
      final localNumber = result.invoiceNumber;
      expect(localNumber, startsWith('INV-'));

      final before = await (db.select(
        db.localInvoices,
      )..where((t) => t.localId.equals(result.invoiceLocalId))).getSingle();
      expect(before.localInvoiceNumber, localNumber);
      expect(before.invoiceNumber, localNumber);
      expect(before.totalAmount, 1150);
      expect(before.subtotal, 1000);
      expect(before.taxAmount, 150);
      expect(before.serverId, isNull);
      expect(before.serverInvoiceNumber, isNull);

      final seenTypes = <String>[];
      Map<String, dynamic>? invoiceData;
      final engine = SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async {
          final ops = (body['operations'] as List).cast<Map>();
          for (final op in ops) {
            seenTypes.add(op['type'] as String);
            if (op['type'] == 'invoice.created') {
              invoiceData = Map<String, dynamic>.from(op['data'] as Map);
            }
          }
          if (ops.any((op) => op['type'] == 'invoice.created')) {
            expect(
              ops.any((op) => op['type'] == 'order.created'),
              isFalse,
              reason: 'invoice must wait until the order batch has been ACKed',
            );
          }
          return ackBatch(body);
        },
      );

      final report = await engine.pushPending(workspaceId: workspaceId);
      expect(report.synced, 2);
      expect(seenTypes, ['order.created', 'invoice.created']);
      expect(invoiceData?['order_local_id'], 'tw-ack-inv');
      expect(invoiceData?['order_server_id'], 4401);
      expect(invoiceData?['local_invoice_number'], localNumber);
      expect(invoiceData?['payment_method'], 'cash');

      final order = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-ack-inv'))).getSingle();
      expect(order.serverId, 4401);

      final invoice = await (db.select(
        db.localInvoices,
      )..where((t) => t.localId.equals(result.invoiceLocalId))).getSingle();
      expect(invoice.serverId, 8801);
      expect(invoice.serverInvoiceNumber, 'CASH-00000001');
      expect(invoice.serverInvoiceNumber, startsWith('CASH-'));
      expect(invoice.localInvoiceNumber, localNumber);
      expect(invoice.invoiceNumber, localNumber);
      expect(invoice.invoiceNumber, isNot(startsWith('CASH-')));
      expect(invoice.totalAmount, 1150);
      expect(invoice.subtotal, 1000);
      expect(invoice.taxAmount, 150);
      expect(invoice.discountAmount, before.discountAmount);
      expect(invoice.syncStatus, 'synced');

      final invoiceOp = await invoiceQueueRow(result.invoiceLocalId);
      expect(invoiceOp.status, 'synced');
    },
  );

  test(
    'retry uses the same invoice UUID and duplicate ACK is success',
    () async {
      final result = await sellTakeaway(clientReference: 'tw-dup-inv');
      final invoiceOp = await invoiceQueueRow(result.invoiceLocalId);
      final uuid = invoiceOp.operationUuid;
      expect(uuid, isNotEmpty);

      var phase = 'first';
      final engine = SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async {
          final ops = (body['operations'] as List).cast<Map>();
          for (final op in ops) {
            if (op['type'] == 'invoice.created') {
              expect(op['id'], uuid);
            }
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

      final first = await engine.pushPending(workspaceId: workspaceId);
      expect(first.synced, 2);

      await (db.update(db.syncQueueItems)
            ..where((t) => t.id.equals(invoiceOp.id)))
          .write(
            const SyncQueueItemsCompanion(
              status: Value('pending'),
              nextAttemptAt: Value(null),
            ),
          );

      final reconciled = await engine.pushPending(workspaceId: workspaceId);
      expect(reconciled.synced, 1);
      final still = await invoiceQueueRow(result.invoiceLocalId);
      expect(still.operationUuid, uuid);
      expect(still.status, 'synced');

      final invoices = await db.select(db.localInvoices).get();
      expect(invoices, hasLength(1));
      expect(invoices.single.serverId, 8801);
      expect(invoices.single.serverInvoiceNumber, startsWith('CASH-'));
      expect(invoices.single.localInvoiceNumber, result.invoiceNumber);
      expect(
        await (db.select(
          db.syncQueueItems,
        )..where((t) => t.entityType.equals('invoice'))).get(),
        hasLength(1),
      );
    },
  );

  test(
    'invoice totals stay on the order snapshot after a catalog price change',
    () async {
      final result = await sellTakeaway(clientReference: 'tw-price-lock-inv');
      await (db.update(db.localProducts)
            ..where((t) => t.localId.equals(productLocalId)))
          .write(const LocalProductsCompanion(price: Value(1200)));

      final queued = await invoiceQueueRow(result.invoiceLocalId);
      final payload = jsonDecode(queued.payloadJson) as Map<String, dynamic>;
      expect(payload['total_amount'], 11.5);
      expect(payload['total_amount'], isNot(13.8));

      await SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async => ackBatch(body),
      ).pushPending(workspaceId: workspaceId);

      final invoice = await (db.select(
        db.localInvoices,
      )..where((t) => t.localId.equals(result.invoiceLocalId))).getSingle();
      expect(invoice.totalAmount, 1150);
      expect(invoice.subtotal, 1000);
      expect(invoice.taxAmount, 150);
    },
  );

  test('cash payment state is preserved and payment.created is never queued', () async {
    final result = await sellTakeaway(clientReference: 'tw-cash-state');
    final payments = await (db.select(
      db.localPayments,
    )..where((t) => t.invoiceLocalId.equals(result.invoiceLocalId))).get();
    expect(payments, hasLength(1));
    expect(payments.single.method, 'cash');
    expect(payments.single.amount, 1150);
    expect(payments.single.syncStatus, 'local');

    expect(
      await (db.select(
        db.syncQueueItems,
      )..where((t) => t.entityType.equals('payment'))).get(),
      isEmpty,
    );

    await SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async => ackBatch(body),
    ).pushPending(workspaceId: workspaceId);

    final after = await (db.select(
      db.localPayments,
    )..where((t) => t.invoiceLocalId.equals(result.invoiceLocalId))).getSingle();
    expect(after.method, 'cash');
    expect(after.amount, 1150);
    expect(after.tendered, 1150);
    expect(after.syncStatus, 'synced');
    expect(
      await (db.select(
        db.syncQueueItems,
      )..where((t) => t.entityType.equals('payment'))).get(),
      isEmpty,
    );

    final invoice = await (db.select(
      db.localInvoices,
    )..where((t) => t.localId.equals(result.invoiceLocalId))).getSingle();
    final payload = jsonDecode(invoice.payloadJson) as Map<String, dynamic>;
    expect(payload['payment_method'], 'cash');
    expect(payload['invoice_number'], result.invoiceNumber);
    expect(payload['server_invoice_number'], startsWith('CASH-'));
  });

  test('table checkout now enqueues order.created then invoice.created', () async {
    await db
        .into(db.localTables)
        .insert(
          LocalTablesCompanion.insert(
            localId: 't1',
            workspaceId: workspaceId,
            serverId: const Value(4),
            name: 'T1',
            updatedAt: DateTime.now(),
          ),
        );
    await sellTakeaway(
      clientReference: 'table-now-inv',
      orderType: 'table',
      tableLocalId: 't1',
      tableServerId: 4,
    );
    final queued = await db.select(db.syncQueueItems).get();
    expect(queued.map((r) => '${r.entityType}.${r.operation}'), [
      'order.create',
      'invoice.create',
    ]);
  });

  test('invoice is not pushed until the local order has server_id', () async {
    final result = await sellTakeaway(clientReference: 'tw-wait-order');
    var invoiceSentBeforeOrder = false;
    final engine = SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        final ops = (body['operations'] as List).cast<Map>();
        final hasInvoice = ops.any((op) => op['type'] == 'invoice.created');
        final hasOrder = ops.any((op) => op['type'] == 'order.created');
        if (hasInvoice && hasOrder) {
          invoiceSentBeforeOrder = true;
        }
        if (hasInvoice && !hasOrder) {
          final order = await (db.select(
            db.localOrders,
          )..where((t) => t.localId.equals('tw-wait-order'))).getSingle();
          expect(order.serverId, isNotNull);
          expect(order.serverId, greaterThan(0));
        }
        return ackBatch(body);
      },
    );
    await engine.pushPending(workspaceId: workspaceId);
    expect(invoiceSentBeforeOrder, isFalse);
    final invoice = await (db.select(
      db.localInvoices,
    )..where((t) => t.localId.equals(result.invoiceLocalId))).getSingle();
    expect(invoice.serverId, 8801);
  });
}
