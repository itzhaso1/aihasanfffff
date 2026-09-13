import 'package:drift/drift.dart';

import '../../local_db/app_database.dart';
import '../../repositories/sync_queue_repository.dart';
import '../pos_mode.dart';
import '../pos_permissions.dart';

class KitchenBoardSnapshot {
  const KitchenBoardSnapshot({
    this.active = const [],
    this.delivered = const [],
    this.cancelled = const [],
  });

  final List<Map<String, dynamic>> active;
  final List<Map<String, dynamic>> delivered;
  final List<Map<String, dynamic>> cancelled;
}

/// Kitchen board reads/writes SQLite. Network is optional enrichment only.
class KitchenLocalService {
  KitchenLocalService(
    this._db, {
    SyncQueueRepository? queue,
    Future<String> Function()? deviceId,
  })  : _queue = queue,
        _deviceId = deviceId;

  final AppDatabase _db;
  final SyncQueueRepository? _queue;
  final Future<String> Function()? _deviceId;

  /// Visible on the live kitchen board. `accepted` stays here because it is
  /// an existing prep state, not history.
  static const activeStatuses = ['new', 'accepted', 'preparing', 'ready'];

  static const deliveredHistoryStatuses = ['delivered', 'completed'];

  static const cancelledStatuses = ['cancelled'];

  static const kitchenStatuses = {
    'new',
    'accepted',
    'preparing',
    'ready',
    'delivered',
    'completed',
    'cancelled',
  };

  Stream<List<Map<String, dynamic>>> watchActive(int workspaceId) {
    return watchBoard(workspaceId).map((board) => board.active);
  }

  Stream<KitchenBoardSnapshot> watchBoard(int workspaceId) {
    final query = _db.select(_db.localOrders)
      ..where(
        (t) =>
            t.workspaceId.equals(workspaceId) &
            t.posStatus.isIn([
              ...activeStatuses,
              ...deliveredHistoryStatuses,
              ...cancelledStatuses,
            ]),
      )
      ..orderBy([(t) => OrderingTerm.desc(t.updatedAt)]);
    return query.watch().asyncMap((rows) => _toBoard(rows));
  }

  Future<KitchenBoardSnapshot> _toBoard(List<LocalOrder> rows) async {
    final tableIds = {
      for (final row in rows)
        if (row.tableLocalId != null && row.tableLocalId!.trim().isNotEmpty)
          row.tableLocalId!,
    };
    final tablesById = <String, LocalTable>{};
    if (tableIds.isNotEmpty) {
      final found = await (_db.select(
        _db.localTables,
      )..where((t) => t.localId.isIn(tableIds))).get();
      for (final table in found) {
        tablesById[table.localId] = table;
      }
    }
    final active = <Map<String, dynamic>>[];
    final delivered = <Map<String, dynamic>>[];
    final cancelled = <Map<String, dynamic>>[];
    for (final row in rows) {
      final mapped = await _mapTicket(row, tablesById[row.tableLocalId]);
      final status = row.posStatus.trim().toLowerCase();
      if (activeStatuses.contains(status)) {
        active.add(mapped);
      } else if (cancelledStatuses.contains(status)) {
        cancelled.add(mapped);
      } else if (deliveredHistoryStatuses.contains(status)) {
        delivered.add(mapped);
      }
    }
    return KitchenBoardSnapshot(
      active: active,
      delivered: delivered,
      cancelled: cancelled,
    );
  }

  Future<Map<String, dynamic>> _mapTicket(
    LocalOrder row,
    LocalTable? table,
  ) async {
    final items = await (_db.select(_db.localOrderItems)..where(
          (t) =>
              t.orderLocalId.equals(row.localId) & t.isRemoved.equals(false),
        ))
        .get();
    return {
      'id': row.serverId ?? row.localId,
      'local_id': row.localId,
      'client_reference': row.clientReference,
      'server_id': row.serverId,
      'order_number': row.orderNumber ?? row.localId,
      'order_type': row.orderType,
      'pos_status': row.posStatus,
      'payment_status': row.paymentStatus,
      'notes': row.notes,
      'created_at': row.createdAt.toIso8601String(),
      'table_local_id': row.tableLocalId,
      'session_local_id': row.sessionLocalId,
      if (table != null)
        'table': {
          'id': table.serverId ?? table.localId,
          'local_id': table.localId,
          'name': table.name,
        },
      'items': [
        for (final item in items)
          {
            'id': item.localId,
            'item_name': item.name,
            'product_name': item.name,
            'name': item.name,
            'quantity': item.quantity,
            'notes': item.notes,
          },
      ],
    };
  }

  Future<void> updateStatus({
    required int workspaceId,
    required String orderLocalId,
    required String status,
    Map<String, dynamic>? permissions,
    String? deviceId,
  }) async {
    PosPermissions.require(permissions, PosPermissions.kitchen);
    final next = status.trim().toLowerCase();
    if (!kitchenStatuses.contains(next)) {
      throw ArgumentError('unsupported kitchen status: $status');
    }
    final order = await (_db.select(_db.localOrders)..where(
          (t) =>
              t.localId.equals(orderLocalId) &
              t.workspaceId.equals(workspaceId),
        ))
        .getSingleOrNull();
    if (order == null) return;

    await (_db.update(_db.localOrders)..where(
          (t) =>
              t.localId.equals(order.localId) &
              t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalOrdersCompanion(
            posStatus: Value(next),
            fulfillmentStatus: Value(
              next == 'completed' || next == 'delivered'
                  ? 'fulfilled'
                  : next == 'cancelled'
                      ? 'cancelled'
                      : 'unfulfilled',
            ),
            updatedAt: Value(DateTime.now()),
            completedAt: next == 'completed' || next == 'delivered'
                ? Value(DateTime.now())
                : const Value.absent(),
          ),
        );
    await _enqueueStatus(
      workspaceId: workspaceId,
      order: order,
      status: next,
      deviceId: deviceId,
    );
  }

  Future<void> _enqueueStatus({
    required int workspaceId,
    required LocalOrder order,
    required String status,
    String? deviceId,
  }) async {
    final queue = _queue;
    if (queue == null) return;
    if (PosMode.isReservedStandaloneWorkspace(workspaceId)) return;
    final resolvedDevice = (deviceId ??
            (await _deviceId?.call()) ??
            order.deviceId)
        .trim();
    if (resolvedDevice.isEmpty) return;

    final payload = <String, dynamic>{
      'kitchen_status': true,
      'pos_status': status,
      'client_reference': order.clientReference,
      'order_type': order.orderType,
      if (order.serverId != null && order.serverId! > 0) ...{
        'order_server_id': order.serverId,
        'server_order_id': order.serverId,
        'id': order.serverId,
      },
    };

    final updatedOpen = await queue.updateOpenPayload(
      workspaceId: workspaceId,
      entityType: 'order',
      entityId: order.localId,
      operation: 'update',
      payload: payload,
    );
    if (updatedOpen) return;

    await queue.enqueue(
      workspaceId: workspaceId,
      deviceId: resolvedDevice,
      entityType: 'order',
      entityId: order.localId,
      operation: 'update',
      payload: payload,
      clientReference: order.clientReference,
    );
  }
}
