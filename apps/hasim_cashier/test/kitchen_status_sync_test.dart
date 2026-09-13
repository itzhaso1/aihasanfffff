import 'package:drift/drift.dart' show Value;
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim_cashier/core/local_db/app_database.dart';
import 'package:hasim_cashier/core/pos/application/kitchen_local_service.dart';
import 'package:hasim_cashier/core/pos/application/local_auth_service.dart';
import 'package:hasim_cashier/core/repositories/sync_queue_repository.dart';
import 'package:hasim_cashier/core/sync/sync_engine_v2.dart';
import 'package:hasim_cashier/core/sync/sync_pull_applier.dart';
import 'package:hasim_cashier/core/sync/sync_queue_classifier.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppDatabase phone;
  late AppDatabase windows;
  late SyncQueueRepository phoneQueue;
  late SyncQueueRepository windowsQueue;
  late KitchenLocalService phoneKitchen;
  late KitchenLocalService windowsKitchen;

  const workspaceId = 10;
  const productServerId = 42;

  Future<void> seedOrder(
    AppDatabase db, {
    required String localId,
    required int serverId,
    String status = 'new',
    String type = 'takeaway',
  }) async {
    final now = DateTime.now();
    await db.into(db.localOrders).insert(
          LocalOrdersCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            deviceId: 'phone',
            serverId: Value(serverId),
            clientReference: localId,
            orderType: type,
            posStatus: Value(status),
            paymentStatus: const Value('unpaid'),
            syncStatus: const Value('synced'),
            createdAt: now,
            updatedAt: now,
          ),
        );
    await db.into(db.localOrderItems).insert(
          LocalOrderItemsCompanion.insert(
            localId: '$localId-item',
            workspaceId: workspaceId,
            orderLocalId: localId,
            productServerId: const Value(productServerId),
            name: 'برجر',
            quantity: 2,
            unitPrice: 1500,
            totalAmount: 3000,
            updatedAt: now,
          ),
        );
  }

  setUp(() async {
    phone = AppDatabase.memory();
    windows = AppDatabase.memory();
    phoneQueue = SyncQueueRepository(phone);
    windowsQueue = SyncQueueRepository(windows);
    phoneKitchen = KitchenLocalService(
      phone,
      queue: phoneQueue,
      deviceId: () async => 'POS-PHONE',
    );
    windowsKitchen = KitchenLocalService(
      windows,
      queue: windowsQueue,
      deviceId: () async => 'POS-WINDOWS',
    );
    await seedOrder(phone, localId: 'ord-1', serverId: 501);
    await seedOrder(windows, localId: 'ord-1', serverId: 501);
  });

  tearDown(() async {
    await phone.close();
    await windows.close();
  });

  Future<List<String>> pushStatus(AppDatabase db, SyncQueueRepository queue) async {
    final types = <String>[];
    await SyncEngineV2(
      db,
      queue,
      postPushBatch: (body) async {
        final ops = (body['operations'] as List).cast<Map>();
        for (final op in ops) {
          types.add(op['type'] as String);
        }
        return {
          'accepted': [
            for (final op in ops)
              {
                'id': op['id'],
                'status': 'applied',
                'entity_id': 501,
                'result': {
                  'id': 501,
                  'pos_status': op['data']['pos_status'],
                },
              },
          ],
          'failed': <Map<String, dynamic>>[],
        };
      },
    ).pushPending(workspaceId: workspaceId);
    return types;
  }

  Future<void> pullStatus(
    AppDatabase db,
    String status, {
    int fromCursor = 0,
    String operation = 'update',
  }) {
    return SyncPullApplier(db).applyBatch(
      workspaceId: workspaceId,
      fromCursor: fromCursor,
      responseCursor: fromCursor + 1,
      changes: [
        {
          'version': fromCursor + 1,
          'entity': 'order',
          'operation': operation,
          'id': 501,
          'data': {
            'id': 501,
            'client_reference': 'ord-1',
            'order_type': 'takeaway',
            'pos_status': status,
            'payment_status': 'pending',
            'items': [
              {
                'id': 9,
                'product_name': 'برجر',
                'quantity': 2,
              },
            ],
          },
        },
      ],
    );
  }

  test('TEST 1 NEW stays on the active board', () async {
    final board = await phoneKitchen.watchBoard(workspaceId).first;
    expect(board.active, hasLength(1));
    expect(board.active.single['pos_status'], 'new');
    expect(board.delivered, isEmpty);
    expect(board.cancelled, isEmpty);
  });

  test('TEST 2 PREPARING stays active and is queued', () async {
    await phoneKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'preparing',
      permissions: LocalAuthService.adminPermissions,
    );
    final board = await phoneKitchen.watchBoard(workspaceId).first;
    expect(board.active.single['pos_status'], 'preparing');
    expect(
      (await SyncQueueClassifier(phone).classifyWorkspace(workspaceId))
          .single
          .bucket,
      SyncQueueBucket.ready,
    );
  });

  test('TEST 3 / 6 phone READY appears on windows', () async {
    await phoneKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'ready',
      permissions: LocalAuthService.adminPermissions,
    );
    expect(await pushStatus(phone, phoneQueue), ['order.updated']);
    await pullStatus(windows, 'ready');
    final board = await windowsKitchen.watchBoard(workspaceId).first;
    expect(board.active.single['pos_status'], 'ready');
    expect(await (windows.select(windows.localOrders)).get(), hasLength(1));
  });

  test('TEST 4 / 7 windows DELIVERED leaves both active boards', () async {
    await phoneKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'ready',
      permissions: LocalAuthService.adminPermissions,
    );
    await pushStatus(phone, phoneQueue);
    await pullStatus(windows, 'ready');

    await windowsKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'delivered',
      permissions: LocalAuthService.adminPermissions,
    );
    expect(await pushStatus(windows, windowsQueue), ['order.updated']);
    await pullStatus(phone, 'delivered', fromCursor: 1);

    final phoneBoard = await phoneKitchen.watchBoard(workspaceId).first;
    final windowsBoard = await windowsKitchen.watchBoard(workspaceId).first;
    expect(phoneBoard.active, isEmpty);
    expect(windowsBoard.active, isEmpty);
    expect(phoneBoard.delivered.single['pos_status'], 'delivered');
    expect(windowsBoard.delivered.single['pos_status'], 'delivered');
    expect(await (phone.select(phone.localOrderItems)).get(), isNotEmpty);
    expect(await (phone.select(phone.localInvoices)).get(), isEmpty);
  });

  test('TEST 5 CANCELLED moves to cancelled history', () async {
    await phoneKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'cancelled',
      permissions: LocalAuthService.adminPermissions,
    );
    await pushStatus(phone, phoneQueue);
    await pullStatus(windows, 'cancelled');
    final board = await windowsKitchen.watchBoard(workspaceId).first;
    expect(board.active, isEmpty);
    expect(board.cancelled.single['pos_status'], 'cancelled');
    expect(await (windows.select(windows.localOrders)).get(), hasLength(1));
  });

  test('TEST 8 offline status change is local immediately', () async {
    await phoneKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'ready',
      permissions: LocalAuthService.adminPermissions,
    );
    expect(
      (await phoneKitchen.watchBoard(workspaceId).first).active.single['pos_status'],
      'ready',
    );
    expect(
      (await windowsKitchen.watchBoard(workspaceId).first).active.single['pos_status'],
      'new',
    );
    expect(await phoneQueue.pendingCount(workspaceId), 1);
  });

  test('TEST 11 retry of the same status does not duplicate the ticket', () async {
    await phoneKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'ready',
      permissions: LocalAuthService.adminPermissions,
    );
    await phoneKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'ready',
      permissions: LocalAuthService.adminPermissions,
    );
    expect(await (phone.select(phone.syncQueueItems)).get(), hasLength(1));
    await pullStatus(windows, 'ready');
    await pullStatus(windows, 'ready', fromCursor: 1);
    expect(await (windows.select(windows.localOrders)).get(), hasLength(1));
  });

  test('TEST 12 kitchen status push never enqueues an invoice', () async {
    await phoneKitchen.updateStatus(
      workspaceId: workspaceId,
      orderLocalId: 'ord-1',
      status: 'delivered',
      permissions: LocalAuthService.adminPermissions,
    );
    expect(await pushStatus(phone, phoneQueue), ['order.updated']);
    expect(await (phone.select(phone.localInvoices)).get(), isEmpty);
    expect(await (windows.select(windows.localInvoices)).get(), isEmpty);
  });
}
