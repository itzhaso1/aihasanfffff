import 'dart:convert';
import 'dart:io';

import 'package:drift/drift.dart' show Value, driftRuntimeOptions;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/pos/application/reports_service.dart';
import 'package:hasim_cashier/core/repositories/local_finance_repository.dart';
import 'package:hasim_cashier/core/repositories/orders_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/util/json_numbers.dart';
import 'package:hasim_cashier/core/repositories/tables_repository.dart';

void main() {
  late AppDatabase db;
  late SyncQueueRepository queue;
  late TablesRepository tables;
  late OrdersRepository orders;
  late LocalReportsService reports;
  late LocalFinanceRepository finance;

  setUp(() {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
    tables = TablesRepository(db, queue);
    orders = OrdersRepository(db, queue);
    reports = LocalReportsService(db);
    finance = LocalFinanceRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  Future<void> seedUuidTable({
    int workspaceId = 1,
    int tableId = 4,
    String localId = 'uuid-table-4',
  }) async {
    final now = DateTime.now();
    await db
        .into(db.localTables)
        .insert(
          LocalTablesCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            serverId: Value(tableId),
            name: 'طاولة $tableId',
            status: const Value('available'),
            payloadJson: Value(
              jsonEncode({
                'id': tableId,
                'name': 'طاولة $tableId',
                'status': 'available',
              }),
            ),
            updatedAt: now,
          ),
        );
  }

  test('table order stays on the table without a sync badge', () async {
    await seedUuidTable();
    await tables.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 4,
    );
    final created = await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 4,
      clientReference: 'ord-table-1',
      items: [
        {
          'pos_menu_item_id': 11,
          'name': 'شاي',
          'quantity': 2,
          'unit_price': 5,
          'total_amount': 10,
        },
      ],
    );
    expect(created['sync_label'], isNull);
    expect(created['total_amount'], 10);

    final open = await orders.listOpenForTable(workspaceId: 1, tableId: 4);
    expect(open, hasLength(1));
    expect(open.single['sync_label'], isNull);
    expect(open.single['items'], isNotEmpty);

    final detail = await tables.getTable(1, 4);
    expect(detail?['status'], 'occupied');
    expect(detail?['open_orders_count'], 1);
    final payloadOrders = detail?['orders'];
    expect(payloadOrders, isA<List>());
    expect((payloadOrders as List), isNotEmpty);
  });

  test(
    'closing a table writes a local invoice that reports can load',
    () async {
      await seedUuidTable();
      await tables.openSessionLocal(
        workspaceId: 1,
        deviceId: 'dev-1',
        tableServerId: 4,
      );
      await orders.createTableOrder(
        workspaceId: 1,
        deviceId: 'dev-1',
        tableId: 4,
        clientReference: 'ord-close-1',
        items: [
          {
            'pos_menu_item_id': 11,
            'name': 'شاي',
            'quantity': 2,
            'unit_price': 7.5,
            'total_amount': 15,
          },
        ],
      );

      final closed = await tables.closeSessionLocal(
        workspaceId: 1,
        deviceId: 'dev-1',
        tableServerId: 4,
        paymentMethod: 'cash',
      );
      expect(closed['invoice'], isA<Map>());
      expect(closed['invoice']['total_amount'], 15);

      final table = await tables.getTable(1, 4);
      expect(table?['status'], 'available');
      final stillOpen = await orders.listOpenForTable(
        workspaceId: 1,
        tableId: 4,
      );
      expect(stillOpen, isEmpty);

      final invoices = await finance.listInvoices(
        workspaceId: 1,
        onDate: DateTime.now(),
      );
      expect(invoices, hasLength(1));
      expect(asDoubleOr(invoices.single['total_amount']), 15);
      expect(invoices.single['items'], isNotEmpty);

      final listedWithoutDate = await finance.listInvoices(
        workspaceId: 1,
        fallbackAllWorkspaces: true,
      );
      expect(listedWithoutDate, hasLength(1));

      final daily = await reports.daily(workspaceId: 1, date: DateTime.now());
      expect(daily['summary']['invoices_count'], 1);
      expect(asDoubleOr(daily['summary']['invoice_sales_total']), 15);
      expect((daily['invoices'] as List), isNotEmpty);
    },
  );

  test(
    'opening a new table session does not show completed orders from the last sitting',
    () async {
      await seedUuidTable();
      await tables.openSessionLocal(
        workspaceId: 1,
        deviceId: 'dev-1',
        tableServerId: 4,
      );
      await orders.createTableOrder(
        workspaceId: 1,
        deviceId: 'dev-1',
        tableId: 4,
        clientReference: 'ord-old-sitting',
        items: [
          {
            'pos_menu_item_id': 11,
            'name': 'قهوه كولد برو',
            'quantity': 6,
            'unit_price': 15,
            'total_amount': 90,
          },
        ],
      );
      await tables.closeSessionLocal(
        workspaceId: 1,
        deviceId: 'dev-1',
        tableServerId: 4,
        paymentMethod: 'cash',
      );

      final reopened = await tables.openSessionLocal(
        workspaceId: 1,
        deviceId: 'dev-1',
        tableServerId: 4,
      );
      expect(reopened['status'], 'occupied');
      expect(asMapList(reopened['orders']), isEmpty);
      expect(asDoubleOr(reopened['total']), 0);
      expect(asDoubleOr(reopened['last_sale_total']), 0);

      final board = await tables.listTables(1);
      expect(asMapList(board.single['orders']), isEmpty);

      final created = await orders.createTableOrder(
        workspaceId: 1,
        deviceId: 'dev-1',
        tableId: 4,
        clientReference: 'ord-new-sitting',
        items: [
          {
            'pos_menu_item_id': 12,
            'name': 'ماء',
            'quantity': 1,
            'unit_price': 2,
            'total_amount': 2,
          },
        ],
      );
      expect(created['total_amount'], 2);
      final fresh = await tables.getTable(1, 4);
      final freshOrders = asMapList(fresh?['orders']);
      expect(freshOrders, hasLength(1));
      expect(freshOrders.single['local_id'], 'ord-new-sitting');
      expect(asDoubleOr(fresh?['total']), 2);
      expect(fresh?['items_count'], 1);

      final storedOld = await (db.select(
        db.localOrders,
      )..where((t) => t.localId.equals('ord-old-sitting'))).getSingle();
      expect(storedOld.posStatus, 'completed');
      expect(storedOld.paymentStatus, 'paid');
      expect(
        storedOld.sessionLocalId,
        isNot(freshOrders.single['session_local_id']),
      );
      expect(
        created['session_local_id'],
        freshOrders.single['session_local_id'],
      );
      expect(created['session_local_id'], isNot(storedOld.sessionLocalId));

      final invoices = await finance.listInvoices(
        workspaceId: 1,
        onDate: DateTime.now(),
      );
      expect(invoices, hasLength(1));
      expect(asDoubleOr(invoices.single['total_amount']), 90);
    },
  );

  test('session 2 pizza does not revive session 1 burger and cola', () async {
    await seedUuidTable();
    await tables.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 4,
    );
    await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 4,
      clientReference: 'sess1-burger',
      items: [
        {'name': 'Burger', 'quantity': 2, 'unit_price': 10, 'total_amount': 20},
      ],
    );
    await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 4,
      clientReference: 'sess1-cola',
      items: [
        {'name': 'Cola', 'quantity': 1, 'unit_price': 5, 'total_amount': 5},
      ],
    );
    await tables.closeSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 4,
      paymentMethod: 'cash',
    );

    await tables.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 4,
    );
    expect(asMapList((await tables.getTable(1, 4))?['orders']), isEmpty);

    await orders.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 4,
      clientReference: 'sess2-pizza',
      items: [
        {'name': 'Pizza', 'quantity': 1, 'unit_price': 12, 'total_amount': 12},
      ],
    );
    final session2 = await tables.getTable(1, 4);
    final names = [
      for (final order in asMapList(session2?['orders']))
        for (final item in asMapList(order['items'])) '${item['name']}',
    ];
    expect(names, ['Pizza']);
    expect(names, isNot(contains('Burger')));
    expect(names, isNot(contains('Cola')));
    expect(asMapList(session2?['orders']), hasLength(1));
    expect(asDoubleOr(session2?['total']), 12);

    expect(await db.select(db.localOrders).get(), hasLength(3));
    expect(await db.select(db.localInvoices).get(), hasLength(1));
  });

  test('closed sitting stays empty after a database restart', () async {
    driftRuntimeOptions.dontWarnAboutMultipleDatabases = true;
    final dir = await Directory.systemTemp.createTemp('table_session_restart_');
    final file = File('${dir.path}/pos.sqlite');
    final db1 = AppDatabase.file(file);
    final queue1 = SyncQueueRepository(db1);
    final tables1 = TablesRepository(db1, queue1);
    final orders1 = OrdersRepository(db1, queue1);
    await db1
        .into(db1.localTables)
        .insert(
          LocalTablesCompanion.insert(
            localId: 'uuid-table-4',
            workspaceId: 1,
            serverId: const Value(4),
            name: 'طاولة 4',
            status: const Value('available'),
            payloadJson: Value(
              jsonEncode({'id': 4, 'name': 'طاولة 4', 'status': 'available'}),
            ),
            updatedAt: DateTime.now(),
          ),
        );
    await tables1.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 4,
    );
    await orders1.createTableOrder(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableId: 4,
      clientReference: 'restart-old',
      items: [
        {'name': 'Burger', 'quantity': 1, 'unit_price': 10, 'total_amount': 10},
      ],
    );
    await tables1.closeSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 4,
      paymentMethod: 'cash',
    );
    await db1.close();

    final db2 = AppDatabase.file(file);
    final queue2 = SyncQueueRepository(db2);
    final tables2 = TablesRepository(db2, queue2);
    final finance2 = LocalFinanceRepository(db2);
    final reopened = await tables2.openSessionLocal(
      workspaceId: 1,
      deviceId: 'dev-1',
      tableServerId: 4,
    );
    expect(asMapList(reopened['orders']), isEmpty);
    expect(asDoubleOr(reopened['total']), 0);
    final invoices = await finance2.listInvoices(workspaceId: 1);
    expect(invoices, hasLength(1));
    expect(asDoubleOr(invoices.single['total_amount']), 10);
    final persisted = await db2.select(db2.localOrders).get();
    expect(persisted, hasLength(1));
    expect(persisted.single.localId, 'restart-old');
    expect(persisted.single.posStatus, 'completed');
    await db2.close();
  });

  test('reports.daily completes with an empty workspace', () async {
    final daily = await reports
        .daily(workspaceId: 1, date: DateTime.now())
        .timeout(const Duration(seconds: 2));
    expect(daily['summary']['invoices_count'], 0);
    expect(daily['source'], 'local_sqlite');
  });

  test(
    'offline invoices tab can find a sale saved under another workspace id',
    () async {
      final now = DateTime.now();
      await db
          .into(db.localInvoices)
          .insert(
            LocalInvoicesCompanion.insert(
              localId: 'inv-other-ws',
              workspaceId: 42,
              deviceId: 'dev-1',
              invoiceNumber: const Value('INV-OTHER'),
              totalAmount: const Value(2500),
              createdAt: now,
            ),
          );
      final found = await finance.listInvoices(
        workspaceId: 900001,
        fallbackAllWorkspaces: true,
      );
      expect(found, hasLength(1));
      expect(found.single['invoice_number'], 'INV-OTHER');
    },
  );
}
