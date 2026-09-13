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

  const workspaceId = 10;
  const deviceId = 'POS-2C2';
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

  Future<CheckoutResult> sellCashTakeaway(String clientReference) {
    const lines = [
      PricedLine(
        productLocalId: productLocalId,
        productServerId: productServerId,
        name: 'Latte',
        quantity: 1,
        unitPrice: 10,
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
        orderType: 'takeaway',
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
        connected: false,
      ),
    );
  }

  Map<String, dynamic> ackBatch(Map<String, dynamic> body) {
    final ops = (body['operations'] as List).cast<Map>();
    return {
      'accepted': [
        for (final op in ops)
          {
            'id': op['id'],
            'status': 'applied',
            'entity_id': op['type'] == 'invoice.created' ? 8801 : 4401,
            'result': op['type'] == 'invoice.created'
                ? {
                    'invoice_id': 8801,
                    'invoice_number': 'CASH-00000001',
                    'total_amount': 11.5,
                    'payment_method': 'cash',
                    'payment_status': 'paid',
                    'currency': 'SAR',
                  }
                : {'id': 4401, 'order_number': 'TW-1001'},
          },
      ],
      'failed': <Map<String, dynamic>>[],
    };
  }

  test(
    'takeaway cash sale keeps paid local cash state and never queues payment.created',
    () async {
      final result = await sellCashTakeaway('tw-cash-2c2');

      final order = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-cash-2c2'))).getSingle();
      expect(order.paymentStatus, 'paid');
      expect(order.totalAmount, 1150);

      final invoice = await (db.select(
        db.localInvoices,
      )..where((t) => t.localId.equals(result.invoiceLocalId))).getSingle();
      expect(invoice.status, 'closed');
      expect(invoice.totalAmount, order.totalAmount);

      final payment = await (db.select(
        db.localPayments,
      )..where((t) => t.invoiceLocalId.equals(result.invoiceLocalId))).getSingle();
      expect(payment.method, 'cash');
      expect(payment.amount, order.totalAmount);
      expect(payment.amount, invoice.totalAmount);

      final cashMove = await (db.select(
        db.localCashMovements,
      )..where((t) => t.referenceId.equals(result.invoiceLocalId))).getSingle();
      expect(cashMove.type, 'sale');
      expect(cashMove.amount, payment.amount);

      final queued = await db.select(db.syncQueueItems).get();
      expect(queued.map((r) => r.entityType).toSet(), {'order', 'invoice'});
      expect(queued.every((r) => r.entityType != 'payment'), isTrue);

      final invoiceOp = queued.singleWhere((r) => r.entityType == 'invoice');
      final payload =
          jsonDecode(invoiceOp.payloadJson) as Map<String, dynamic>;
      expect(payload['payment_method'], 'cash');
      expect(payload['total_amount'], 11.5);
      expect(payload['order_local_id'], 'tw-cash-2c2');

      final seen = <String>[];
      await SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async {
          final ops = (body['operations'] as List).cast<Map>();
          for (final op in ops) {
            seen.add(op['type'] as String);
          }
          return ackBatch(body);
        },
      ).pushPending(workspaceId: workspaceId);

      expect(seen, ['order.created', 'invoice.created']);

      final afterOrder = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-cash-2c2'))).getSingle();
      expect(afterOrder.paymentStatus, 'paid');
      expect(afterOrder.totalAmount, 1150);
      expect(afterOrder.serverId, 4401);

      final afterPay = await (db.select(
        db.localPayments,
      )..where((t) => t.invoiceLocalId.equals(result.invoiceLocalId))).getSingle();
      expect(afterPay.method, 'cash');
      expect(afterPay.amount, 1150);
      expect(
        await (db.select(
          db.syncQueueItems,
        )..where((t) => t.entityType.equals('payment'))).get(),
        isEmpty,
      );
    },
  );

  test(
    'catalog price change does not change local cash amount before or after ACK',
    () async {
      final result = await sellCashTakeaway('tw-cash-price-lock');
      await (db.update(db.localProducts)
            ..where((t) => t.localId.equals(productLocalId)))
          .write(const LocalProductsCompanion(price: Value(1200)));

      await SyncEngineV2(
        db,
        queue,
        postPushBatch: (body) async => ackBatch(body),
      ).pushPending(workspaceId: workspaceId);

      final order = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('tw-cash-price-lock'))).getSingle();
      final invoice = await (db.select(
        db.localInvoices,
      )..where((t) => t.localId.equals(result.invoiceLocalId))).getSingle();
      final payment = await (db.select(
        db.localPayments,
      )..where((t) => t.invoiceLocalId.equals(result.invoiceLocalId))).getSingle();
      expect(order.totalAmount, 1150);
      expect(invoice.totalAmount, 1150);
      expect(payment.amount, 1150);
      expect(payment.method, 'cash');
      expect(order.paymentStatus, 'paid');
    },
  );
}
