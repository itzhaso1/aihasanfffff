import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/permissions/staff_permissions.dart';
import 'package:hasim_cashier/core/pos/application/kitchen_local_service.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/pos/application/local_data_wipe_service.dart';
import 'package:hasim_cashier/core/pos/pos_errors.dart';
import 'package:hasim_cashier/core/repositories/catalog_repository.dart';
import 'package:hasim_cashier/core/repositories/local_finance_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late LocalDataWipeService wipe;
  late SyncQueueRepository queue;
  late CatalogRepository catalog;
  late LocalFinanceRepository finance;

  const ws = 10;
  const otherWs = 11;
  const admin = LocalAuthService.adminPermissions;
  final now = DateTime.now();

  setUp(() async {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
    wipe = LocalDataWipeService(db, queue: queue);
    catalog = CatalogRepository(db);
    finance = LocalFinanceRepository(db);

    await db
        .into(db.localStores)
        .insert(
          LocalStoresCompanion.insert(
            localId: 'store-10',
            workspaceId: ws,
            name: 'متجر 10',
            createdAt: now,
            updatedAt: now,
          ),
        );
    await db
        .into(db.localUsers)
        .insert(
          LocalUsersCompanion.insert(
            localId: 'user-10',
            workspaceId: ws,
            name: 'مدير',
            username: 'admin@test.com',
            pinSalt: 's',
            pinHash: 'h',
            createdAt: now,
            updatedAt: now,
          ),
        );
    await db
        .into(db.localTables)
        .insert(
          LocalTablesCompanion.insert(
            localId: 'table-10',
            workspaceId: ws,
            name: 'طاولة 1',
            status: const Value('occupied'),
            payloadJson: const Value(
              '{"status":"occupied","opened_at":"2026-01-01T00:00:00.000Z"}',
            ),
            updatedAt: now,
          ),
        );
    await db
        .into(db.localCategories)
        .insert(
          LocalCategoriesCompanion.insert(
            localId: 'cat-10',
            workspaceId: ws,
            name: 'مشروبات',
            updatedAt: now,
          ),
        );
    await db
        .into(db.localProducts)
        .insert(
          LocalProductsCompanion.insert(
            localId: 'prod-10',
            workspaceId: ws,
            categoryLocalId: const Value('cat-10'),
            name: 'شاي',
            price: const Value(500),
            updatedAt: now,
          ),
        );
    await db
        .into(db.localCategories)
        .insert(
          LocalCategoriesCompanion.insert(
            localId: 'cat-11',
            workspaceId: otherWs,
            name: 'أخرى',
            updatedAt: now,
          ),
        );
    await db
        .into(db.localProducts)
        .insert(
          LocalProductsCompanion.insert(
            localId: 'prod-11',
            workspaceId: otherWs,
            name: 'صنف آخر',
            price: const Value(900),
            updatedAt: now,
          ),
        );
    await db
        .into(db.localOrders)
        .insert(
          LocalOrdersCompanion.insert(
            localId: 'ord-active',
            workspaceId: ws,
            deviceId: 'dev-1',
            clientReference: 'ref-active',
            orderType: 'table',
            tableLocalId: const Value('table-10'),
            posStatus: const Value('new'),
            serverId: const Value(4010),
            syncStatus: const Value('synced'),
            createdAt: now,
            updatedAt: now,
          ),
        );
    await db
        .into(db.localOrderItems)
        .insert(
          LocalOrderItemsCompanion.insert(
            localId: 'item-active',
            workspaceId: ws,
            orderLocalId: 'ord-active',
            name: 'شاي',
            quantity: 1,
            unitPrice: 500,
            totalAmount: 500,
            updatedAt: now,
          ),
        );
    await db
        .into(db.localOrders)
        .insert(
          LocalOrdersCompanion.insert(
            localId: 'ord-odd',
            workspaceId: otherWs,
            deviceId: 'dev-2',
            clientReference: 'ref-other',
            orderType: 'takeaway',
            posStatus: const Value('new'),
            createdAt: now,
            updatedAt: now,
          ),
        );
    await db
        .into(db.localInvoices)
        .insert(
          LocalInvoicesCompanion.insert(
            localId: 'inv-10',
            workspaceId: ws,
            deviceId: 'dev-1',
            invoiceNumber: const Value('INV-10'),
            orderLocalId: const Value('ord-active'),
            totalAmount: const Value(500),
            serverId: const Value(5010),
            syncStatus: const Value('synced'),
            createdAt: now,
          ),
        );
    await db
        .into(db.localPayments)
        .insert(
          LocalPaymentsCompanion.insert(
            localId: 'pay-10',
            workspaceId: ws,
            deviceId: 'dev-1',
            invoiceLocalId: const Value('inv-10'),
            orderLocalId: const Value('ord-active'),
            method: 'cash',
            amount: 500,
            clientReference: 'pay-10',
            createdAt: now,
          ),
        );
    await db
        .into(db.localInvoices)
        .insert(
          LocalInvoicesCompanion.insert(
            localId: 'inv-11',
            workspaceId: otherWs,
            deviceId: 'dev-2',
            invoiceNumber: const Value('INV-11'),
            totalAmount: const Value(900),
            createdAt: now,
          ),
        );
    await db
        .into(db.localSessions)
        .insert(
          LocalSessionsCompanion.insert(
            localId: 'sess-10',
            workspaceId: ws,
            tableLocalId: 'table-10',
            status: const Value('open'),
            openedAt: now,
            createdAt: now,
            updatedAt: now,
          ),
        );
    await queue.enqueue(
      workspaceId: ws,
      deviceId: 'dev-1',
      entityType: 'order',
      entityId: 'ord-active',
      operation: 'create',
      payload: const {'client_reference': 'ref-active'},
      clientReference: 'ref-active',
    );
    await queue.enqueue(
      workspaceId: ws,
      deviceId: 'dev-1',
      entityType: 'invoice',
      entityId: 'inv-10',
      operation: 'create',
      payload: const {'client_reference': 'inv-10'},
      clientReference: 'inv-10',
    );
    for (final row in await queue.pendingForWorkspace(ws)) {
      await queue.markSynced(row.id);
    }
  });

  tearDown(() async {
    await db.close();
  });

  test('delete all invoices stays on this workspace', () async {
    final count = await wipe.deleteAllInvoices(
      workspaceId: ws,
      permissions: admin,
    );
    expect(count, 1);
    expect(await finance.listInvoices(workspaceId: ws), isEmpty);
    expect(await (db.select(db.localPayments)).get(), isEmpty);
    final other = await finance.listInvoices(workspaceId: otherWs);
    expect(other, hasLength(1));
    expect(other.single['local_id'], 'inv-11');
    expect(await (db.select(db.localOrders)).get(), hasLength(2));
    expect(await (db.select(db.localUsers)).get(), hasLength(1));
    final invoiceOps = await (db.select(
      db.syncQueueItems,
    )..where((t) => t.entityType.equals('invoice'))).get();
    expect(invoiceOps.single.status, 'synced');
  });

  test(
    'delete all products hides catalog and leaves other workspace',
    () async {
      await db
          .into(db.localDraftCarts)
          .insert(
            LocalDraftCartsCompanion.insert(
              localId: 'cart-10',
              workspaceId: ws,
              channel: 'takeaway',
              updatedAt: now,
            ),
          );
      await db
          .into(db.localDraftCartLines)
          .insert(
            LocalDraftCartLinesCompanion.insert(
              localId: 'line-10',
              cartLocalId: 'cart-10',
              workspaceId: ws,
              productLocalId: 'prod-10',
              name: 'شاي',
              quantity: 1,
              unitPrice: 500,
              updatedAt: now,
            ),
          );
      final count = await wipe.deleteAllProducts(
        workspaceId: ws,
        permissions: admin,
      );
      expect(count, 1);
      expect(await catalog.products(ws), isEmpty);
      expect(await catalog.categories(ws), isEmpty);
      expect(await catalog.products(otherWs), hasLength(1));
      expect(await (db.select(db.localDraftCartLines)).get(), isEmpty);
      expect(await (db.select(db.localStores)).get(), hasLength(1));
    },
  );

  test('delete all kitchen tickets frees the occupied table', () async {
    final count = await wipe.deleteAllKitchenTickets(
      workspaceId: ws,
      permissions: admin,
    );
    expect(count, 1);
    expect(await (db.select(db.localOrders)).get(), hasLength(1));
    expect((await (db.select(db.localOrders)).get()).single.localId, 'ord-odd');
    final table = await (db.select(
      db.localTables,
    )..where((t) => t.localId.equals('table-10'))).getSingle();
    expect(table.status, 'available');
    final session = await (db.select(
      db.localSessions,
    )..where((t) => t.localId.equals('sess-10'))).getSingle();
    expect(session.status, 'closed');
    final board = await KitchenLocalService(db).watchBoard(ws).first;
    expect(board.active, isEmpty);
    expect(board.delivered, isEmpty);
    expect(board.cancelled, isEmpty);
    final orderOps = await (db.select(
      db.syncQueueItems,
    )..where((t) => t.entityType.equals('order'))).get();
    expect(orderOps.single.status, 'synced');
    expect(await finance.listInvoices(workspaceId: ws), hasLength(1));
  });

  test('delete all orders removes leftover non-kitchen rows too', () async {
    await db
        .into(db.localOrders)
        .insert(
          LocalOrdersCompanion.insert(
            localId: 'ord-custom',
            workspaceId: ws,
            deviceId: 'dev-1',
            clientReference: 'ref-custom',
            orderType: 'takeaway',
            posStatus: const Value('queued'),
            serverId: const Value(4099),
            syncStatus: const Value('synced'),
            createdAt: now,
            updatedAt: now,
          ),
        );
    expect(
      await wipe.deleteAllKitchenTickets(workspaceId: ws, permissions: admin),
      1,
    );
    expect(
      (await (db.select(
        db.localOrders,
      )..where((t) => t.workspaceId.equals(ws))).get()).single.localId,
      'ord-custom',
    );
    expect(await wipe.deleteAllOrders(workspaceId: ws, permissions: admin), 1);
    expect(
      await (db.select(
        db.localOrders,
      )..where((t) => t.workspaceId.equals(ws))).get(),
      isEmpty,
    );
    expect(
      (await (db.select(
        db.localOrders,
      )..where((t) => t.workspaceId.equals(otherWs))).get()).single.localId,
      'ord-odd',
    );
  });

  test('pending sync blocks local wipe', () async {
    await queue.enqueue(
      workspaceId: ws,
      deviceId: 'dev-1',
      entityType: 'order',
      entityId: 'ord-pending',
      operation: 'create',
      payload: const {'order_type': 'table'},
      clientReference: 'ord-pending',
    );
    await expectLater(
      wipe.deleteAllOrders(workspaceId: ws, permissions: admin),
      throwsA(isA<UnsyncedWipeBlocked>()),
    );
    await expectLater(
      wipe.deleteAllInvoices(workspaceId: ws, permissions: admin),
      throwsA(isA<UnsyncedWipeBlocked>()),
    );
    expect(await finance.listInvoices(workspaceId: ws), hasLength(1));
  });

  test('cashier cannot wipe invoices or catalog', () async {
    const cashier = StaffPermissions.cashierDefaults;
    await expectLater(
      wipe.deleteAllInvoices(workspaceId: ws, permissions: cashier),
      throwsA(isA<Forbidden>()),
    );
    await expectLater(
      wipe.deleteAllProducts(workspaceId: ws, permissions: cashier),
      throwsA(isA<Forbidden>()),
    );
    await expectLater(
      wipe.deleteAllOrders(workspaceId: ws, permissions: cashier),
      throwsA(isA<Forbidden>()),
    );
    await expectLater(
      wipe.deleteAllKitchenTickets(workspaceId: ws, permissions: cashier),
      throwsA(isA<Forbidden>()),
    );
    expect(await finance.listInvoices(workspaceId: ws), hasLength(1));
    expect(await catalog.products(ws), hasLength(1));
  });
}
