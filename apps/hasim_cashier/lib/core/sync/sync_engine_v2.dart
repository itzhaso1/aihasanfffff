import '../util/json_numbers.dart';
import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../api/cashier_api.dart';
import '../local_db/app_database.dart';
import '../pos/domain/pricing_service.dart';
import '../local_db/workspace_scope.dart';
import '../pos/pos_mode.dart';
import '../repositories/sync_queue_repository.dart';
import '../repositories/sync_conflict_repository.dart';
import 'sync_pull_applier.dart';
import 'sync_queue_classifier.dart';

/// Sync Engine v2 — push SQLite sync_queue, then pull incremental changes.
/// Primary POS sync path (Hive outbox is legacy migration only).
class SyncEngineV2 {
  SyncEngineV2(
    this._db,
    this._queue, {
    CashierApiClient? api,
    SyncPullApplier? pullApplier,
    Future<Map<String, dynamic>> Function(
      Map<String, dynamic> payload,
      String idempotencyKey,
    )?
    postOrder,
    Future<Map<String, dynamic>> Function(
      int serverOrderId,
      Map<String, dynamic> payload,
    )?
    postOrderItems,
    Future<void> Function(int serverOrderId)? deleteOrder,
    Future<Map<String, dynamic>> Function(int since, int limit)? fetchChanges,
    Future<Map<String, dynamic>> Function(
      int tableServerId,
      String idempotencyKey,
    )?
    postSessionOpen,
    Future<Map<String, dynamic>> Function(
      int tableServerId,
      int sessionServerId,
      Map<String, dynamic> payload,
      String idempotencyKey,
    )?
    postSessionClose,
    Future<Map<String, dynamic>> Function(
      int orderServerId,
      String idempotencyKey,
    )?
    postInvoice,
    Future<Map<String, dynamic>> Function(int tableServerId)? getTable,
    Future<Map<String, dynamic>> Function(Map<String, dynamic> body)?
    postPushBatch,
    Future<Map<String, dynamic>> Function(Map<String, dynamic> body)? postPull,
  }) : _api = api,
       _pullApplier = pullApplier ?? SyncPullApplier(_db),
       _postOrder = postOrder,
       _postOrderItems = postOrderItems,
       _deleteOrder = deleteOrder,
       _fetchChanges = fetchChanges,
       _postSessionOpen = postSessionOpen,
       _postSessionClose = postSessionClose,
       _postInvoice = postInvoice,
       _getTable = getTable,
       _postPushBatch = postPushBatch,
       _postPull = postPull;

  final AppDatabase _db;
  final SyncQueueRepository _queue;
  final CashierApiClient? _api;
  final SyncPullApplier _pullApplier;
  final Future<Map<String, dynamic>> Function(
    Map<String, dynamic> payload,
    String idempotencyKey,
  )?
  _postOrder;
  final Future<Map<String, dynamic>> Function(
    int serverOrderId,
    Map<String, dynamic> payload,
  )?
  _postOrderItems;
  final Future<void> Function(int serverOrderId)? _deleteOrder;
  final Future<Map<String, dynamic>> Function(int since, int limit)?
  _fetchChanges;
  final Future<Map<String, dynamic>> Function(
    int tableServerId,
    String idempotencyKey,
  )?
  _postSessionOpen;
  final Future<Map<String, dynamic>> Function(
    int tableServerId,
    int sessionServerId,
    Map<String, dynamic> payload,
    String idempotencyKey,
  )?
  _postSessionClose;
  final Future<Map<String, dynamic>> Function(
    int orderServerId,
    String idempotencyKey,
  )?
  _postInvoice;
  final Future<Map<String, dynamic>> Function(int tableServerId)? _getTable;
  final Future<Map<String, dynamic>> Function(Map<String, dynamic> body)?
  _postPushBatch;
  final Future<Map<String, dynamic>> Function(Map<String, dynamic> body)?
  _postPull;

  var _flushing = false;
  bool get isFlushing => _flushing;

  /// Push pending local ops, then pull server deltas. Pull failure never
  /// drops the local sync_queue or pending orders. A second push after pull
  /// drains ops that were enqueued while the first cycle was already running
  /// (first invoice / first table close after startup).
  Future<SyncEngineV2Result> syncBidirectional({
    required int workspaceId,
    String? deviceId,
  }) async {
    final push = await pushPending(workspaceId: workspaceId);
    try {
      final pull = await pullChanges(
        workspaceId: workspaceId,
        deviceId: deviceId,
      );
      final catchUp = await pushPending(workspaceId: workspaceId);
      return SyncEngineV2Result(
        synced: push.synced + catchUp.synced,
        failed: push.failed + catchUp.failed,
        keptPending: catchUp.keptPending,
        authRequired: push.authRequired || pull.authRequired || catchUp.authRequired,
        skippedInFlight: push.skippedInFlight && catchUp.skippedInFlight,
        pulled: pull.pulled,
        cursor: pull.cursor,
        pullFailed: pull.pullFailed,
      );
    } on ApiException catch (e) {
      // Keep pending local ops + existing cursor intact.
      return SyncEngineV2Result(
        synced: push.synced,
        failed: push.failed,
        keptPending: push.keptPending,
        authRequired: push.authRequired || e.isUnauthorized,
        skippedInFlight: push.skippedInFlight,
        pullFailed: true,
        networkError: e.isNetwork || e.isUnavailable,
        pullError: e.message,
      );
    } catch (e) {
      // Keep pending local ops + existing cursor intact.
      return SyncEngineV2Result(
        synced: push.synced,
        failed: push.failed,
        keptPending: push.keptPending,
        authRequired: push.authRequired,
        skippedInFlight: push.skippedInFlight,
        pullFailed: true,
        pullError: e.toString(),
      );
    }
  }

  /// After full Initial Sync snapshot, anchor cursor to server head without
  /// re-applying historical create events.
  Future<int> anchorCursorToServerHead({
    required int workspaceId,
    String? deviceId,
  }) async {
    final data = await _loadChanges(since: 0, limit: 0, deviceId: deviceId);
    final serverCursor =
        (data['server_cursor'] as num?)?.toInt() ??
        (data['cursor'] as num?)?.toInt() ??
        0;
    await _db.writeCursor(workspaceId, '$serverCursor', deviceId: deviceId);
    return serverCursor;
  }

  Future<SyncEngineV2Result> pullChanges({
    required int workspaceId,
    String? deviceId,
    int pageLimit = 200,
  }) async {
    if (workspaceId <= 0) return const SyncEngineV2Result();
    final rawCursor = await _db.readCursor(workspaceId);
    var since = int.tryParse(rawCursor ?? '') ?? 0;
    var pulled = 0;
    var cursor = since;
    var hasMore = true;
    while (hasMore) {
      final data = await _loadChanges(
        since: since,
        limit: pageLimit,
        deviceId: deviceId,
      );
      final changes = <Map<String, dynamic>>[];
      if (data['changes'] is List) {
        for (final item in data['changes'] as List) {
          if (item is Map) changes.add(Map<String, dynamic>.from(item));
        }
      }
      final responseCursor = (data['cursor'] as num?)?.toInt() ?? since;
      cursor = await _pullApplier.applyBatch(
        workspaceId: workspaceId,
        fromCursor: since,
        responseCursor: responseCursor,
        changes: changes,
        deviceId: deviceId,
      );
      pulled += changes.length;
      hasMore = data['has_more'] == true;
      since = cursor;
      if (changes.isEmpty) break;
    }
    await _db.writeMeta(
      workspaceId,
      SyncMetaKeys.lastPullAt,
      DateTime.now().toIso8601String(),
      deviceId: deviceId,
    );
    return SyncEngineV2Result(pulled: pulled, cursor: cursor);
  }

  Future<SyncEngineV2Result> pushPending({required int workspaceId}) async {
    if (_flushing) {
      return const SyncEngineV2Result(skippedInFlight: true);
    }
    if (workspaceId <= 0) {
      return const SyncEngineV2Result();
    }
    _flushing = true;
    var synced = 0;
    var failed = 0;
    var kept = 0;
    try {
      await _queue.recoverStuckSyncing(workspaceId);
      await _requeueSessionsSentBeforeTable(workspaceId);
      await _catchUpUnqueuedSales(workspaceId);
      if (_preferBatchPush) {
        final batch = await _pushPendingBatch(workspaceId);
        if (!batch.fellBackToRow) {
          if (batch.synced > 0 || batch.failed == 0) {
            await _db.writeMeta(
              workspaceId,
              SyncMetaKeys.lastPushAt,
              DateTime.now().toIso8601String(),
            );
          }
          return batch.result;
        }
      }
      final rows = await _queue.pendingForWorkspace(workspaceId);
      final ordered = [...rows]
        ..sort((a, b) {
          final pa = _pushPriority(a);
          final pb = _pushPriority(b);
          if (pa != pb) return pa.compareTo(pb);
          return a.createdAt.compareTo(b.createdAt);
        });
      for (final row in ordered) {
        if (row.workspaceId != workspaceId) continue;
        if (row.status == 'cancelled' || row.status == 'synced') continue;
        if (row.status == 'failed') {
          kept++;
          continue;
        }
        if (row.nextAttemptAt != null &&
            row.nextAttemptAt!.isAfter(DateTime.now())) {
          kept++;
          continue;
        }
        final supported =
            row.entityType == 'order' ||
            row.entityType == 'customer' ||
            row.entityType == 'invoice' ||
            (row.entityType == 'table_session' && _hasRowInjectors);
        if (!supported) {
          kept++;
          continue;
        }

        // Close / takeaway invoice wait until dependent orders are pushed.
        if (row.entityType == 'order' &&
            row.operation == 'create' &&
            !_hasRowInjectors &&
            !await _saleCreateReady(row)) {
          kept++;
          continue;
        }
        if (row.entityType == 'table_session' &&
            !await _localTableHasServerId(row)) {
          kept++;
          continue;
        }
        if (row.entityType == 'table_session' && row.operation == 'close') {
          if (await _hasPendingSessionOpen(row)) {
            kept++;
            continue;
          }
          if (await _closeBlockedByUnsyncedOrders(row)) {
            kept++;
            continue;
          }
        }
        if (row.entityType == 'table_session' &&
            _isSessionAction(row.operation) &&
            !await _sessionActionReady(row)) {
          kept++;
          continue;
        }
        if (row.entityType == 'invoice' && row.operation == 'create') {
          final payload = _decode(row.payloadJson);
          final orderLocalId = '${payload['order_local_id'] ?? ''}';
          if (orderLocalId.isNotEmpty &&
              await _orderNeedsServerId(workspaceId, orderLocalId)) {
            kept++;
            continue;
          }
        }

        await _queue.markSyncing(row.id);
        try {
          if (row.entityType == 'customer') {
            await _pushCustomer(row);
            synced++;
          } else if (row.entityType == 'table_session' &&
              row.operation == 'open') {
            await _pushSessionOpen(row);
            synced++;
          } else if (row.entityType == 'table_session' &&
              row.operation == 'close') {
            await _pushSessionClose(row);
            synced++;
          } else if (row.entityType == 'table_session' &&
              _isSessionAction(row.operation)) {
            await _pushSessionAction(row);
            synced++;
          } else if (row.entityType == 'invoice') {
            await _pushInvoice(row);
            synced++;
          } else if (row.operation == 'create') {
            await _pushCreate(row);
            synced++;
          } else if (row.operation == 'update') {
            await _pushUpdate(row);
            synced++;
          } else if (row.operation == 'delete') {
            await _pushDelete(row);
            synced++;
          } else {
            await _queue.markFailed(
              row.id,
              'عملية مزامنة غير مدعومة: ${row.operation}',
              retryable: false,
            );
            failed++;
          }
        } on ApiException catch (e) {
          if (e.statusCode == 401) {
            await _queue.markFailed(row.id, e.message, retryable: true);
            return SyncEngineV2Result(
              synced: synced,
              failed: failed,
              keptPending: kept + 1,
              authRequired: true,
            );
          }
          if (e.statusCode == 422 || e.statusCode == 403) {
            await _queue.markFailed(row.id, e.message, retryable: false);
            if (row.entityType == 'order') {
              await _markOrderFailed(row.entityId, e.message);
            } else if (row.entityType == 'customer') {
              await _markCustomerFailed(row.entityId, e.message);
            }
            failed++;
          } else {
            await _queue.markFailed(row.id, e.message, retryable: true);
            if (row.entityType == 'order') {
              await _markOrderPending(row.entityId, e.message);
            } else if (row.entityType == 'customer') {
              await _markCustomerPending(row.entityId, e.message);
            }
            kept++;
          }
        } catch (e) {
          await _queue.markFailed(row.id, e.toString(), retryable: true);
          if (row.entityType == 'order') {
            await _markOrderPending(row.entityId, e.toString());
          } else if (row.entityType == 'customer') {
            await _markCustomerPending(row.entityId, e.toString());
          }
          kept++;
        }
      }
      if (synced > 0) {
        await _db.writeMeta(
          workspaceId,
          SyncMetaKeys.lastPushAt,
          DateTime.now().toIso8601String(),
        );
      }
      return SyncEngineV2Result(
        synced: synced,
        failed: failed,
        keptPending: kept,
      );
    } finally {
      _flushing = false;
    }
  }

  bool get _hasRowInjectors =>
      _postOrder != null ||
      _postOrderItems != null ||
      _deleteOrder != null ||
      _postSessionOpen != null ||
      _postSessionClose != null ||
      _postInvoice != null;

  bool get _preferBatchPush =>
      _postPushBatch != null || (_api != null && !_hasRowInjectors);

  Future<String> _operationUuid(SyncQueueItem row) async {
    final existing = row.operationUuid?.trim();
    if (existing != null && existing.isNotEmpty) return existing;
    final uuid = const Uuid().v4();
    await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(row.id)))
        .write(SyncQueueItemsCompanion(operationUuid: Value(uuid)));
    return uuid;
  }

  String _operationType(SyncQueueItem row) {
    if (row.entityType == 'order') {
      return switch (row.operation) {
        'create' => 'order.created',
        'update' => 'order.updated',
        'delete' => 'order.deleted',
        _ => 'order.${row.operation}',
      };
    }
    if (row.entityType == 'customer') return 'customer.created';
    if (row.entityType == 'invoice') return 'invoice.created';
    if (row.entityType == 'category' ||
        row.entityType == 'product' ||
        row.entityType == 'table') {
      return switch (row.operation) {
        'create' => '${row.entityType}.created',
        'update' => '${row.entityType}.updated',
        'delete' => '${row.entityType}.deleted',
        _ => '${row.entityType}.${row.operation}',
      };
    }
    if (row.entityType == 'table_session') {
      return 'table_session.${row.operation}';
    }
    if (row.entityType == 'stock' || row.entityType == 'stock_movement') {
      return 'stock.movement';
    }
    return '${row.entityType}.${row.operation}';
  }

  Future<
    ({SyncEngineV2Result result, bool fellBackToRow, int synced, int failed})
  >
  _pushPendingBatch(int workspaceId) async {
    var synced = 0;
    var failed = 0;
    var kept = 0;
    var authRequired = false;
    synced += await _reconcileAlreadyApplied(workspaceId);

    for (var round = 0; round < 6; round++) {
      final rows = await _queue.pendingForWorkspace(workspaceId);
      final ordered = [...rows]
        ..sort((a, b) {
          final pa = _pushPriority(a);
          final pb = _pushPriority(b);
          if (pa != pb) return pa.compareTo(pb);
          return a.createdAt.compareTo(b.createdAt);
        });
      final ready = <SyncQueueItem>[];
      kept = 0;
      for (final row in ordered) {
        if (row.workspaceId != workspaceId) continue;
        if (row.status == 'cancelled' || row.status == 'synced') continue;
        if (row.status == 'failed') {
          kept++;
          continue;
        }
        if (row.nextAttemptAt != null &&
            row.nextAttemptAt!.isAfter(DateTime.now())) {
          kept++;
          continue;
        }
        if (!await _rowReadyForPush(row)) {
          kept++;
          continue;
        }
        ready.add(row);
      }
      if (ready.isEmpty) break;

      const chunkSize = 50;
      var sentAny = false;
      for (var i = 0; i < ready.length; i += chunkSize) {
        final chunk = ready.sublist(
          i,
          i + chunkSize > ready.length ? ready.length : i + chunkSize,
        );
        sentAny = true;
        final deviceId = chunk.first.deviceId;
        final operations = <Map<String, dynamic>>[];
        final byId = <String, SyncQueueItem>{};
        for (final row in chunk) {
          final operationId = await _operationUuid(row);
          byId[operationId] = row;
          operations.add({
            'id': operationId,
            'type': _operationType(row),
            'created_at': row.createdAt.toUtc().toIso8601String(),
            'data': await _pushData(row),
          });
        }
        for (final row in chunk) {
          await _queue.markSyncing(row.id);
        }
        try {
          final data = await _sendPushBatch({
            'device_id': deviceId,
            'operations': operations,
          });
          final accepted = _asMapList(data['accepted']);
          final failedOps = _asMapList(data['failed']);
          for (final ack in accepted) {
            final id = '${ack['id'] ?? ''}';
            final row = byId.remove(id);
            if (row == null) continue;
            await _applyAcceptedAck(row, ack);
            synced++;
          }
          for (final ack in failedOps) {
            final id = '${ack['id'] ?? ''}';
            final row = byId.remove(id);
            if (row == null) continue;
            final retryable = ack['retryable'] != false;
            await _queue.markFailed(
              row.id,
              '${ack['error'] ?? 'فشلت المزامنة'}',
              retryable: retryable,
            );
            if (retryable) {
              kept++;
            } else {
              failed++;
            }
          }
          for (final leftover in byId.values) {
            await _queue.markFailed(
              leftover.id,
              'لم يُرجع الخادم تأكيداً لهذه العملية',
              retryable: true,
            );
            kept++;
          }
        } on ApiException catch (e) {
          if (e.statusCode == 404) {
            for (final row in chunk) {
              await _queue.markFailed(row.id, e.message, retryable: true);
            }
            return (
              result: const SyncEngineV2Result(),
              fellBackToRow: true,
              synced: 0,
              failed: 0,
            );
          }
          if (e.statusCode == 401) {
            for (final row in chunk) {
              await _queue.markFailed(row.id, e.message, retryable: true);
            }
            authRequired = true;
            kept += chunk.length;
            break;
          }
          if (e.statusCode == 403) {
            for (final row in chunk) {
              await _queue.markFailed(row.id, e.message, retryable: false);
              if (row.entityType == 'order') {
                await _markOrderFailed(row.entityId, e.message);
              }
            }
            failed += chunk.length;
            break;
          }
          for (final row in chunk) {
            await _queue.markFailed(row.id, e.message, retryable: true);
          }
          kept += chunk.length;
        } catch (e) {
          for (final row in chunk) {
            await _queue.markFailed(row.id, e.toString(), retryable: true);
          }
          kept += chunk.length;
        }
      }
      if (authRequired || !sentAny) break;
    }

    return (
      result: SyncEngineV2Result(
        synced: synced,
        failed: failed,
        keptPending: kept,
        authRequired: authRequired,
      ),
      fellBackToRow: false,
      synced: synced,
      failed: failed,
    );
  }

  Future<int> _reconcileAlreadyApplied(int workspaceId) async {
    final classifier = SyncQueueClassifier(_db);
    final items = await classifier.classifyWorkspace(workspaceId);
    var marked = 0;
    for (final item in items) {
      if (item.bucket != SyncQueueBucket.alreadyApplied) continue;
      await _queue.markSynced(item.row.id);
      marked++;
    }
    return marked;
  }

  Future<bool> _rowReadyForPush(SyncQueueItem row) async {
    // Scoped batch: kitchen/sale orders + invoices + menu + table master
    // + table sessions. Sale stock.movement stays out (alreadyApplied).
    if (!_isScopedBatchOp(row)) return false;
    if (row.entityType == 'table_session') {
      if (!await _localTableHasServerId(row)) return false;
      if (row.operation == 'close') {
        if (await _hasPendingSessionOpen(row)) {
          return false;
        }
        if (await _closeBlockedByUnsyncedOrders(row)) {
          return false;
        }
      }
      if (_isSessionAction(row.operation)) {
        return _sessionActionReady(row);
      }
      return true;
    }
    if (row.entityType == 'stock' || row.entityType == 'stock_movement') {
      return true;
    }
    if (row.entityType == 'order' && row.operation == 'update') {
      if (SyncQueueClassifier.isOrderItemsUpdate(row)) {
        return _orderItemsUpdateReady(row);
      }
      return _kitchenStatusReady(row);
    }
    if (row.entityType == 'order') {
      return _saleCreateReady(row);
    }
    if (row.entityType == 'invoice') {
      return _saleInvoiceReady(row);
    }
    if (row.entityType == 'product') {
      return _productReady(row);
    }
    if (row.entityType == 'category' && row.operation != 'create') {
      return _catalogHasServerId('category', row);
    }
    if (row.entityType == 'table' && row.operation != 'create') {
      return _tableMasterReady(row);
    }
    return true;
  }

  bool _isScopedBatchOp(SyncQueueItem row) {
    if (row.entityType == 'customer' && row.operation == 'create') {
      return true;
    }
    if (row.entityType == 'order' && row.operation == 'create') {
      return SyncQueueClassifier.saleTypes.contains(
        SyncQueueClassifier.saleTypeOf(row),
      );
    }
    if (row.entityType == 'order' && row.operation == 'update') {
      return SyncQueueClassifier.isKitchenStatusOp(row) ||
          SyncQueueClassifier.isOrderItemsUpdate(row);
    }
    if (row.entityType == 'invoice' && row.operation == 'create') {
      final type = SyncQueueClassifier.saleTypeOf(row);
      return type == null || SyncQueueClassifier.saleTypes.contains(type);
    }
    if (SyncQueueClassifier.menuTypes.contains(row.entityType) &&
        (row.operation == 'create' ||
            row.operation == 'update' ||
            row.operation == 'delete')) {
      return true;
    }
    if (row.entityType == SyncQueueClassifier.tableMasterType &&
        (row.operation == 'create' ||
            row.operation == 'update' ||
            row.operation == 'delete')) {
      return true;
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
      final payload = SyncQueueClassifier.decodePayload(row.payloadJson);
      final kind =
          '${payload['kind'] ?? payload['type'] ?? ''}'.trim().toLowerCase();
      return kind.isNotEmpty && kind != 'sale' && kind != 'remove';
    }
    return false;
  }

  Future<bool> _kitchenStatusReady(SyncQueueItem row) async {
    if (!SyncQueueClassifier.isKitchenStatusOp(row)) return false;
    final payload = await _pushData(row);
    final serverId = (payload['order_server_id'] as num?)?.toInt() ??
        (payload['server_order_id'] as num?)?.toInt() ??
        0;
    return serverId > 0;
  }

  Future<bool> _orderItemsUpdateReady(SyncQueueItem row) async {
    if (!SyncQueueClassifier.isOrderItemsUpdate(row)) return false;
    final payload = await _pushData(row);
    final serverId = (payload['order_server_id'] as num?)?.toInt() ??
        (payload['server_order_id'] as num?)?.toInt() ??
        0;
    if (serverId <= 0) return false;
    final items = payload['items'];
    if (items is! List || items.isEmpty) return false;
    for (final item in items) {
      if (item is! Map) return false;
      final menuId = (item['pos_menu_item_id'] as num?)?.toInt() ?? 0;
      final qty = (item['quantity'] as num?)?.toInt() ?? 0;
      if (menuId <= 0 || qty < 1) return false;
    }
    return true;
  }

  Future<bool> _saleCreateReady(SyncQueueItem row) async {
    if (row.clientReference.trim().isEmpty) return false;
    final payload = await _pushData(row);
    final type = '${payload['order_type'] ?? ''}'.trim().toLowerCase();
    if (!SyncQueueClassifier.saleTypes.contains(type)) return false;
    final items = payload['items'];
    if (items is! List || items.isEmpty) return false;
    for (final item in items) {
      if (item is! Map) return false;
      final menuId = (item['pos_menu_item_id'] as num?)?.toInt() ?? 0;
      final qty = (item['quantity'] as num?)?.toInt() ?? 0;
      if (menuId <= 0 || qty < 1) return false;
    }
    final customerLocal = '${payload['customer_local_id'] ?? ''}'.trim();
    final customerId = (payload['customer_id'] as num?)?.toInt() ?? 0;
    if (customerLocal.isNotEmpty && customerId <= 0) {
      return false;
    }
    if (type == 'table') {
      final tableLocal = '${payload['table_local_id'] ?? ''}'.trim();
      if (tableLocal.isNotEmpty) {
        final table =
            await (_db.select(_db.localTables)..where(
                  (t) =>
                      t.workspaceId.equals(row.workspaceId) &
                      t.localId.equals(tableLocal),
                ))
                .getSingleOrNull();
        if (table == null || table.serverId == null || table.serverId! <= 0) {
          return false;
        }
      }
    }
    return true;
  }

  Future<bool> _saleInvoiceReady(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    var type = '${payload['order_type'] ?? ''}'.trim().toLowerCase();
    final orderLocalId = '${payload['order_local_id'] ?? ''}'.trim();
    if (orderLocalId.isEmpty) return false;
    final order =
        await (_db.select(_db.localOrders)..where(
              (t) =>
                  t.workspaceId.equals(row.workspaceId) &
                  t.localId.equals(orderLocalId),
            ))
            .getSingleOrNull();
    if (order == null) return false;
    if (type.isEmpty) type = order.orderType.trim().toLowerCase();
    if (!SyncQueueClassifier.saleTypes.contains(type)) return false;
    return order.serverId != null && order.serverId! > 0;
  }

  Future<bool> _productReady(SyncQueueItem row) async {
    if (row.operation != 'create' &&
        !await _catalogHasServerId('product', row)) {
      return false;
    }
    final payload = _decode(row.payloadJson);
    final product =
        await (_db.select(_db.localProducts)..where(
              (t) =>
                  t.workspaceId.equals(row.workspaceId) &
                  t.localId.equals(row.entityId),
            ))
            .getSingleOrNull();
    final categoryLocal =
        '${payload['category_local_id'] ?? product?.categoryLocalId ?? ''}'
            .trim();
    if (categoryLocal.isEmpty) return true;
    final category =
        await (_db.select(_db.localCategories)..where(
              (t) =>
                  t.workspaceId.equals(row.workspaceId) &
                  t.localId.equals(categoryLocal),
            ))
            .getSingleOrNull();
    return category?.serverId != null && category!.serverId! > 0;
  }

  Future<bool> _catalogHasServerId(String entityType, SyncQueueItem row) async {
    if (entityType == 'category') {
      final category =
          await (_db.select(_db.localCategories)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(row.entityId),
              ))
              .getSingleOrNull();
      return category?.serverId != null && category!.serverId! > 0;
    }
    if (entityType == 'product') {
      final product =
          await (_db.select(_db.localProducts)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(row.entityId),
              ))
              .getSingleOrNull();
      return product?.serverId != null && product!.serverId! > 0;
    }
    return false;
  }

  Future<bool> _tableMasterReady(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    final table =
        await (_db.select(_db.localTables)..where(
              (t) =>
                  t.workspaceId.equals(row.workspaceId) &
                  t.localId.equals(row.entityId),
            ))
            .getSingleOrNull();
    final serverId = table?.serverId ??
        (payload['server_id'] as num?)?.toInt() ??
        (payload['table_server_id'] as num?)?.toInt();
    return serverId != null && serverId > 0;
  }

  Future<Map<String, dynamic>> _pushData(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    if (row.entityType == 'invoice') {
      final orderLocalId = '${payload['order_local_id'] ?? ''}'.trim();
      if (orderLocalId.isNotEmpty) {
        payload['client_reference'] = orderLocalId;
        payload['order_local_id'] = orderLocalId;
        final order =
            await (_db.select(_db.localOrders)..where(
                  (t) =>
                      t.workspaceId.equals(row.workspaceId) &
                      t.localId.equals(orderLocalId),
                ))
                .getSingleOrNull();
        if (order?.serverId != null && order!.serverId! > 0) {
          payload['order_server_id'] = order.serverId;
        }
      }
      return payload;
    }
    payload['client_reference'] = row.clientReference;
    if (row.entityType == 'table_session') {
      payload['device_id'] = row.deviceId;
      if ('${payload['session_client_id'] ?? ''}'.trim().isEmpty) {
        payload['session_client_id'] = row.clientReference;
      }
      final table =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.localId.equals(row.entityId) &
                    t.workspaceId.equals(row.workspaceId),
              ))
              .getSingleOrNull();
      final tableServerId = table?.serverId;
      if (tableServerId != null && tableServerId > 0) {
        payload['table_server_id'] = tableServerId;
        payload['dining_table_id'] = tableServerId;
        final sessionId = await _resolvedSessionServerId(row, payload, table);
        if (sessionId != null && sessionId > 0) {
          payload['session_server_id'] = sessionId;
          payload['session_id'] = sessionId;
        }
      } else {
        // A board id is not a Laravel id. Sending it opens the wrong table
        // or fails the first sitting permanently.
        payload.remove('table_server_id');
        payload.remove('dining_table_id');
        payload.remove('table_id');
        payload.remove('session_server_id');
        payload.remove('session_id');
      }
    }
    if (row.entityType == 'category' ||
        row.entityType == 'product' ||
        row.entityType == 'table') {
      return _catalogPushData(row, payload);
    }
    if (row.entityType == 'order' && row.operation == 'update') {
      final order =
          await (_db.select(_db.localOrders)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(row.entityId),
              ))
              .getSingleOrNull();
      final serverId = order?.serverId ??
          (payload['order_server_id'] as num?)?.toInt() ??
          (payload['server_order_id'] as num?)?.toInt();
      if (serverId != null && serverId > 0) {
        payload['order_server_id'] = serverId;
        payload['server_order_id'] = serverId;
        payload['id'] = serverId;
      }
      final items = payload['items'];
      final hasItems = items is List && items.isNotEmpty;
      if (hasItems && payload['kitchen_status'] != true) {
        await _hydratePayloadItemServerIds(payload);
        return payload;
      }
      payload['kitchen_status'] = true;
      if ('${payload['pos_status'] ?? ''}'.trim().isEmpty) {
        payload['pos_status'] = order?.posStatus ?? 'new';
      }
      return payload;
    }
    if (row.entityType == 'order' && row.operation == 'create') {
      payload['offline_sale'] = true;
      await _resolveOrderTableId(row, payload);
      await _hydrateOrderItemServerIds(row, payload);
    }
    if (row.entityType != 'order' || row.operation != 'create') {
      return payload;
    }
    final customerId = (payload['customer_id'] as num?)?.toInt() ?? 0;
    if (customerId > 0) return payload;
    final customerLocal = '${payload['customer_local_id'] ?? ''}'.trim();
    if (customerLocal.isEmpty) return payload;
    final customer =
        await (_db.select(_db.localCustomers)..where(
              (t) =>
                  t.workspaceId.equals(row.workspaceId) &
                  t.localId.equals(customerLocal),
            ))
            .getSingleOrNull();
    if (customer?.serverId != null && customer!.serverId! > 0) {
      payload['customer_id'] = customer.serverId;
    }
    return payload;
  }

  Future<Map<String, dynamic>> _catalogPushData(
    SyncQueueItem row,
    Map<String, dynamic> payload,
  ) async {
    if (row.entityType == 'product') {
      final product =
          await (_db.select(_db.localProducts)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(row.entityId),
              ))
              .getSingleOrNull();
      if (product?.serverId != null && product!.serverId! > 0) {
        payload['server_id'] = product.serverId;
      }
      final categoryLocal =
          '${payload['category_local_id'] ?? product?.categoryLocalId ?? ''}'
              .trim();
      if (categoryLocal.isNotEmpty) {
        payload['category_local_id'] = categoryLocal;
        final category =
            await (_db.select(_db.localCategories)..where(
                  (t) =>
                      t.workspaceId.equals(row.workspaceId) &
                      t.localId.equals(categoryLocal),
                ))
                .getSingleOrNull();
        if (category?.serverId != null && category!.serverId! > 0) {
          payload['pos_item_category_id'] = category.serverId;
        }
      }
    }
    if (row.entityType == 'category') {
      final category =
          await (_db.select(_db.localCategories)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(row.entityId),
              ))
              .getSingleOrNull();
      if (category?.serverId != null && category!.serverId! > 0) {
        payload['server_id'] = category.serverId;
      }
    }
    if (row.entityType == 'table') {
      payload.remove('status');
      payload.remove('session_id');
      payload.remove('session_open');
      final table =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(row.entityId),
              ))
              .getSingleOrNull();
      final serverId = table?.serverId ??
          (payload['server_id'] as num?)?.toInt() ??
          (payload['table_server_id'] as num?)?.toInt();
      if (serverId != null && serverId > 0) {
        payload['server_id'] = serverId;
        payload['table_server_id'] = serverId;
      }
    }
    return payload;
  }

  Future<void> _resolveOrderTableId(
    SyncQueueItem row,
    Map<String, dynamic> payload,
  ) async {
    final tableLocal = '${payload['table_local_id'] ?? ''}'.trim();
    if (tableLocal.isNotEmpty) {
      final table =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(tableLocal),
              ))
              .getSingleOrNull();
      if (table?.serverId != null && table!.serverId! > 0) {
        payload['dining_table_id'] = table.serverId;
        payload['table_server_id'] = table.serverId;
        return;
      }
      payload.remove('dining_table_id');
      payload.remove('table_server_id');
      return;
    }
    final tableId = _numericId(
      payload['table_server_id'] ??
          payload['dining_table_id'] ??
          payload['table_id'],
    );
    if (tableId != null) {
      payload['dining_table_id'] = tableId;
    }
  }

  Future<bool> _localTableHasServerId(SyncQueueItem row) async {
    final table =
        await (_db.select(_db.localTables)..where(
              (t) =>
                  t.localId.equals(row.entityId) &
                  t.workspaceId.equals(row.workspaceId),
            ))
            .getSingleOrNull();
    return table?.serverId != null && table!.serverId! > 0;
  }

  /// A session op that was rejected with a board id can proceed once
  /// table.created has stored the real Laravel id.
  Future<void> _requeueSessionsSentBeforeTable(int workspaceId) async {
    final rows = await _queue.pendingForWorkspace(workspaceId);
    final now = DateTime.now();
    for (final row in rows) {
      if (row.status != 'failed' || row.entityType != 'table_session') {
        continue;
      }
      final table =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.localId.equals(row.entityId) &
                    t.workspaceId.equals(workspaceId),
              ))
              .getSingleOrNull();
      final serverId = table?.serverId;
      if (serverId == null || serverId <= 0) continue;
      final payload = _decode(row.payloadJson);
      final sentId = (payload['table_server_id'] as num?)?.toInt() ?? 0;
      if (sentId == serverId) continue;
      await (_db.update(_db.syncQueueItems)..where((t) => t.id.equals(row.id)))
          .write(
            SyncQueueItemsCompanion(
              status: const Value('pending'),
              nextAttemptAt: const Value(null),
              updatedAt: Value(now),
            ),
          );
    }
  }

  Future<void> _hydratePayloadItemServerIds(Map<String, dynamic> payload) async {
    final items = payload['items'];
    if (items is! List) return;
    for (final raw in items) {
      if (raw is! Map) continue;
      final current = (raw['pos_menu_item_id'] as num?)?.toInt() ?? 0;
      if (current > 0) continue;
      final localId = '${raw['product_local_id'] ?? ''}'.trim();
      if (localId.isEmpty) continue;
      final product =
          await (_db.select(_db.localProducts)..where(
                (t) => t.localId.equals(localId),
              ))
              .getSingleOrNull();
      final serverId = product?.serverId;
      if (serverId != null && serverId > 0) {
        raw['pos_menu_item_id'] = serverId;
      }
    }
  }

  Future<int?> _resolvedSessionServerId(
    SyncQueueItem row,
    Map<String, dynamic> payload,
    LocalTable? table,
  ) async {
    final fromPayload = (payload['session_server_id'] as num?)?.toInt() ??
        (payload['session_id'] as num?)?.toInt();
    if (fromPayload != null && fromPayload > 0) return fromPayload;
    final clientId = '${payload['session_client_id'] ?? ''}'.trim();
    if (clientId.isNotEmpty) {
      final session =
          await (_db.select(_db.localSessions)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(clientId),
              ))
              .getSingleOrNull();
      if (session?.serverId != null && session!.serverId! > 0) {
        return session.serverId;
      }
    }
    final fromTable = table?.sessionServerId;
    if (fromTable != null && fromTable > 0) return fromTable;
    return null;
  }

  /// Sales checked out before the product had a Laravel id never entered the
  /// queue. Once the product is known, the next push sends them.
  Future<void> _catchUpUnqueuedSales(int workspaceId) async {
    if (PosMode.isReservedStandaloneWorkspace(workspaceId)) return;
    final orders =
        await (_db.select(_db.localOrders)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) & t.serverId.isNull(),
            ))
            .get();
    for (final order in orders) {
      final type = order.orderType.trim().toLowerCase();
      if (!SyncQueueClassifier.saleTypes.contains(type)) continue;
      if (order.posStatus == 'cancelled') continue;
      if (order.clientReference.trim().isEmpty) continue;
      final sync = order.syncStatus.trim().toLowerCase();
      if (sync != 'local' && sync != 'pending') continue;
      final existing = await (_db.select(_db.syncQueueItems)..where(
            (t) =>
                t.workspaceId.equals(workspaceId) &
                t.entityType.equals('order') &
                t.entityId.equals(order.localId) &
                t.operation.equals('create') &
                t.status.isNotValue('cancelled'),
          ))
          .get();
      if (existing.isNotEmpty) continue;
      final items =
          await (_db.select(_db.localOrderItems)..where(
                (t) =>
                    t.orderLocalId.equals(order.localId) &
                    t.workspaceId.equals(workspaceId) &
                    t.isRemoved.equals(false),
              ))
              .get();
      if (items.isEmpty) continue;
      final sellable = items.every((item) {
        final server = item.productServerId ?? 0;
        final local = item.productLocalId?.trim() ?? '';
        return server > 0 || local.isNotEmpty;
      });
      if (!sellable) continue;
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: order.deviceId,
        entityType: 'order',
        entityId: order.localId,
        operation: 'create',
        clientReference: order.clientReference,
        payload: {
          'order_type': type,
          'offline_sale': true,
          if (order.tableServerId != null && order.tableServerId! > 0)
            'dining_table_id': order.tableServerId,
          if (order.tableLocalId != null && order.tableLocalId!.trim().isNotEmpty)
            'table_local_id': order.tableLocalId!.trim(),
          if (order.sessionLocalId != null &&
              order.sessionLocalId!.trim().isNotEmpty)
            'session_local_id': order.sessionLocalId!.trim(),
          'client_reference': order.clientReference,
          'placed_at': order.createdAt.toUtc().toIso8601String(),
          'currency': 'SAR',
          'subtotal_amount': Money.fromCents(order.subtotal),
          'discount_amount': Money.fromCents(order.discountAmount),
          'tax_amount': Money.fromCents(order.taxAmount),
          'total_amount': Money.fromCents(order.totalAmount),
          'items': [
            for (final item in items)
              {
                if (item.productServerId != null && item.productServerId! > 0)
                  'pos_menu_item_id': item.productServerId,
                if (item.productLocalId != null &&
                    item.productLocalId!.trim().isNotEmpty)
                  'product_local_id': item.productLocalId!.trim(),
                'quantity': item.quantity,
                'unit_price': Money.fromCents(item.unitPrice),
                'name': item.name,
                'product_name': item.name,
              },
          ],
        },
      );
      if (sync == 'local') {
        await (_db.update(_db.localOrders)..where(
              (t) => t.localId.equals(order.localId),
            ))
            .write(
              const LocalOrdersCompanion(syncStatus: Value('pending')),
            );
      }
      final invoices =
          await (_db.select(_db.localInvoices)..where(
                (t) =>
                    t.workspaceId.equals(workspaceId) &
                    t.orderLocalId.equals(order.localId) &
                    t.serverId.isNull(),
              ))
              .get();
      for (final invoice in invoices) {
        final invoiceSync = invoice.syncStatus.trim().toLowerCase();
        if (invoiceSync != 'local' && invoiceSync != 'pending') continue;
        final queuedInvoice = await (_db.select(_db.syncQueueItems)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.entityType.equals('invoice') &
                  t.entityId.equals(invoice.localId) &
                  t.operation.equals('create') &
                  t.status.isNotValue('cancelled'),
            ))
            .get();
        if (queuedInvoice.isNotEmpty) continue;
        final localNumber =
            (invoice.localInvoiceNumber ?? invoice.invoiceNumber ?? '')
                .trim();
        final payments =
            await (_db.select(_db.localPayments)..where(
                  (t) => t.invoiceLocalId.equals(invoice.localId),
                ))
                .get();
        await _queue.enqueue(
          workspaceId: workspaceId,
          deviceId: invoice.deviceId,
          entityType: 'invoice',
          entityId: invoice.localId,
          operation: 'create',
          clientReference: invoice.localId,
          payload: {
            'order_type': type,
            'order_local_id': order.localId,
            'local_invoice_number': localNumber.isEmpty
                ? invoice.localId
                : localNumber,
            'closed_at': invoice.createdAt.toUtc().toIso8601String(),
            'currency': 'SAR',
            'subtotal_amount': Money.fromCents(invoice.subtotal),
            'discount_amount': Money.fromCents(invoice.discountAmount),
            'tax_amount': Money.fromCents(invoice.taxAmount),
            'total_amount': Money.fromCents(invoice.totalAmount),
            'payment_method': payments.isEmpty ? 'cash' : payments.first.method,
          },
        );
        if (invoiceSync == 'local') {
          await (_db.update(_db.localInvoices)..where(
                (t) => t.localId.equals(invoice.localId),
              ))
              .write(
                const LocalInvoicesCompanion(syncStatus: Value('pending')),
              );
        }
      }
    }
  }

  Future<void> _hydrateOrderItemServerIds(
    SyncQueueItem row,
    Map<String, dynamic> payload,
  ) async {
    final items = payload['items'];
    if (items is! List || items.isEmpty) return;
    final locals =
        await (_db.select(_db.localOrderItems)..where(
              (t) =>
                  t.orderLocalId.equals(row.entityId) &
                  t.workspaceId.equals(row.workspaceId) &
                  t.isRemoved.equals(false),
            ))
            .get();
    for (var i = 0; i < items.length; i++) {
      final raw = items[i];
      if (raw is! Map) continue;
      var serverId = (raw['pos_menu_item_id'] as num?)?.toInt() ?? 0;
      var localProductId = '${raw['product_local_id'] ?? ''}'.trim();
      LocalOrderItem? localItem;
      if (i < locals.length) {
        localItem = locals[i];
        final fromItem = localItem.productServerId;
        if (serverId <= 0 && fromItem != null && fromItem > 0) {
          serverId = fromItem;
        }
        if (localProductId.isEmpty) {
          localProductId = localItem.productLocalId?.trim() ?? '';
        }
      }
      if (serverId <= 0 && localProductId.isNotEmpty) {
        final product =
            await (_db.select(_db.localProducts)..where(
                  (t) =>
                      t.workspaceId.equals(row.workspaceId) &
                      t.localId.equals(localProductId),
                ))
                .getSingleOrNull();
        final found = product?.serverId ?? 0;
        if (found > 0) {
          serverId = found;
          if (localItem != null &&
              (localItem.productServerId == null ||
                  localItem.productServerId! <= 0)) {
            final itemId = localItem.localId;
            await (_db.update(_db.localOrderItems)..where(
                  (t) => t.localId.equals(itemId),
                ))
                .write(
                  LocalOrderItemsCompanion(
                    productServerId: Value(found),
                    updatedAt: Value(DateTime.now()),
                  ),
                );
          }
        }
      }
      if (localProductId.isNotEmpty) {
        raw['product_local_id'] = localProductId;
      }
      if (serverId > 0) {
        raw['pos_menu_item_id'] = serverId;
      }
    }
  }

  Future<void> _applyAcceptedAck(
    SyncQueueItem row,
    Map<String, dynamic> ack,
  ) async {
    final result = ack['result'] is Map
        ? Map<String, dynamic>.from(ack['result'] as Map)
        : <String, dynamic>{};
    if (ack['entity_id'] != null && result['id'] == null) {
      result['id'] = ack['entity_id'];
    }
    if (row.entityType == 'customer') {
      await _finalizeCustomer(row, result);
    } else if (row.entityType == 'category' ||
        row.entityType == 'product' ||
        row.entityType == 'table') {
      await _finalizeCatalogMaster(row, result);
    } else if (row.entityType == 'table_session' && row.operation == 'open') {
      await _finalizeSessionOpen(row, result);
    } else if (row.entityType == 'table_session' && row.operation == 'close') {
      await _finalizeSessionClose(row, result);
    } else if (row.entityType == 'table_session') {
      await _queue.markSynced(row.id);
    } else if (row.entityType == 'invoice') {
      await _finalizeInvoice(row, result);
    } else if (row.operation == 'create') {
      await _finalizeOrderCreate(row, result);
    } else if (row.operation == 'update') {
      await _finalizeOrderUpdate(row);
    } else if (row.operation == 'delete') {
      await _finalizeOrderDelete(row);
    } else {
      await _queue.markSynced(row.id);
    }
  }

  List<Map<String, dynamic>> _asMapList(dynamic raw) {
    if (raw is! List) return const [];
    return [
      for (final item in raw)
        if (item is Map) Map<String, dynamic>.from(item),
    ];
  }

  Future<Map<String, dynamic>> _sendPushBatch(Map<String, dynamic> body) {
    final poster = _postPushBatch;
    if (poster != null) return poster(body);
    final api = _api;
    if (api == null) {
      throw StateError('SyncEngineV2 requires API or postPushBatch');
    }
    return api.post('/sync/push', data: body);
  }

  int _pushPriority(SyncQueueItem row) {
    if (row.entityType == 'category') return 0;
    if (row.entityType == 'product') return 1;
    if (row.entityType == 'table') return 1;
    if (row.entityType == 'customer') return 2;
    if (row.entityType == 'table_session' && row.operation == 'open') return 3;
    if (row.entityType == 'table_session' && _isSessionAction(row.operation)) {
      return 4;
    }
    if (row.entityType == 'order') return 5;
    if (row.entityType == 'invoice') return 6;
    if (row.entityType == 'table_session' && row.operation == 'close') return 7;
    if (row.entityType == 'stock' || row.entityType == 'stock_movement') {
      return 8;
    }
    return 9;
  }

  bool _isSessionAction(String operation) =>
      operation == 'cancel' ||
      operation == 'note' ||
      operation == 'discount' ||
      operation == 'transfer' ||
      operation == 'merge' ||
      operation == 'split';

  Future<bool> _sessionActionReady(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    var sessionId = (payload['session_server_id'] as num?)?.toInt();
    if (sessionId != null && sessionId > 0) return true;
    final table =
        await (_db.select(_db.localTables)..where(
              (t) =>
                  t.localId.equals(row.entityId) &
                  t.workspaceId.equals(row.workspaceId),
            ))
            .getSingleOrNull();
    sessionId = table?.sessionServerId;
    if (sessionId != null && sessionId > 0) return true;
    // Transfer/merge may have moved session onto target table.
    final targetId = (payload['target_table_id'] as num?)?.toInt();
    if (targetId != null && targetId > 0) {
      final target =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.serverId.equals(targetId),
              ))
              .getSingleOrNull();
      if (target?.sessionServerId != null && target!.sessionServerId! > 0) {
        return true;
      }
    }
    // Split is applied locally via new order create; remote split can wait.
    if (row.operation == 'split') return true;
    return false;
  }

  Future<void> _pushSessionAction(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    final tableId = (payload['table_server_id'] as num?)?.toInt();
    if (tableId == null || tableId <= 0) {
      throw StateError('table_session action requires table_server_id');
    }
    var sessionId = (payload['session_server_id'] as num?)?.toInt();
    if (sessionId == null || sessionId <= 0) {
      final table =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.localId.equals(row.entityId) &
                    t.workspaceId.equals(row.workspaceId),
              ))
              .getSingleOrNull();
      sessionId = table?.sessionServerId;
    }
    final api = _api;
    if (api == null) {
      throw StateError('SyncEngineV2 requires API for session action');
    }

    // Local split already created a new order; remote split is best-effort.
    if (row.operation == 'split') {
      if (sessionId != null && sessionId > 0) {
        try {
          await api.post(
            '/tables/$tableId/sessions/$sessionId/split',
            data: payload['remote_groups'] ?? {'groups': const []},
            idempotencyKey: row.clientReference,
          );
        } catch (_) {
          // Keep local split; mark synced so we don't block the queue forever.
        }
      }
      await _queue.markSynced(row.id);
      return;
    }

    if (sessionId == null || sessionId <= 0) {
      throw StateError('session_server_id missing for ${row.operation}');
    }

    switch (row.operation) {
      case 'cancel':
        await api.post(
          '/tables/$tableId/sessions/$sessionId/cancel',
          idempotencyKey: row.clientReference,
        );
      case 'note':
        await api.post(
          '/tables/$tableId/sessions/$sessionId/note',
          data: {'notes': payload['notes'] ?? ''},
          idempotencyKey: row.clientReference,
        );
      case 'discount':
        await api.post(
          '/tables/$tableId/sessions/$sessionId/discount',
          data: {'discount_amount': payload['discount_amount'] ?? 0},
          idempotencyKey: row.clientReference,
        );
      case 'transfer':
        await api.post(
          '/tables/$tableId/sessions/$sessionId/transfer',
          data: {'target_table_id': payload['target_table_id']},
          idempotencyKey: row.clientReference,
        );
      case 'merge':
        await api.post(
          '/tables/$tableId/sessions/$sessionId/merge',
          data: {'target_table_id': payload['target_table_id']},
          idempotencyKey: row.clientReference,
        );
      default:
        throw StateError('unsupported session action ${row.operation}');
    }
    await _queue.markSynced(row.id);
  }

  Future<bool> _hasPendingSessionOpen(SyncQueueItem closeRow) async {
    final rows = await _queue.pendingForWorkspace(closeRow.workspaceId);
    for (final row in rows) {
      if (row.id == closeRow.id) continue;
      if (row.entityType != 'table_session' || row.operation != 'open') {
        continue;
      }
      if (row.entityId != closeRow.entityId) continue;
      if (row.status == 'pending' ||
          row.status == 'failed' ||
          row.status == 'syncing') {
        return true;
      }
    }
    return false;
  }

  Future<bool> _closeBlockedByUnsyncedOrders(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    final tableLocalId = '${payload['table_local_id'] ?? ''}'.trim();
    if (tableLocalId.isNotEmpty) {
      final rows =
          await (_db.select(_db.localOrders)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.tableLocalId.equals(tableLocalId) &
                    t.posStatus.isNotValue('cancelled') &
                    (t.syncStatus.equals('pending') |
                        t.syncStatus.equals('syncing') |
                        t.syncStatus.equals('failed')),
              ))
              .get();
      return rows.isNotEmpty;
    }
    final tableId = (payload['table_server_id'] as num?)?.toInt();
    if (tableId == null) return false;
    return _hasUnsyncedOrdersForTable(row.workspaceId, tableId);
  }

  Future<bool> _hasUnsyncedOrdersForTable(int workspaceId, int tableId) async {
    final rows =
        await (_db.select(_db.localOrders)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.tableServerId.equals(tableId) &
                  t.posStatus.isNotValue('cancelled') &
                  (t.syncStatus.equals('pending') |
                      t.syncStatus.equals('syncing') |
                      t.syncStatus.equals('failed')),
            ))
            .get();
    return rows.isNotEmpty;
  }

  Future<bool> _orderNeedsServerId(int workspaceId, String orderLocalId) async {
    final order =
        await (_db.select(_db.localOrders)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.localId.equals(orderLocalId),
            ))
            .getSingleOrNull();
    if (order == null) return true;
    return order.serverId == null || order.serverId! <= 0;
  }

  Future<void> _finalizeOrderCreate(
    SyncQueueItem row,
    Map<String, dynamic> data,
  ) async {
    final serverId = data['id'] is num
        ? asIntOr(data['id'])
        : int.tryParse('${data['id']}');
    final orderNumber = '${data['order_number'] ?? ''}'.trim();
    await _db.transaction(() async {
      await (_db.update(_db.localOrders)..where(
            (t) =>
                t.localId.equals(row.entityId) &
                t.workspaceId.equals(row.workspaceId),
          ))
          .write(
            LocalOrdersCompanion(
              serverId: Value(serverId),
              orderNumber: orderNumber.isEmpty
                  ? const Value.absent()
                  : Value(orderNumber),
              subtotal: data['subtotal'] is num
                  ? Value(Money.toCents(data['subtotal']))
                  : const Value.absent(),
              taxAmount: data['tax_amount'] is num
                  ? Value(Money.toCents(data['tax_amount']))
                  : const Value.absent(),
              discountAmount: data['discount_amount'] is num
                  ? Value(Money.toCents(data['discount_amount']))
                  : const Value.absent(),
              totalAmount: data['total_amount'] is num
                  ? Value(Money.toCents(data['total_amount']))
                  : const Value.absent(),
              syncStatus: const Value('synced'),
              lastError: const Value(null),
              syncedAt: Value(DateTime.now()),
              updatedAt: Value(DateTime.now()),
            ),
          );
      await _queue.markSynced(row.id);
    });
  }

  Future<void> _finalizeOrderUpdate(SyncQueueItem row) async {
    await _db.transaction(() async {
      await (_db.update(_db.localOrders)..where(
            (t) =>
                t.localId.equals(row.entityId) &
                t.workspaceId.equals(row.workspaceId),
          ))
          .write(
            LocalOrdersCompanion(
              syncStatus: const Value('synced'),
              lastError: const Value(null),
              syncedAt: Value(DateTime.now()),
              updatedAt: Value(DateTime.now()),
            ),
          );
      await _queue.markSynced(row.id);
    });
  }

  Future<void> _finalizeOrderDelete(SyncQueueItem row) async {
    await _db.transaction(() async {
      await (_db.update(_db.localOrders)..where(
            (t) =>
                t.localId.equals(row.entityId) &
                t.workspaceId.equals(row.workspaceId),
          ))
          .write(
            LocalOrdersCompanion(
              posStatus: const Value('cancelled'),
              syncStatus: const Value('synced'),
              lastError: const Value(null),
              syncedAt: Value(DateTime.now()),
              updatedAt: Value(DateTime.now()),
            ),
          );
      await _queue.markSynced(row.id);
    });
  }

  Future<void> _finalizeCustomer(
    SyncQueueItem row,
    Map<String, dynamic> data,
  ) async {
    final payload = _decode(row.payloadJson);
    final serverId = data['id'] is num
        ? asIntOr(data['id'])
        : int.tryParse('${data['id']}');
    await _db.transaction(() async {
      await (_db.update(_db.localCustomers)..where(
            (t) =>
                t.localId.equals(row.entityId) &
                t.workspaceId.equals(row.workspaceId),
          ))
          .write(
            LocalCustomersCompanion(
              serverId: Value(serverId),
              syncStatus: const Value('synced'),
              name: Value('${data['name'] ?? payload['name'] ?? ''}'),
              phone: Value(
                data['phone'] as String? ?? payload['phone'] as String?,
              ),
              updatedAt: Value(DateTime.now()),
            ),
          );
      await _queue.markSynced(row.id);
    });
  }

  Future<void> _finalizeCatalogMaster(
    SyncQueueItem row,
    Map<String, dynamic> data,
  ) async {
    final serverId = data['id'] is num
        ? asInt(data['id'])
        : int.tryParse('${data['id']}');
    final now = DateTime.now();
    await _db.transaction(() async {
      if (row.entityType == 'category') {
        if (row.operation == 'create' && serverId != null && serverId > 0) {
          await (_db.update(_db.localCategories)..where(
                (t) =>
                    t.localId.equals(row.entityId) &
                    t.workspaceId.equals(row.workspaceId),
              ))
              .write(
                LocalCategoriesCompanion(
                  serverId: Value(serverId),
                  updatedAt: Value(now),
                ),
              );
          await (_db.update(_db.localProducts)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.categoryLocalId.equals(row.entityId),
              ))
              .write(
                LocalProductsCompanion(
                  categoryServerId: Value(serverId),
                  updatedAt: Value(now),
                ),
              );
        }
      } else if (row.entityType == 'product') {
        if (row.operation == 'create' && serverId != null && serverId > 0) {
          final categoryServerId =
              (data['pos_item_category_id'] as num?)?.toInt();
          await (_db.update(_db.localProducts)..where(
                (t) =>
                    t.localId.equals(row.entityId) &
                    t.workspaceId.equals(row.workspaceId),
              ))
              .write(
                LocalProductsCompanion(
                  serverId: Value(serverId),
                  categoryServerId: categoryServerId == null
                      ? const Value.absent()
                      : Value(categoryServerId),
                  updatedAt: Value(now),
                ),
              );
          await (_db.update(_db.localOrderItems)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.productLocalId.equals(row.entityId),
              ))
              .write(
                LocalOrderItemsCompanion(
                  productServerId: Value(serverId),
                  updatedAt: Value(now),
                ),
              );
        }
      } else if (row.entityType == 'table') {
        if (row.operation == 'delete') {
          await (_db.delete(_db.localTables)..where(
                (t) =>
                    t.localId.equals(row.entityId) &
                    t.workspaceId.equals(row.workspaceId),
              ))
              .go();
        } else if (row.operation == 'create' &&
            serverId != null &&
            serverId > 0) {
          final table =
              await (_db.select(_db.localTables)..where(
                    (t) =>
                        t.localId.equals(row.entityId) &
                        t.workspaceId.equals(row.workspaceId),
                  ))
                  .getSingleOrNull();
          Map<String, dynamic> payload = const {};
          if (table != null) {
            payload = _decode(table.payloadJson);
          }
          payload['id'] = serverId;
          await (_db.update(_db.localTables)..where(
                (t) =>
                    t.localId.equals(row.entityId) &
                    t.workspaceId.equals(row.workspaceId),
              ))
              .write(
                LocalTablesCompanion(
                  serverId: Value(serverId),
                  payloadJson: Value(jsonEncode(payload)),
                  updatedAt: Value(now),
                ),
              );
          await (_db.update(_db.localOrders)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.tableLocalId.equals(row.entityId),
              ))
              .write(
                LocalOrdersCompanion(
                  tableServerId: Value(serverId),
                  updatedAt: Value(now),
                ),
              );
        }
      }
      await _queue.markSynced(row.id);
    });
  }

  Future<void> _finalizeSessionOpen(
    SyncQueueItem row,
    Map<String, dynamic> data,
  ) async {
    final sessionId = (data['session_id'] as num?)?.toInt() ??
        (data['accepted_session_id'] as num?)?.toInt() ??
        (data['id'] as num?)?.toInt();
    final now = DateTime.now();
    await _db.transaction(() async {
      final table =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.localId.equals(row.entityId) &
                    t.workspaceId.equals(row.workspaceId),
              ))
              .getSingleOrNull();
      // Bind the device sitting to its server id so pull recognises the same
      // sitting instead of minting a second local row for it.
      final sessionClientId = '${_decode(row.payloadJson)['session_client_id'] ?? row.clientReference ?? ''}'.trim();
      if (sessionId != null && sessionId > 0 && sessionClientId.isNotEmpty) {
        await (_db.update(_db.localSessions)..where(
              (t) =>
                  t.localId.equals(sessionClientId) &
                  t.workspaceId.equals(row.workspaceId),
            ))
            .write(
          LocalSessionsCompanion(
            serverId: Value(sessionId),
            updatedAt: Value(now),
          ),
        );
      }
      if (table != null) {
        final prev = _decode(table.payloadJson);
        final pendingClose = await _queue.findOpenOp(
          workspaceId: row.workspaceId,
          entityType: 'table_session',
          entityId: row.entityId,
          operation: 'close',
        );
        final locallyClosed = table.status == 'available' &&
            prev['session_open'] != true &&
            '${prev['session_client_id'] ?? ''}'.trim().isEmpty;
        if (pendingClose != null || locallyClosed) {
          await (_db.update(
            _db.localTables,
          )..where((t) => t.localId.equals(table.localId))).write(
            LocalTablesCompanion(
              sessionServerId: Value(sessionId),
              updatedAt: Value(now),
            ),
          );
        } else {
          final next = {
            ...prev,
            'session_id': sessionId,
            'session_open': true,
            'status': 'occupied',
            if (data['opened_at'] != null) 'opened_at': data['opened_at'],
          };
          await (_db.update(
            _db.localTables,
          )..where((t) => t.localId.equals(table.localId))).write(
            LocalTablesCompanion(
              sessionServerId: Value(sessionId),
              status: const Value('occupied'),
              payloadJson: Value(jsonEncode(next)),
              updatedAt: Value(now),
            ),
          );
        }
      }
      await _queue.markSynced(row.id);
    });
    if (data['conflict'] == true || data['conflict_status'] == 'conflict') {
      await SyncConflictRepository(_db).record(
        workspaceId: row.workspaceId,
        entityType: 'table_session',
        entityId: row.clientReference,
        strategy: 'detectAndRecord',
        reason: '${data['reason'] ?? 'table_session_open_conflict_other_device'}',
        deviceId: row.deviceId,
        operation: 'open',
        local: {
          'local_session_id': data['local_session_id'] ?? row.clientReference,
          'table_id': data['table_id'],
          'device_id': data['device_id'] ?? row.deviceId,
          'accepted_session_id': data['accepted_session_id'] ?? sessionId,
        },
        server: {
          'server_session_id': data['server_session_id'],
          'table_id': data['table_id'],
          'conflict_status': data['conflict_status'] ?? 'conflict',
        },
      );
    }
  }

  Future<void> _finalizeSessionClose(
    SyncQueueItem row,
    Map<String, dynamic> data,
  ) async {
    final invoice = data['invoice'] is Map
        ? Map<String, dynamic>.from(data['invoice'] as Map)
        : null;
    await _markInvoiceSyncedFromClose(row, invoice);
    await _queue.markSynced(row.id);
  }

  Future<void> _finalizeInvoice(
    SyncQueueItem row,
    Map<String, dynamic> data,
  ) async {
    final invoiceLocalId = row.entityId;
    final existing = await (_db.select(
      _db.localInvoices,
    )..where((t) => t.localId.equals(invoiceLocalId))).getSingleOrNull();
    final prev = existing == null
        ? <String, dynamic>{}
        : _decode(existing.payloadJson);
    final serverNumber = data['invoice_number']?.toString();
    final serverId =
        (data['invoice_id'] as num?)?.toInt() ?? (data['id'] as num?)?.toInt();
    final merged = {
      ...prev,
      'id': data['invoice_id'] ?? data['id'],
      'server_id': serverId,
      if (serverNumber != null && serverNumber.isNotEmpty) ...{
        'server_invoice_number': serverNumber,
        'invoice_number': serverNumber,
      },
      'currency': data['currency'] ?? prev['currency'],
      'sync_status': 'synced',
    };
    await _db.transaction(() async {
      await (_db.update(
        _db.localInvoices,
      )..where((t) => t.localId.equals(invoiceLocalId))).write(
        LocalInvoicesCompanion(
          serverId: Value(serverId),
          serverInvoiceNumber: Value(
            (serverNumber != null && serverNumber.isNotEmpty)
                ? serverNumber
                : existing?.serverInvoiceNumber,
          ),
          subtotal: data['subtotal'] is num
              ? Value(Money.toCents(data['subtotal']))
              : const Value.absent(),
          taxAmount: data['tax_amount'] is num
              ? Value(Money.toCents(data['tax_amount']))
              : const Value.absent(),
          discountAmount: data['discount_amount'] is num
              ? Value(Money.toCents(data['discount_amount']))
              : const Value.absent(),
          totalAmount: data['total_amount'] is num
              ? Value(Money.toCents(data['total_amount']))
              : const Value.absent(),
          syncStatus: const Value('synced'),
          payloadJson: Value(jsonEncode(merged)),
        ),
      );
      await (_db.update(_db.localPayments)
            ..where((t) => t.invoiceLocalId.equals(invoiceLocalId)))
          .write(const LocalPaymentsCompanion(syncStatus: Value('synced')));
      await _queue.markSynced(row.id);
    });
  }

  Future<void> _pushSessionOpen(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    final tableId = (payload['table_server_id'] as num?)?.toInt();
    if (tableId == null || tableId <= 0) {
      throw StateError('table_session open requires table_server_id');
    }
    final Map<String, dynamic> data;
    final opener = _postSessionOpen;
    if (opener != null) {
      data = await opener(tableId, row.clientReference);
    } else {
      final api = _api;
      if (api == null) {
        throw StateError('SyncEngineV2 requires API for session open');
      }
      data = await api.post(
        '/tables/$tableId/sessions/open',
        idempotencyKey: row.clientReference,
      );
    }
    await _finalizeSessionOpen(row, data);
  }

  Future<void> _pushSessionClose(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    final table =
        await (_db.select(_db.localTables)..where(
              (t) =>
                  t.localId.equals(row.entityId) &
                  t.workspaceId.equals(row.workspaceId),
            ))
            .getSingleOrNull();
    var tableId = (payload['table_server_id'] as num?)?.toInt();
    if (tableId == null || tableId <= 0) {
      tableId = table?.serverId;
    }
    if (tableId == null || tableId <= 0) {
      throw StateError('table_session close requires table_server_id');
    }
    payload['table_server_id'] = tableId;

    var sessionId = await _resolvedSessionServerId(row, payload, table);
    if (sessionId == null || sessionId <= 0) {
      final getter = _getTable;
      if (getter != null) {
        final remote = await getter(tableId);
        sessionId = (remote['session_id'] as num?)?.toInt();
      } else {
        final api = _api;
        if (api != null) {
          final remote = await api.get('/tables/$tableId');
          sessionId = (remote['session_id'] as num?)?.toInt();
        }
      }
    }
    if (sessionId == null || sessionId <= 0) {
      if (await _hasPendingSessionOpen(row)) {
        throw StateError('table_session close waits for session open sync');
      }
      // Session may already be closed server-side (e.g. auto-open + empty).
      await _markInvoiceSyncedFromClose(row, null);
      await _queue.markSynced(row.id);
      return;
    }

    final closeBody = <String, dynamic>{
      if (payload['payment_method'] != null)
        'payment_method': payload['payment_method'],
    };
    final Map<String, dynamic> data;
    final closer = _postSessionClose;
    if (closer != null) {
      data = await closer(tableId, sessionId, closeBody, row.clientReference);
    } else {
      final api = _api;
      if (api == null) {
        throw StateError('SyncEngineV2 requires API for session close');
      }
      data = await api.post(
        '/tables/$tableId/sessions/$sessionId/close',
        data: closeBody,
        idempotencyKey: row.clientReference,
      );
    }
    final invoice = data['invoice'] is Map
        ? Map<String, dynamic>.from(data['invoice'] as Map)
        : null;
    await _markInvoiceSyncedFromClose(row, invoice);
    await _queue.markSynced(row.id);
  }

  Future<void> _markInvoiceSyncedFromClose(
    SyncQueueItem row,
    Map<String, dynamic>? invoice,
  ) async {
    final payload = _decode(row.payloadJson);
    final invoiceLocalId = '${payload['invoice_local_id'] ?? ''}';
    final paymentLocalId = '${payload['payment_local_id'] ?? ''}';
    final now = DateTime.now();
    if (invoiceLocalId.isNotEmpty && invoice != null) {
      final serverId = (invoice['invoice_id'] as num?)?.toInt() ??
          (invoice['id'] as num?)?.toInt();
      final serverNumber = invoice['invoice_number']?.toString();
      if (serverId != null && serverId > 0) {
        final existing = await (_db.select(
          _db.localInvoices,
        )..where((t) => t.localId.equals(invoiceLocalId))).getSingleOrNull();
        final prev = existing == null
            ? <String, dynamic>{}
            : _decode(existing.payloadJson);
        final merged = {
          ...prev,
          ...invoice,
          'id': serverId,
          'server_id': serverId,
          if (serverNumber != null && serverNumber.isNotEmpty)
            'invoice_number': serverNumber,
          if (serverNumber != null && serverNumber.isNotEmpty)
            'server_invoice_number': serverNumber,
          'sync_status': 'synced',
        };
        await (_db.update(
          _db.localInvoices,
        )..where((t) => t.localId.equals(invoiceLocalId))).write(
          LocalInvoicesCompanion(
            serverId: Value(serverId),
            serverInvoiceNumber: Value(
              (serverNumber != null && serverNumber.isNotEmpty)
                  ? serverNumber
                  : existing?.serverInvoiceNumber,
            ),
            totalAmount: Value(
              invoice['total_amount'] is num
                  ? Money.toCents(invoice['total_amount'])
                  : (existing?.totalAmount ?? 0),
            ),
            syncStatus: const Value('synced'),
            payloadJson: Value(jsonEncode(merged)),
          ),
        );
      }
    }
    if (paymentLocalId.isNotEmpty) {
      await (_db.update(_db.localPayments)
            ..where((t) => t.localId.equals(paymentLocalId)))
          .write(const LocalPaymentsCompanion(syncStatus: Value('synced')));
    }
    // Ensure table is available after successful close sync.
    final table =
        await (_db.select(_db.localTables)..where(
              (t) =>
                  t.localId.equals(row.entityId) &
                  t.workspaceId.equals(row.workspaceId),
            ))
            .getSingleOrNull();
    if (table != null) {
      final prev = _decode(table.payloadJson);
      final next = {
        ...prev,
        'id': table.serverId ?? prev['id'],
        'status': 'available',
        'session_open': false,
        'session_id': null,
        'session_client_id': null,
        'opened_at': null,
        'orders': const [],
        'last_sale_items': const [],
        'last_sale_total': 0,
        'subtotal': 0,
        'tax_amount': 0,
        'discount_amount': 0,
        'total': 0,
      };
      await (_db.update(_db.localTables)..where(
            (t) =>
                t.localId.equals(row.entityId) &
                t.workspaceId.equals(row.workspaceId),
          ))
          .write(
            LocalTablesCompanion(
              status: const Value('available'),
              sessionServerId: const Value(null),
              payloadJson: Value(jsonEncode(next)),
              updatedAt: Value(now),
            ),
          );
    } else {
      await (_db.update(_db.localTables)..where(
            (t) =>
                t.localId.equals(row.entityId) &
                t.workspaceId.equals(row.workspaceId),
          ))
          .write(
            LocalTablesCompanion(
              status: const Value('available'),
              sessionServerId: const Value(null),
              updatedAt: Value(now),
            ),
          );
    }
  }

  Future<void> _pushInvoice(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    var orderServerId = (payload['order_server_id'] as num?)?.toInt();
    final orderLocalId = '${payload['order_local_id'] ?? ''}';
    if ((orderServerId == null || orderServerId <= 0) &&
        orderLocalId.isNotEmpty) {
      final order =
          await (_db.select(_db.localOrders)..where(
                (t) =>
                    t.workspaceId.equals(row.workspaceId) &
                    t.localId.equals(orderLocalId),
              ))
              .getSingleOrNull();
      orderServerId = order?.serverId;
    }
    if (orderServerId == null || orderServerId <= 0) {
      throw StateError('invoice create requires synced order_server_id');
    }
    final Map<String, dynamic> data;
    final poster = _postInvoice;
    if (poster != null) {
      data = await poster(orderServerId, row.clientReference);
    } else {
      final api = _api;
      if (api == null) {
        throw StateError('SyncEngineV2 requires API for invoice push');
      }
      data = await api.post(
        '/orders/$orderServerId/invoice',
        idempotencyKey: row.clientReference,
      );
    }
    await _finalizeInvoice(row, data);
  }

  Future<void> _pushCreate(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    payload['client_reference'] = row.clientReference;
    final data = await _sendCreate(payload, row.clientReference);
    await _finalizeOrderCreate(row, data);
  }

  Future<void> _pushCustomer(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    payload['client_reference'] = row.clientReference;
    final api = _api;
    if (api == null) {
      throw StateError('SyncEngineV2 requires API for customer push');
    }
    final data = await api.post(
      '/customers',
      data: payload,
      idempotencyKey: row.clientReference,
    );
    await _finalizeCustomer(row, data);
  }

  Future<void> _pushUpdate(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    final serverId = (payload['server_order_id'] as num?)?.toInt();
    if (serverId == null || serverId <= 0) {
      throw StateError('update requires server_order_id');
    }
    await _sendUpdate(serverId, payload);
    await _finalizeOrderUpdate(row);
  }

  Future<void> _pushDelete(SyncQueueItem row) async {
    final payload = _decode(row.payloadJson);
    final serverId = (payload['server_order_id'] as num?)?.toInt();
    if (serverId == null || serverId <= 0) {
      throw StateError('delete requires server_order_id');
    }
    await _sendDelete(serverId);
    await _finalizeOrderDelete(row);
  }

  Future<void> _markOrderFailed(String localId, String error) async {
    await (_db.update(
      _db.localOrders,
    )..where((t) => t.localId.equals(localId))).write(
      LocalOrdersCompanion(
        syncStatus: const Value('failed'),
        lastError: Value(error),
        updatedAt: Value(DateTime.now()),
      ),
    );
  }

  Future<void> _markOrderPending(String localId, String error) async {
    await (_db.update(
      _db.localOrders,
    )..where((t) => t.localId.equals(localId))).write(
      LocalOrdersCompanion(
        syncStatus: const Value('pending'),
        lastError: Value(error),
        updatedAt: Value(DateTime.now()),
      ),
    );
  }

  Future<void> _markCustomerFailed(String localId, String error) async {
    await (_db.update(
      _db.localCustomers,
    )..where((t) => t.localId.equals(localId))).write(
      LocalCustomersCompanion(
        syncStatus: const Value('failed'),
        updatedAt: Value(DateTime.now()),
      ),
    );
  }

  Future<void> _markCustomerPending(String localId, String error) async {
    await (_db.update(
      _db.localCustomers,
    )..where((t) => t.localId.equals(localId))).write(
      LocalCustomersCompanion(
        syncStatus: const Value('pending'),
        updatedAt: Value(DateTime.now()),
      ),
    );
  }

  Future<Map<String, dynamic>> _sendCreate(
    Map<String, dynamic> payload,
    String key,
  ) {
    final poster = _postOrder;
    if (poster != null) return poster(payload, key);
    final api = _api;
    if (api == null) {
      throw StateError('SyncEngineV2 requires API or postOrder');
    }
    return api.post('/orders', data: payload, idempotencyKey: key);
  }

  Future<Map<String, dynamic>> _sendUpdate(
    int serverOrderId,
    Map<String, dynamic> payload,
  ) {
    final poster = _postOrderItems;
    if (poster != null) return poster(serverOrderId, payload);
    final api = _api;
    if (api == null) {
      throw StateError('SyncEngineV2 requires API or postOrderItems');
    }
    return api.post('/orders/$serverOrderId/items', data: payload);
  }

  Future<void> _sendDelete(int serverOrderId) async {
    final deleter = _deleteOrder;
    if (deleter != null) {
      await deleter(serverOrderId);
      return;
    }
    final api = _api;
    if (api == null) {
      throw StateError('SyncEngineV2 requires API or deleteOrder');
    }
    await api.delete('/orders/$serverOrderId');
  }

  int? _numericId(dynamic raw) {
    if (raw is num) {
      final value = raw.toInt();
      return value > 0 ? value : null;
    }
    final parsed = int.tryParse('$raw');
    if (parsed == null || parsed <= 0) return null;
    return parsed;
  }

  Map<String, dynamic> _decode(String raw) {
    if (raw.isEmpty) return <String, dynamic>{};
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
    } catch (_) {}
    return <String, dynamic>{};
  }

  Future<Map<String, dynamic>> _loadChanges({
    required int since,
    required int limit,
    String? deviceId,
  }) async {
    final fetcher = _fetchChanges;
    if (fetcher != null) return fetcher(since, limit);
    final puller = _postPull;
    if (puller != null) {
      return puller({
        'device_id': deviceId ?? 'unknown',
        'cursor': since,
        'limit': limit,
      });
    }
    final api = _api;
    if (api == null) {
      throw StateError('SyncEngineV2 requires API or fetchChanges');
    }
    try {
      return await api.post(
        '/sync/pull',
        data: {
          'device_id': deviceId ?? 'unknown',
          'cursor': since,
          'limit': limit,
        },
      );
    } on ApiException catch (e) {
      if (e.statusCode == 404) {
        return api.get(
          '/sync/changes',
          query: {'since': since, 'limit': limit},
        );
      }
      rethrow;
    }
  }
}

class SyncEngineV2Result {
  const SyncEngineV2Result({
    this.synced = 0,
    this.failed = 0,
    this.keptPending = 0,
    this.authRequired = false,
    this.skippedInFlight = false,
    this.pulled = 0,
    this.cursor,
    this.pullFailed = false,
    this.networkError = false,
    this.pullError,
  });

  final int synced;
  final int failed;
  final int keptPending;
  final bool authRequired;
  final bool skippedInFlight;
  final int pulled;
  final int? cursor;
  final bool pullFailed;
  final bool networkError;
  final String? pullError;
}
