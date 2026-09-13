import 'dart:convert';

import 'package:drift/drift.dart' hide isNull, isNotNull;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/local_db/local_ids.dart';
import 'package:hasim_cashier/core/local_db/workspace_scope.dart';
import 'package:hasim_cashier/core/repositories/orders_repository.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/repositories/tables_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';

/// Minimal Laravel stand-in: applies pushes to an in-memory state and appends
/// pos_sync_changes rows exactly like PosSyncChangeObserver / recorder do
/// (table snapshot carries session_id / session_open / opened_at; orders carry
/// source + table_session_id; echoes carry origin_device_id).
class _FakeLaravel {
  _FakeLaravel({required this.tableId, required this.device});

  final int tableId;
  final String device;
  final log = <Map<String, dynamic>>[];
  final pushes = <String>[];
  int? openSessionId;
  DateTime? openedAt;
  int nextSession = 100;
  int nextOrder = 500;
  final orders = <int, Map<String, dynamic>>{};

  void recordTable({String? origin}) {
    log.add({
      'version': log.length + 1,
      'entity': 'table',
      'operation': 'update',
      'id': tableId,
      'origin_device_id': origin,
      'data': {
        'id': tableId,
        'name': 'T$tableId',
        'status': orders.values.any((o) =>
                o['table_session_id'] == openSessionId &&
                o['pos_status'] != 'cancelled')
            ? 'occupied'
            : 'available',
        'session_id': openSessionId,
        'session_open': openSessionId != null,
        'opened_at': openedAt?.toUtc().toIso8601String(),
      },
    });
  }

  void recordOrder(int id, {String? origin}) {
    log.add({
      'version': log.length + 1,
      'entity': 'order',
      'operation': 'update',
      'id': id,
      'origin_device_id': origin,
      'data': Map<String, dynamic>.from(orders[id]!),
    });
  }

  Map<String, dynamic> openSession(String key) {
    pushes.add('open');
    if (openSessionId == null) {
      openSessionId = nextSession++;
      openedAt = DateTime.now();
      recordTable(origin: device);
    }
    return {
      'session_id': openSessionId,
      'table_id': tableId,
      'status': 'open',
      'opened_at': openedAt!.toUtc().toIso8601String(),
    };
  }

  Map<String, dynamic> createOrder(Map<String, dynamic> payload, String key) {
    pushes.add('order');
    final existing = orders.values
        .where((o) => o['client_reference'] == payload['client_reference']);
    if (existing.isNotEmpty) return existing.first;
    final id = nextOrder++;
    orders[id] = {
      ...payload,
      'id': id,
      'order_number': 'ORD-$id',
      'source': 'pos',
      'pos_status': 'new',
      'payment_status': payload['payment_status'] ?? 'unpaid',
      'dining_table_id': tableId,
      'table_session_id': openSessionId,
    };
    recordOrder(id, origin: device);
    recordTable(origin: device);
    return orders[id]!;
  }

  Map<String, dynamic> closeSession(int? sessionId, String key) {
    pushes.add('close:$sessionId');
    for (final o in orders.values) {
      if (o['table_session_id'] == openSessionId) {
        o['payment_status'] = 'paid';
        o['pos_status'] = 'completed';
        recordOrder(o['id'] as int, origin: device);
      }
    }
    openSessionId = null;
    openedAt = null;
    recordTable(origin: device);
    return {'invoice': null};
  }

  /// Guest QR order placed on the open sitting (no device origin).
  int qrOrder(String clientRef) {
    final id = nextOrder++;
    orders[id] = {
      'id': id,
      'client_reference': clientRef,
      'order_number': 'QR-$id',
      'source': 'qr_menu',
      'order_type': 'table',
      'pos_status': 'new',
      'payment_status': 'unpaid',
      'dining_table_id': tableId,
      'table_session_id': openSessionId,
      'table_name': 'T$tableId',
      'subtotal': 12,
      'tax_amount': 0,
      'discount_amount': 0,
      'total_amount': 12,
      'placed_at': DateTime.now().toUtc().toIso8601String(),
      'items': [
        {
          'id': id * 10,
          'pos_menu_item_id': 9,
          'product_name': 'شاي',
          'quantity': 1,
          'unit_price': 12,
          'total_amount': 12,
        },
      ],
    };
    recordOrder(id);
    recordTable();
    return id;
  }

  /// Web / Laravel-side open with no orders (dining_tables.status stays
  /// available; only the TableSession row changes).
  void webOpen() {
    openSessionId = nextSession++;
    openedAt = DateTime.now();
    recordTable();
  }

  void webClose() {
    openSessionId = null;
    openedAt = null;
    recordTable();
  }

  Map<String, dynamic> pull(int since, int limit) {
    final rows = log.where((c) => (c['version'] as int) > since).toList();
    final page = rows.take(limit).toList();
    return {
      'cursor': page.isEmpty ? log.length : page.last['version'],
      'server_cursor': log.length,
      'has_more': rows.length > page.length,
      'changes': page,
    };
  }
}

void main() {
  const ws = 1;
  const tableId = 10;
  const device = 'dev-1';
  late AppDatabase db;
  late SyncQueueRepository queue;
  late TablesRepository tables;
  late OrdersRepository orders;
  late _FakeLaravel laravel;
  late SyncEngineV2 engine;

  setUp(() async {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
    tables = TablesRepository(db, queue);
    orders = OrdersRepository(db, queue);
    laravel = _FakeLaravel(tableId: tableId, device: device);
    engine = SyncEngineV2(
      db,
      queue,
      postSessionOpen: (id, key) async => laravel.openSession(key),
      postOrder: (payload, key) async => laravel.createOrder(payload, key),
      postSessionClose: (id, sessionId, payload, key) async =>
          laravel.closeSession(sessionId, key),
      postInvoice: (orderServerId, key) async => {
        'invoice_id': 900,
        'id': 900,
        'invoice_number': 'INV-900',
        'total_amount': 0,
        'currency': 'SAR',
      },
      fetchChanges: (since, limit) async => laravel.pull(since, limit),
    );
    await db.into(db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: LocalIds.table(ws, tableId),
            workspaceId: ws,
            serverId: const Value(tableId),
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
  });

  tearDown(() async {
    await db.close();
  });

  Future<SyncEngineV2Result> sync() =>
      engine.syncBidirectional(workspaceId: ws, deviceId: device);

  Future<LocalTable> table() => (db.select(db.localTables)
        ..where((t) => t.localId.equals(LocalIds.table(ws, tableId))))
      .getSingle();

  Future<List<LocalSession>> openSessions() => (db.select(db.localSessions)
        ..where((t) =>
            t.tableLocalId.equals(LocalIds.table(ws, tableId)) &
            t.status.equals('open')))
      .get();

  Map<String, dynamic> payloadOf(LocalTable row) =>
      jsonDecode(row.payloadJson) as Map<String, dynamic>;

  test('seven-step lifecycle stays consistent in both directions', () async {
    // 1. Open a table offline, then sync.
    await tables.openSessionLocal(
      workspaceId: ws,
      deviceId: device,
      tableServerId: tableId,
    );
    var result = await sync();
    expect(result.failed, 0);
    expect(result.pullFailed, isFalse);
    var row = await table();
    expect(row.status, 'occupied');
    expect(row.sessionServerId, 100);
    final firstSitting = '${payloadOf(row)['session_client_id']}';
    expect(firstSitting, isNotEmpty);
    expect(await openSessions(), hasLength(1));

    // 2. Add an order and sync: one local row, one server row, echo merges.
    await orders.createTableOrder(
      workspaceId: ws,
      deviceId: device,
      tableId: tableId,
      clientReference: 'ord-1',
      items: [
        {
          'pos_menu_item_id': 9,
          'name': 'شاي',
          'quantity': 2,
          'unit_price': 5,
          'total_amount': 10,
        },
      ],
    );
    result = await sync();
    expect(result.failed, 0);
    final localOrders = await db.select(db.localOrders).get();
    expect(localOrders, hasLength(1));
    expect(localOrders.single.serverId, 500);
    expect(localOrders.single.syncStatus, 'synced');
    expect(localOrders.single.sessionLocalId, firstSitting);
    expect(laravel.orders, hasLength(1));

    // 3. Close the table; Laravel frees the sitting; pull confirms.
    await tables.closeSessionLocal(
      workspaceId: ws,
      deviceId: device,
      tableServerId: tableId,
      paymentMethod: 'cash',
    );
    result = await sync();
    expect(result.failed, 0);
    expect(laravel.pushes, contains('close:100'));
    expect(laravel.openSessionId, isNull);
    row = await table();
    expect(row.status, 'available');
    expect(row.sessionServerId, isNull);
    expect(payloadOf(row)['session_client_id'], isNull);
    expect(payloadOf(row)['opened_at'], isNull);
    expect(await openSessions(), isEmpty);
    expect(await queue.pendingForWorkspace(ws), isEmpty);

    // 4. Open a new sitting: fresh ids, fresh opened_at, nothing leaks over.
    await Future<void>.delayed(const Duration(milliseconds: 5));
    await tables.openSessionLocal(
      workspaceId: ws,
      deviceId: device,
      tableServerId: tableId,
    );
    result = await sync();
    expect(result.failed, 0);
    row = await table();
    expect(row.status, 'occupied');
    expect(row.sessionServerId, 101);
    final secondSitting = '${payloadOf(row)['session_client_id']}';
    expect(secondSitting, isNot(firstSitting));
    final open = await openSessions();
    expect(open, hasLength(1));
    expect(open.single.localId, secondSitting);
    final board = await tables.getTable(ws, tableId);
    expect(board?['status'], 'occupied');
    expect((board?['orders'] as List?) ?? const [], isEmpty,
        reason: 'previous sitting orders must not leak into the new one');

    // 5. Guest orders from the QR menu on the new sitting → shows on cashier.
    final qrId = laravel.qrOrder('qr-1');
    result = await sync();
    expect(result.failed, 0);
    final qr = await (db.select(db.localOrders)
          ..where((t) => t.clientReference.equals('qr-1')))
        .getSingle();
    expect(qr.serverId, qrId);
    expect(qr.tableServerId, tableId);
    expect(qr.sessionLocalId, secondSitting);
    row = await table();
    expect(row.status, 'occupied');
    final tableOrders = (payloadOf(row)['orders'] as List).cast<Map>();
    expect(tableOrders, hasLength(1));
    expect(tableOrders.single['id'], qrId);
    final detail = await tables.getTable(ws, tableId);
    expect((detail?['orders'] as List).length, 1);

    // 6. Two-way: Laravel (web) closes the sitting → cashier frees the table;
    //    Laravel opens again with no orders → cashier shows it occupied.
    laravel.webClose();
    result = await sync();
    expect(result.failed, 0);
    row = await table();
    expect(row.status, 'available');
    expect(row.sessionServerId, isNull);
    expect(await openSessions(), isEmpty);

    final cursorBeforeReopen = int.parse((await db.readCursor(ws))!);
    laravel.webOpen();
    result = await sync();
    row = await table();
    expect(row.status, 'occupied', reason: 'open session wins over status flag');
    expect(row.sessionServerId, 102);
    expect(await openSessions(), hasLength(1));

    // 7. Repeated sync, partial re-delivery, and a full replay from cursor 0
    //    create nothing new, re-push nothing, and leave state untouched.
    Future<Map<String, Object>> snapshot() async {
      final o = await db.select(db.localOrders).get();
      final s = await db.select(db.localSessions).get();
      final t = await db.select(db.localTables).get();
      final items = await db.select(db.localOrderItems).get();
      return {
        'orders': o.length,
        'order_states': (o
                .map((e) => '${e.localId}:${e.posStatus}:${e.paymentStatus}:'
                    '${e.serverId}:${e.sessionLocalId}')
                .toList()
              ..sort())
            .join('|'),
        'items': items.length,
        'sessions': s.length,
        'session_states': (s
                .map((e) => '${e.localId}:${e.status}:${e.serverId}')
                .toList()
              ..sort())
            .join('|'),
        'tables': t.length,
        'table_state': '${t.single.status}:${t.single.sessionServerId}',
      };
    }

    final before = await snapshot();
    final logBefore = laravel.log.length;
    for (var i = 0; i < 3; i++) {
      result = await sync();
      expect(result.failed, 0);
      expect(result.pulled, 0);
    }
    expect(await snapshot(), before);

    await db.writeCursor(ws, '$cursorBeforeReopen');
    result = await sync();
    expect(result.pulled, greaterThan(0));
    expect(await snapshot(), before,
        reason: 'partial re-delivery must be a no-op');

    await db.writeCursor(ws, '0');
    result = await sync();
    expect(result.pullFailed, isFalse);
    expect(result.pulled, laravel.log.length);
    expect(await snapshot(), before,
        reason: 'replay from cursor 0 must be a no-op');
    expect(int.parse((await db.readCursor(ws))!), laravel.log.length);

    expect(laravel.log.length, logBefore, reason: 'replay must not re-push');
    expect(laravel.orders, hasLength(2));
    expect(await queue.pendingForWorkspace(ws), isEmpty);
    row = await table();
    expect(row.status, 'occupied');
    expect(row.sessionServerId, 102);
    expect(await openSessions(), hasLength(1));

    // Every server sitting maps to exactly one local row.
    final sessions = await db.select(db.localSessions).get();
    final serverIds =
        sessions.map((s) => s.serverId).whereType<int>().toList();
    expect(serverIds.toSet().length, serverIds.length);
    expect(serverIds.toSet(), {100, 101, 102});
    expect(sessions, hasLength(3));
  });

  test('open table detail is notified when a pulled QR order lands', () async {
    await tables.openSessionLocal(
      workspaceId: ws,
      deviceId: device,
      tableServerId: tableId,
    );
    await sync();

    final events = <int>[];
    final sub = tables
        .watchTableActivity(ws, tableId)
        .listen((_) => events.add(events.length));
    await Future<void>.delayed(Duration.zero);
    final baseline = events.length;

    laravel.qrOrder('qr-live');
    await sync();
    await Future<void>.delayed(Duration.zero);
    expect(events.length, greaterThan(baseline),
        reason: 'detail screen must reload when sync writes this table');

    final before = events.length;
    laravel.webClose();
    await sync();
    await Future<void>.delayed(Duration.zero);
    expect(events.length, greaterThan(before));
    await sub.cancel();
  });
}
