import 'dart:convert';

import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/pos/pos_mode.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_queue_classifier.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase db;
  late SyncQueueRepository queue;

  const workspaceId = 1;
  const deviceId = 'POS-2D';

  setUp(() {
    db = AppDatabase.memory();
    queue = SyncQueueRepository(db);
  });

  tearDown(() async {
    await db.close();
  });

  Future<void> insertOrder({
    required String localId,
    String type = 'takeaway',
    String paymentStatus = 'paid',
    int? serverId,
  }) {
    final now = DateTime.now();
    return db
        .into(db.localOrders)
        .insert(
          LocalOrdersCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            deviceId: deviceId,
            clientReference: localId,
            orderType: type,
            paymentStatus: Value(paymentStatus),
            serverId: Value(serverId),
            createdAt: now,
            updatedAt: now,
          ),
        );
  }

  Future<void> insertInvoice({
    required String localId,
    required String orderLocalId,
    int? serverId,
  }) {
    return db
        .into(db.localInvoices)
        .insert(
          LocalInvoicesCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            deviceId: deviceId,
            invoiceNumber: const Value('INV-1'),
            localInvoiceNumber: const Value('INV-1'),
            orderLocalId: Value(orderLocalId),
            serverId: Value(serverId),
            createdAt: DateTime.now(),
          ),
        );
  }

  Future<void> insertQueue({
    required String entityType,
    required String entityId,
    required String operation,
    required String status,
    Map<String, dynamic> payload = const {},
    String? lastError,
    int? workspace,
  }) {
    final now = DateTime.now();
    return db
        .into(db.syncQueueItems)
        .insert(
          SyncQueueItemsCompanion.insert(
            workspaceId: workspace ?? workspaceId,
            deviceId: deviceId,
            entityType: entityType,
            entityId: entityId,
            operation: operation,
            payloadJson: jsonEncode(payload),
            clientReference: entityId,
            operationUuid: Value('op-$entityType-$entityId'),
            status: Value(status),
            lastError: Value(lastError),
            createdAt: now,
            updatedAt: now,
          ),
        );
  }

  Map<String, dynamic> salePayload(String type) => {
    'order_type': type,
    'client_reference': 'x',
    'items': [
      {'pos_menu_item_id': 9, 'quantity': 1, 'unit_price': 10},
    ],
  };

  test('18-pending screenshot: waiting includes failed; invoice pending does not', () async {
    for (var i = 1; i <= 6; i++) {
      await insertQueue(
        entityType: 'stock',
        entityId: 'stock-$i',
        operation: 'movement',
        status: 'failed',
        lastError: 'عملية غير مدعومة',
      );
    }
    for (var i = 1; i <= 8; i++) {
      await insertQueue(
        entityType: 'table_session',
        entityId: 't-$i',
        operation: i.isEven ? 'close' : 'open',
        status: 'pending',
      );
    }
    await insertOrder(localId: 'table-unpaid-a', type: 'table', paymentStatus: 'unpaid');
    await insertOrder(localId: 'table-unpaid-b', type: 'table', paymentStatus: 'unpaid');
    await insertQueue(
      entityType: 'order',
      entityId: 'table-unpaid-a',
      operation: 'create',
      status: 'pending',
      payload: salePayload('table'),
    );
    await insertQueue(
      entityType: 'order',
      entityId: 'table-unpaid-b',
      operation: 'create',
      status: 'pending',
      payload: salePayload('table'),
    );
    await insertOrder(localId: 'tw-ready-a');
    await insertOrder(localId: 'tw-ready-b');
    await insertQueue(
      entityType: 'order',
      entityId: 'tw-ready-a',
      operation: 'create',
      status: 'pending',
      payload: salePayload('takeaway'),
    );
    await insertQueue(
      entityType: 'order',
      entityId: 'tw-ready-b',
      operation: 'create',
      status: 'pending',
      payload: salePayload('takeaway'),
    );

    final raw = await queue.counts(workspaceId);
    expect(raw.waiting, 18);
    expect(raw.failed, 6);

    final classified = await SyncQueueClassifier(db).counts(workspaceId);
    expect(classified.totalOpen, 18);
    expect(classified.failed, 6);
    expect(classified.ready, 12);
    expect(classified.unsupported, 0);
    expect(classified.invoicePending, 12);
    expect(classified.invoicePending, isNot(raw.waiting));
  });

  test('already-applied order is reconciled on push without a second POST', () async {
    await insertOrder(localId: 'tw-ack-local', serverId: 4401);
    await insertQueue(
      entityType: 'order',
      entityId: 'tw-ack-local',
      operation: 'create',
      status: 'pending',
      payload: salePayload('takeaway'),
    );
    final before = await SyncQueueClassifier(db).classifyWorkspace(workspaceId);
    expect(before.single.bucket, SyncQueueBucket.alreadyApplied);

    var posts = 0;
    await SyncEngineV2(
      db,
      queue,
      postPushBatch: (_) async {
        posts++;
        return {'accepted': <Map<String, dynamic>>[], 'failed': <Map<String, dynamic>>[]};
      },
    ).pushPending(workspaceId: workspaceId);

    expect(posts, 0);
    final after = await (db.select(db.syncQueueItems)).getSingle();
    expect(after.status, 'synced');
    expect((await SyncQueueClassifier(db).counts(workspaceId)).invoicePending, 0);
  });

  test('900001 leftovers are standalone, not cloud invoice pending', () async {
    await insertQueue(
      entityType: 'order',
      entityId: 'stand-1',
      operation: 'create',
      status: 'pending',
      workspace: PosMode.standaloneWorkspaceId,
      payload: salePayload('takeaway'),
    );
    final item = (await SyncQueueClassifier(db).classifyWorkspace(
      PosMode.standaloneWorkspaceId,
    )).single;
    expect(item.bucket, SyncQueueBucket.standalone);
    expect(
      (await SyncQueueClassifier(db).counts(workspaceId)).invoicePending,
      0,
    );
  });

  test('invoice without order.server_id is waitingParent, not ready', () async {
    await insertOrder(localId: 'tw-parent');
    await insertInvoice(localId: 'inv-1', orderLocalId: 'tw-parent');
    await insertQueue(
      entityType: 'invoice',
      entityId: 'inv-1',
      operation: 'create',
      status: 'pending',
      payload: {
        'order_type': 'takeaway',
        'order_local_id': 'tw-parent',
      },
    );
    final item = (await SyncQueueClassifier(db).classifyWorkspace(workspaceId)).single;
    expect(item.bucket, SyncQueueBucket.waitingParent);
    expect((await SyncQueueClassifier(db).counts(workspaceId)).invoicePending, 1);
  });

  test('menu and table-master rows are scoped pending with sessions', () async {
    await insertQueue(
      entityType: 'category',
      entityId: 'cat-1',
      operation: 'create',
      status: 'pending',
      payload: {'name': 'مشروبات'},
    );
    await insertQueue(
      entityType: 'table',
      entityId: 'tbl-1',
      operation: 'create',
      status: 'pending',
      payload: {'name': 'VIP 1'},
    );
    await insertQueue(
      entityType: 'table_session',
      entityId: 'sess-1',
      operation: 'open',
      status: 'pending',
    );
    final counts = await SyncQueueClassifier(db).counts(workspaceId);
    expect(counts.ready, 3);
    expect(counts.unsupported, 0);
    expect(counts.scopedPending, 3);
  });

  test('takeaway waiting for a local customer is waitingParent, not Laravel-down',
      () async {
    await insertOrder(localId: 'tw-cust');
    await insertQueue(
      entityType: 'order',
      entityId: 'tw-cust',
      operation: 'create',
      status: 'pending',
      payload: {
        'order_type': 'takeaway',
        'customer_local_id': 'cust-1',
        'items': [
          {'pos_menu_item_id': 9, 'quantity': 1},
        ],
      },
    );
    final item =
        (await SyncQueueClassifier(db).classifyWorkspace(workspaceId)).single;
    expect(item.bucket, SyncQueueBucket.waitingParent);
    expect(item.reason, contains('العميل'));
  });

  test('invoice waiting on a failed order names the parent error', () async {
    await insertOrder(localId: 'tw-fail');
    await insertInvoice(localId: 'inv-fail', orderLocalId: 'tw-fail');
    await insertQueue(
      entityType: 'order',
      entityId: 'tw-fail',
      operation: 'create',
      status: 'failed',
      lastError: 'صنف غير موجود على Laravel',
      payload: {
        'order_type': 'takeaway',
        'items': [
          {'pos_menu_item_id': 9, 'quantity': 1},
        ],
      },
    );
    await insertQueue(
      entityType: 'invoice',
      entityId: 'inv-fail',
      operation: 'create',
      status: 'pending',
      payload: {
        'order_type': 'takeaway',
        'order_local_id': 'tw-fail',
      },
    );
    final items = await SyncQueueClassifier(db).classifyWorkspace(workspaceId);
    final invoice = items.firstWhere((i) => i.row.entityType == 'invoice');
    expect(invoice.bucket, SyncQueueBucket.waitingParent);
    expect(invoice.reason, contains('صنف غير موجود'));
  });

  test('table close waits for unsynced session open', () async {
    await queue.enqueue(
      workspaceId: workspaceId,
      deviceId: deviceId,
      entityType: 'table_session',
      entityId: 'w1_table_10',
      operation: 'open',
      payload: {'table_server_id': 10},
      clientReference: 'open-1',
    );
    await queue.enqueue(
      workspaceId: workspaceId,
      deviceId: deviceId,
      entityType: 'table_session',
      entityId: 'w1_table_10',
      operation: 'close',
      payload: {'table_server_id': 10},
      clientReference: 'close-1',
    );
    final items = await SyncQueueClassifier(db).classifyWorkspace(workspaceId);
    final close = items.firstWhere((i) => i.row.operation == 'close');
    expect(close.bucket, SyncQueueBucket.waitingParent);
    expect(close.reason, contains('فتح الجلسة'));
  });

  test('kitchen status update is ready after the order has a server id', () async {
    await insertOrder(localId: 'k-ready', serverId: 77);
    await insertQueue(
      entityType: 'order',
      entityId: 'k-ready',
      operation: 'update',
      status: 'pending',
      payload: {
        'kitchen_status': true,
        'pos_status': 'ready',
        'client_reference': 'k-ready',
        'order_server_id': 77,
      },
    );
    final item = (await SyncQueueClassifier(db).classifyWorkspace(workspaceId))
        .single;
    expect(item.bucket, SyncQueueBucket.ready);
  });
}
