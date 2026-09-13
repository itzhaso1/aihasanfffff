import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../local_db/app_database.dart';

/// Durable outbox for local→server operations. Never delete on failure
/// of a push attempt; only cancel when the local entity itself is deleted
/// before it ever reached the server.
class SyncQueueRepository {
  SyncQueueRepository(this._db, {String Function()? newOperationUuid})
      : _newOperationUuid = newOperationUuid ?? (() => const Uuid().v4());

  final AppDatabase _db;
  final String Function() _newOperationUuid;

  /// 2s, 5s, 15s, 30s, then 60/120/300s cap.
  static const backoffScheduleSeconds = [2, 5, 15, 30, 60, 120, 300];

  static int backoffSecondsForAttempt(int attempts) {
    if (attempts <= 0) return backoffScheduleSeconds.first;
    final index = (attempts - 1).clamp(0, backoffScheduleSeconds.length - 1);
    return backoffScheduleSeconds[index];
  }

  Future<int> enqueue({
    required int workspaceId,
    required String deviceId,
    required String entityType,
    required String entityId,
    required String operation,
    required Map<String, dynamic> payload,
    required String clientReference,
    String? operationUuid,
  }) async {
    if (workspaceId <= 0) {
      throw ArgumentError('workspaceId required');
    }
    if (clientReference.trim().isEmpty) {
      throw ArgumentError('clientReference required');
    }
    final now = DateTime.now();
    return _db.into(_db.syncQueueItems).insert(
          SyncQueueItemsCompanion.insert(
            workspaceId: workspaceId,
            deviceId: deviceId,
            entityType: entityType,
            entityId: entityId,
            operation: operation,
            payloadJson: jsonEncode(payload),
            clientReference: clientReference.trim(),
            operationUuid: Value(operationUuid ?? _newOperationUuid()),
            status: const Value('pending'),
            createdAt: now,
            updatedAt: now,
          ),
        );
  }

  Future<List<SyncQueueItem>> pendingForWorkspace(int workspaceId) {
    return (_db.select(_db.syncQueueItems)
          ..where((t) =>
              t.workspaceId.equals(workspaceId) &
              (t.status.equals('pending') |
                  t.status.equals('failed') |
                  t.status.equals('syncing')))
          ..orderBy([(t) => OrderingTerm.asc(t.createdAt)]))
        .get();
  }

  Future<int> pendingCount(int workspaceId) async {
    final rows = await pendingForWorkspace(workspaceId);
    return rows
        .where((r) => r.status == 'pending' || r.status == 'failed')
        .length;
  }

  Future<int> failedCount(int workspaceId) async {
    final rows = await pendingForWorkspace(workspaceId);
    return rows.where((r) => r.status == 'failed').length;
  }

  Future<SyncQueueCounts> counts(int workspaceId) async {
    final rows = await pendingForWorkspace(workspaceId);
    var pending = 0;
    var failed = 0;
    var syncing = 0;
    for (final row in rows) {
      switch (row.status) {
        case 'failed':
          failed++;
        case 'syncing':
          syncing++;
        default:
          pending++;
      }
    }
    return SyncQueueCounts(pending: pending, failed: failed, syncing: syncing);
  }

  Future<SyncQueueItem?> findOpenOp({
    required int workspaceId,
    required String entityType,
    required String entityId,
    required String operation,
  }) {
    return (_db.select(_db.syncQueueItems)
          ..where((t) =>
              t.workspaceId.equals(workspaceId) &
              t.entityType.equals(entityType) &
              t.entityId.equals(entityId) &
              t.operation.equals(operation) &
              (t.status.equals('pending') |
                  t.status.equals('failed') |
                  t.status.equals('syncing')))
          ..orderBy([(t) => OrderingTerm.desc(t.createdAt)])
          ..limit(1))
        .getSingleOrNull();
  }

  /// Refresh create payload before first successful push (same client_reference).
  Future<bool> updateOpenPayload({
    required int workspaceId,
    required String entityType,
    required String entityId,
    required String operation,
    required Map<String, dynamic> payload,
  }) async {
    final row = await findOpenOp(
      workspaceId: workspaceId,
      entityType: entityType,
      entityId: entityId,
      operation: operation,
    );
    if (row == null) return false;
    if (row.status == 'syncing') return false;
    await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(row.id)))
        .write(
      SyncQueueItemsCompanion(
        payloadJson: Value(jsonEncode(payload)),
        status: const Value('pending'),
        lastError: const Value(null),
        updatedAt: Value(DateTime.now()),
      ),
    );
    return true;
  }

  /// Cancel open outbox rows for wiped local entities so push cannot revive them.
  Future<int> cancelOpenOps({
    required int workspaceId,
    required Iterable<String> entityTypes,
    Iterable<String>? entityIds,
  }) async {
    final types = [
      for (final type in entityTypes)
        if (type.trim().isNotEmpty) type.trim(),
    ];
    if (types.isEmpty) return 0;
    final query = _db.select(_db.syncQueueItems)
      ..where(
        (t) =>
            t.workspaceId.equals(workspaceId) &
            t.entityType.isIn(types) &
            (t.status.equals('pending') |
                t.status.equals('failed') |
                t.status.equals('syncing')),
      );
    final ids = entityIds?.toList();
    if (ids != null) {
      if (ids.isEmpty) return 0;
      query.where((t) => t.entityId.isIn(ids));
    }
    final rows = await query.get();
    if (rows.isEmpty) return 0;
    await (_db.update(_db.syncQueueItems)
          ..where((t) => t.id.isIn([for (final row in rows) row.id])))
        .write(
          SyncQueueItemsCompanion(
            status: const Value('cancelled'),
            updatedAt: Value(DateTime.now()),
          ),
        );
    return rows.length;
  }

  /// Cancel a not-yet-synced op when the local entity is deleted offline.
  Future<bool> cancelOpenOp({
    required int workspaceId,
    required String entityType,
    required String entityId,
    required String operation,
  }) async {
    final row = await findOpenOp(
      workspaceId: workspaceId,
      entityType: entityType,
      entityId: entityId,
      operation: operation,
    );
    if (row == null) return false;
    if (row.status == 'syncing') return false;
    await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(row.id)))
        .write(
      SyncQueueItemsCompanion(
        status: const Value('cancelled'),
        updatedAt: Value(DateTime.now()),
      ),
    );
    return true;
  }

  Future<List<Map<String, dynamic>>> recentForPanel({
    required int workspaceId,
    int limit = 80,
  }) async {
    return (_db.select(_db.syncQueueItems)
          ..where((t) => t.workspaceId.equals(workspaceId))
          ..orderBy([(t) => OrderingTerm.desc(t.updatedAt)])
          ..limit(limit))
        .get()
        .then(
          (rows) => [
            for (final row in rows)
              {
                'id': row.id,
                'local_id': row.entityId,
                'entity_type': row.entityType,
                'operation': row.operation,
                'status': row.status,
                'client_reference': row.clientReference,
                'operation_uuid': row.operationUuid,
                'attempts': row.attempts,
                'last_error': row.lastError,
                'created_at': row.createdAt.toIso8601String(),
                'updated_at': row.updatedAt.toIso8601String(),
                'payload': () {
                  try {
                    final decoded = jsonDecode(row.payloadJson);
                    return decoded is Map
                        ? Map<String, dynamic>.from(decoded)
                        : <String, dynamic>{};
                  } catch (_) {
                    return <String, dynamic>{};
                  }
                }(),
              },
          ],
        );
  }

  Future<void> markSyncing(int id) async {
    await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(id))).write(
      SyncQueueItemsCompanion(
        status: const Value('syncing'),
        updatedAt: Value(DateTime.now()),
      ),
    );
  }

  Future<void> markSynced(int id) async {
    final now = DateTime.now();
    await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(id))).write(
      SyncQueueItemsCompanion(
        status: const Value('synced'),
        lastError: const Value(null),
        syncedAt: Value(now),
        updatedAt: Value(now),
      ),
    );
  }

  Future<void> markFailed(int id, String error, {bool retryable = true}) async {
    final row = await (_db.select(_db.syncQueueItems)
          ..where((t) => t.id.equals(id)))
        .getSingleOrNull();
    final attempts = (row?.attempts ?? 0) + 1;
    final delaySeconds = backoffSecondsForAttempt(attempts);
    await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(id))).write(
      SyncQueueItemsCompanion(
        status: Value(retryable ? 'pending' : 'failed'),
        attempts: Value(attempts),
        lastError: Value(error),
        nextAttemptAt: Value(
          DateTime.now().add(Duration(seconds: delaySeconds)),
        ),
        updatedAt: Value(DateTime.now()),
      ),
    );
  }

  Future<void> requeueFailed(int id) async {
    await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(id))).write(
      SyncQueueItemsCompanion(
        status: const Value('pending'),
        nextAttemptAt: const Value(null),
        updatedAt: Value(DateTime.now()),
      ),
    );
  }

  Future<void> recoverStuckSyncing(int workspaceId) async {
    final stuck = await (_db.select(_db.syncQueueItems)
          ..where((t) =>
              t.workspaceId.equals(workspaceId) & t.status.equals('syncing')))
        .get();
    final now = DateTime.now();
    for (final row in stuck) {
      await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(row.id)))
          .write(
        SyncQueueItemsCompanion(
          status: const Value('pending'),
          updatedAt: Value(now),
        ),
      );
    }
  }

  /// Settings → مزامنة الآن retries immediately instead of waiting backoff.
  Future<int> clearPendingBackoff(int workspaceId) {
    return (_db.update(_db.syncQueueItems)
          ..where(
            (t) =>
                t.workspaceId.equals(workspaceId) &
                t.status.isIn(['pending', 'syncing']),
          ))
        .write(
          SyncQueueItemsCompanion(
            nextAttemptAt: const Value(null),
            updatedAt: Value(DateTime.now()),
          ),
        );
  }

  static bool isInContract(SyncQueueItem row) {
    if (row.entityType == 'customer' && row.operation == 'create') return true;
    if (row.entityType == 'order' && row.operation == 'create') return true;
    if (row.entityType == 'invoice' && row.operation == 'create') return true;
    if (row.entityType == 'category' || row.entityType == 'product') {
      return row.operation == 'create' ||
          row.operation == 'update' ||
          row.operation == 'delete';
    }
    if (row.entityType == 'table') {
      return row.operation == 'create' ||
          row.operation == 'update' ||
          row.operation == 'delete';
    }
    if (row.entityType == 'table_session') {
      return row.operation == 'open' ||
          row.operation == 'close' ||
          row.operation == 'cancel' ||
          row.operation == 'note' ||
          row.operation == 'discount' ||
          row.operation == 'transfer' ||
          row.operation == 'merge' ||
          row.operation == 'split';
    }
    if (row.entityType == 'stock' || row.entityType == 'stock_movement') {
      Map<String, dynamic> payload = const {};
      try {
        final decoded = jsonDecode(row.payloadJson);
        if (decoded is Map) payload = Map<String, dynamic>.from(decoded);
      } catch (_) {}
      final kind =
          '${payload['kind'] ?? payload['type'] ?? ''}'.trim().toLowerCase();
      return kind.isNotEmpty && kind != 'sale' && kind != 'remove';
    }
    return false;
  }

  Future<int> requeueInContractFailed(int workspaceId) async {
    final rows = await pendingForWorkspace(workspaceId);
    var n = 0;
    for (final row in rows) {
      if (row.status != 'failed') continue;
      if (!isInContract(row)) continue;
      await requeueFailed(row.id);
      n++;
    }
    return n;
  }
}

class SyncQueueCounts {
  const SyncQueueCounts({
    this.pending = 0,
    this.failed = 0,
    this.syncing = 0,
  });

  final int pending;
  final int failed;
  final int syncing;

  int get waiting => pending + failed + syncing;
}
