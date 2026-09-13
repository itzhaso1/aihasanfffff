import 'dart:convert';

import 'package:drift/drift.dart';

import '../../local_db/app_database.dart';
import '../../offline/offline_store.dart';
import '../../permissions/staff_permissions.dart';
import '../../repositories/sync_queue_repository.dart';
import '../pos_errors.dart';
import 'kitchen_local_service.dart';

/// Workspace-scoped local wipes for Settings. Never touches users, store,
/// shifts, tables master, printer, or auth. Does not enqueue cloud deletes.
class LocalDataWipeService {
  LocalDataWipeService(this._db, {SyncQueueRepository? queue}) : _queue = queue;

  final AppDatabase _db;
  final SyncQueueRepository? _queue;

  Future<int> deleteAllInvoices({
    required int workspaceId,
    Map<String, dynamic>? permissions,
  }) async {
    _require(permissions, StaffPermissions.invoicesDelete);
    _assertWorkspace(workspaceId);
    await _assertNoUnsyncedBusiness(workspaceId);
    return _db.transaction(() async {
      final invoices = await (_db.select(
        _db.localInvoices,
      )..where((t) => t.workspaceId.equals(workspaceId))).get();
      if (invoices.isEmpty) return 0;
      final ids = [for (final row in invoices) row.localId];
      await (_db.delete(
        _db.localPayments,
      )..where((t) => t.invoiceLocalId.isIn(ids))).go();
      final returns = await (_db.select(
        _db.localReturns,
      )..where((t) => t.invoiceLocalId.isIn(ids))).get();
      final returnIds = [for (final row in returns) row.localId];
      if (returnIds.isNotEmpty) {
        await (_db.delete(
          _db.localReturnItems,
        )..where((t) => t.returnLocalId.isIn(returnIds))).go();
      }
      await (_db.delete(
        _db.localReturns,
      )..where((t) => t.invoiceLocalId.isIn(ids))).go();
      await (_db.delete(
        _db.localInvoices,
      )..where((t) => t.workspaceId.equals(workspaceId))).go();
      await _cancelQueue(
        workspaceId: workspaceId,
        entityTypes: const ['invoice'],
        entityIds: ids,
      );
      return invoices.length;
    });
  }

  Future<int> deleteAllProducts({
    required int workspaceId,
    Map<String, dynamic>? permissions,
  }) async {
    _require(permissions, StaffPermissions.menuManage);
    _assertWorkspace(workspaceId);
    await _assertNoUnsyncedBusiness(workspaceId);
    final count = await _db.transaction(() async {
      await (_db.delete(
        _db.localDraftCartLines,
      )..where((t) => t.workspaceId.equals(workspaceId))).go();
      await (_db.delete(
        _db.localDraftCarts,
      )..where((t) => t.workspaceId.equals(workspaceId))).go();
      final now = DateTime.now();
      final products =
          await (_db.select(_db.localProducts)..where(
                (t) =>
                    t.workspaceId.equals(workspaceId) &
                    t.isDeleted.equals(false),
              ))
              .get();
      await (_db.update(
        _db.localProducts,
      )..where((t) => t.workspaceId.equals(workspaceId))).write(
        LocalProductsCompanion(
          isDeleted: const Value(true),
          isActive: const Value(false),
          updatedAt: Value(now),
        ),
      );
      await (_db.update(
        _db.localCategories,
      )..where((t) => t.workspaceId.equals(workspaceId))).write(
        LocalCategoriesCompanion(
          isDeleted: const Value(true),
          isActive: const Value(false),
          updatedAt: Value(now),
        ),
      );
      await _cancelQueue(
        workspaceId: workspaceId,
        entityTypes: const ['product', 'category'],
      );
      return products.length;
    });
    try {
      // Hive cache is optional; never block the local SQLite wipe on it.
      // ignore: unawaited_futures
      OfflineStore.instance.clearCatalogCache(workspaceId: workspaceId);
    } catch (_) {}
    return count;
  }

  Future<int> deleteAllOrders({
    required int workspaceId,
    Map<String, dynamic>? permissions,
  }) async {
    _require(permissions, StaffPermissions.ordersManage);
    _assertWorkspace(workspaceId);
    await _assertNoUnsyncedBusiness(workspaceId);
    return _deleteOrders(workspaceId: workspaceId);
  }

  Future<int> deleteAllKitchenTickets({
    required int workspaceId,
    Map<String, dynamic>? permissions,
  }) async {
    if (!StaffPermissions.can(permissions, StaffPermissions.kitchenUse) &&
        !StaffPermissions.can(permissions, StaffPermissions.ordersManage)) {
      throw const Forbidden();
    }
    _assertWorkspace(workspaceId);
    await _assertNoUnsyncedBusiness(workspaceId);
    return _deleteOrders(
      workspaceId: workspaceId,
      posStatuses: KitchenLocalService.kitchenStatuses.toList(),
    );
  }

  Future<int> _deleteOrders({
    required int workspaceId,
    List<String>? posStatuses,
  }) async {
    return _db.transaction(() async {
      final query = _db.select(_db.localOrders)
        ..where((t) => t.workspaceId.equals(workspaceId));
      if (posStatuses != null) {
        query.where((t) => t.posStatus.isIn(posStatuses));
      }
      final orders = await query.get();
      if (orders.isEmpty) {
        await _releaseEmptyTableSessions(workspaceId);
        return 0;
      }
      final orderIds = [for (final row in orders) row.localId];
      final items = await (_db.select(
        _db.localOrderItems,
      )..where((t) => t.orderLocalId.isIn(orderIds))).get();
      final itemIds = [for (final row in items) row.localId];
      if (itemIds.isNotEmpty) {
        await (_db.delete(
          _db.localReturnItems,
        )..where((t) => t.orderItemLocalId.isIn(itemIds))).go();
      }
      final returns = await (_db.select(
        _db.localReturns,
      )..where((t) => t.orderLocalId.isIn(orderIds))).get();
      final orphanReturnIds = [
        for (final row in returns)
          if (!_hasId(row.invoiceLocalId)) row.localId,
      ];
      if (orphanReturnIds.isNotEmpty) {
        await (_db.delete(
          _db.localReturnItems,
        )..where((t) => t.returnLocalId.isIn(orphanReturnIds))).go();
        await (_db.delete(
          _db.localReturns,
        )..where((t) => t.localId.isIn(orphanReturnIds))).go();
      }
      await (_db.update(_db.localReturns)
            ..where((t) => t.orderLocalId.isIn(orderIds)))
          .write(const LocalReturnsCompanion(orderLocalId: Value(null)));
      final payments = await (_db.select(
        _db.localPayments,
      )..where((t) => t.orderLocalId.isIn(orderIds))).get();
      final orphanPaymentIds = [
        for (final row in payments)
          if (!_hasId(row.invoiceLocalId)) row.localId,
      ];
      if (orphanPaymentIds.isNotEmpty) {
        await (_db.delete(
          _db.localPayments,
        )..where((t) => t.localId.isIn(orphanPaymentIds))).go();
      }
      await (_db.update(_db.localPayments)
            ..where((t) => t.orderLocalId.isIn(orderIds)))
          .write(const LocalPaymentsCompanion(orderLocalId: Value(null)));
      await (_db.update(_db.localInvoices)
            ..where((t) => t.orderLocalId.isIn(orderIds)))
          .write(const LocalInvoicesCompanion(orderLocalId: Value(null)));
      await (_db.delete(
        _db.localOrderItems,
      )..where((t) => t.orderLocalId.isIn(orderIds))).go();
      await (_db.delete(
        _db.localOrders,
      )..where((t) => t.localId.isIn(orderIds))).go();
      await _cancelQueue(
        workspaceId: workspaceId,
        entityTypes: const ['order'],
        entityIds: orderIds,
      );
      await _releaseEmptyTableSessions(workspaceId);
      return orders.length;
    });
  }

  Future<void> _releaseEmptyTableSessions(int workspaceId) async {
    final now = DateTime.now();
    final openSessions =
        await (_db.select(_db.localSessions)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) & t.status.equals('open'),
            ))
            .get();
    for (final session in openSessions) {
      final leftover =
          await (_db.select(_db.localOrders)..where(
                (t) =>
                    t.workspaceId.equals(workspaceId) &
                    t.sessionLocalId.equals(session.localId),
              ))
              .get();
      if (leftover.isNotEmpty) continue;
      await (_db.update(
        _db.localSessions,
      )..where((t) => t.localId.equals(session.localId))).write(
        LocalSessionsCompanion(
          status: const Value('closed'),
          closedAt: Value(now),
          updatedAt: Value(now),
        ),
      );
      await _clearTableOccupation(session.tableLocalId, now);
    }
    final occupied =
        await (_db.select(_db.localTables)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.status.equals('occupied'),
            ))
            .get();
    for (final table in occupied) {
      final leftover =
          await (_db.select(_db.localOrders)..where(
                (t) =>
                    t.workspaceId.equals(workspaceId) &
                    t.tableLocalId.equals(table.localId) &
                    t.posStatus.isNotValue('cancelled') &
                    t.posStatus.isNotValue('completed') &
                    t.paymentStatus.isNotValue('paid'),
              ))
              .get();
      if (leftover.isEmpty) {
        await _clearTableOccupation(table.localId, now);
      }
    }
  }

  Future<void> _clearTableOccupation(String tableLocalId, DateTime now) async {
    final row = await (_db.select(
      _db.localTables,
    )..where((t) => t.localId.equals(tableLocalId))).getSingleOrNull();
    if (row == null) return;
    final payload = <String, dynamic>{};
    if (row.payloadJson.isNotEmpty) {
      try {
        final decoded = jsonDecode(row.payloadJson);
        if (decoded is Map) {
          payload.addAll(Map<String, dynamic>.from(decoded));
        }
      } catch (_) {}
    }
    payload['status'] = 'available';
    payload['session_open'] = false;
    payload['session_id'] = null;
    payload['session_client_id'] = null;
    payload['opened_at'] = null;
    await (_db.update(
      _db.localTables,
    )..where((t) => t.localId.equals(tableLocalId))).write(
      LocalTablesCompanion(
        status: const Value('available'),
        payloadJson: Value(jsonEncode(payload)),
        updatedAt: Value(now),
      ),
    );
  }

  Future<void> _cancelQueue({
    required int workspaceId,
    required Iterable<String> entityTypes,
    Iterable<String>? entityIds,
  }) async {
    final queue = _queue;
    if (queue == null) return;
    await queue.cancelOpenOps(
      workspaceId: workspaceId,
      entityTypes: entityTypes,
      entityIds: entityIds,
    );
  }

  void _require(Map<String, dynamic>? permissions, String key) {
    if (!StaffPermissions.can(permissions, key)) {
      throw const Forbidden();
    }
  }

  bool _hasId(String? value) => value != null && value.trim().isNotEmpty;

  Future<void> _assertNoUnsyncedBusiness(int workspaceId) async {
    final queue = _queue;
    if (queue != null) {
      final rows = await queue.pendingForWorkspace(workspaceId);
      const business = {
        'order',
        'invoice',
        'table_session',
        'stock',
        'stock_movement',
        'customer',
        'return',
        'refund',
      };
      final blocking = rows.where(
        (row) =>
            business.contains(row.entityType) &&
            (row.status == 'pending' ||
                row.status == 'failed' ||
                row.status == 'syncing'),
      );
      if (blocking.isNotEmpty) {
        throw const UnsyncedWipeBlocked();
      }
    }

    final unsyncedInvoices =
        await (_db.select(_db.localInvoices)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.serverId.isNull() &
                  t.syncStatus.isNotValue('synced'),
            ))
            .get();
    if (unsyncedInvoices.isNotEmpty) {
      throw const UnsyncedWipeBlocked();
    }

    final unsyncedOrders =
        await (_db.select(_db.localOrders)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.serverId.isNull() &
                  t.syncStatus.isIn(['pending', 'failed', 'syncing']),
            ))
            .get();
    if (unsyncedOrders.isNotEmpty) {
      throw const UnsyncedWipeBlocked();
    }

    final openSessions =
        await (_db.select(_db.localSessions)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) & t.status.equals('open'),
            ))
            .get();
    if (openSessions.isNotEmpty && queue != null) {
      final rows = await queue.pendingForWorkspace(workspaceId);
      final sessionPending = rows.any(
        (row) =>
            row.entityType == 'table_session' &&
            (row.status == 'pending' ||
                row.status == 'failed' ||
                row.status == 'syncing'),
      );
      if (sessionPending) {
        throw const UnsyncedWipeBlocked();
      }
    }
  }

  void _assertWorkspace(int workspaceId) {
    if (workspaceId <= 0) {
      throw const DatabaseFailure('لا توجد مساحة عمل محلية.');
    }
  }
}
