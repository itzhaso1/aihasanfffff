import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../local_db/app_database.dart';
import '../pos/domain/pricing_service.dart';
import '../local_db/local_ids.dart';
import '../pos/table_session_orders.dart';
import '../util/occupied_duration.dart';
import '../local_db/workspace_scope.dart';
import '../repositories/sync_conflict_repository.dart';

/// Applies incremental Laravel sync changes inside one SQLite transaction.
/// Cursor advances only after the whole batch commits successfully.
///
/// Strategies:
/// - product / category / table → server authoritative
/// - order → reconcile by client_reference; conflict if local pending diverges
/// - customer → reconcile by client_reference / server_id; conflict if pending
/// - payments / invoices → upsert local cache (server ids); never silent-delete pending
class SyncPullApplier {
  SyncPullApplier(
    this._db, {
    SyncConflictRepository? conflicts,
    String? deviceId,
  })  : _conflicts = conflicts ?? SyncConflictRepository(_db),
        _deviceId = deviceId;

  final AppDatabase _db;
  final SyncConflictRepository _conflicts;
  final String? _deviceId;

  /// Returns the cursor that should be persisted after a successful apply.
  Future<int> applyBatch({
    required int workspaceId,
    required int fromCursor,
    required int responseCursor,
    required List<Map<String, dynamic>> changes,
    String? deviceId,
  }) async {
    if (workspaceId <= 0) {
      throw ArgumentError('workspaceId required');
    }
    final ourDevice = deviceId ?? _deviceId;

    var appliedThrough = fromCursor;
    await _db.transaction(() async {
      for (final change in changes) {
        final version = (change['version'] as num?)?.toInt();
        if (version == null || version <= fromCursor) {
          continue;
        }
        final entity = '${change['entity'] ?? ''}';
        final operation = '${change['operation'] ?? ''}';
        final originDevice = change['origin_device_id']?.toString();
        final data = change['data'] is Map
            ? Map<String, dynamic>.from(change['data'] as Map)
            : <String, dynamic>{};
        final entityId = (change['id'] as num?)?.toInt() ??
            (data['id'] as num?)?.toInt();

        switch (entity) {
          case 'product':
            await _applyProduct(
              workspaceId,
              operation,
              entityId,
              data,
              version: version,
            );
          case 'category':
            await _applyCategory(
              workspaceId,
              operation,
              entityId,
              data,
              version: version,
            );
          case 'table':
            await _applyTable(
              workspaceId,
              operation,
              entityId,
              data,
              version: version,
            );
          case 'order':
            await _applyOrder(
              workspaceId: workspaceId,
              operation: operation,
              serverId: entityId,
              data: data,
              version: version,
              originDeviceId: originDevice,
              ourDeviceId: ourDevice,
            );
          case 'customer':
            await _applyCustomer(
              workspaceId: workspaceId,
              operation: operation,
              serverId: entityId,
              data: data,
              version: version,
              originDeviceId: originDevice,
              ourDeviceId: ourDevice,
            );
          case 'stock':
            await _applyStock(
              workspaceId: workspaceId,
              operation: operation,
              serverId: entityId,
              data: data,
              deviceId: ourDevice,
            );
          case 'payment':
          case 'invoice':
            await _applyInvoiceOrPayment(
              workspaceId: workspaceId,
              entity: entity,
              operation: operation,
              serverId: entityId,
              data: data,
              version: version,
              ourDeviceId: ourDevice,
            );
          default:
            // Forward-compatible: ignore unknown entities without failing sync.
            break;
        }
        appliedThrough = version;
      }

      final cursorToStore =
          changes.isEmpty ? responseCursor : appliedThrough;
      if (cursorToStore < fromCursor) {
        throw StateError('refusing to move cursor backwards');
      }
      await _db.writeCursor(
        workspaceId,
        '$cursorToStore',
        deviceId: ourDevice,
      );
    });

    final stored = await _db.readCursor(workspaceId);
    return int.tryParse(stored ?? '') ?? fromCursor;
  }

  Future<void> _applyProduct(
    int workspaceId,
    String operation,
    int? serverId,
    Map<String, dynamic> data, {
    int? version,
  }) async {
    if (serverId == null || serverId <= 0) return;
    final existing = await (_db.select(_db.localProducts)..where(
          (t) =>
              t.workspaceId.equals(workspaceId) & t.serverId.equals(serverId),
        ))
        .getSingleOrNull();
    if (_isStaleVersion(version, existing?.serverVersion)) return;
    final localId = existing?.localId ?? LocalIds.product(workspaceId, serverId);
    if (operation == 'delete') {
      await (_db.update(_db.localProducts)
            ..where((t) =>
                t.workspaceId.equals(workspaceId) &
                (t.localId.equals(localId) | t.serverId.equals(serverId))))
          .write(
        LocalProductsCompanion(
          isDeleted: const Value(true),
          isActive: const Value(false),
          updatedAt: Value(DateTime.now()),
          serverVersion:
              version == null ? const Value.absent() : Value(version),
        ),
      );
      return;
    }

    final catServerId = (data['pos_item_category_id'] as num?)?.toInt();
    String? categoryLocalId = existing?.categoryLocalId;
    if (catServerId != null) {
      final category = await (_db.select(_db.localCategories)..where(
            (t) =>
                t.workspaceId.equals(workspaceId) &
                t.serverId.equals(catServerId),
          ))
          .getSingleOrNull();
      categoryLocalId =
          category?.localId ?? LocalIds.category(workspaceId, catServerId);
    }
    final now = DateTime.now();
    if (existing != null) {
      await (_db.update(_db.localProducts)
            ..where((t) => t.localId.equals(existing.localId)))
          .write(
        LocalProductsCompanion(
          serverId: Value(serverId),
          categoryLocalId: Value(categoryLocalId),
          categoryServerId: Value(catServerId),
          name: Value('${data['name'] ?? existing.name}'),
          sku: Value(data['sku'] as String? ?? existing.sku),
          barcode: Value(data['barcode'] as String? ?? existing.barcode),
          itemType: Value(data['item_type'] as String? ?? existing.itemType),
          price: Value(Money.toCents((data['price'] as num?) ?? 0)),
          isActive: Value(data['is_active'] != false),
          isDeleted: const Value(false),
          payloadJson: Value(jsonEncode({...data, 'id': serverId})),
          stock: Value((data['stock'] as num?)?.toInt() ?? existing.stock),
          updatedAt: Value(now),
          serverVersion: Value(version ?? (data['version'] as num?)?.toInt()),
        ),
      );
      return;
    }
    await _db.into(_db.localProducts).insertOnConflictUpdate(
          LocalProductsCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            serverId: Value(serverId),
            categoryLocalId: Value(categoryLocalId),
            categoryServerId: Value(catServerId),
            name: '${data['name'] ?? existing?.name ?? ''}',
            sku: Value(data['sku'] as String? ?? existing?.sku),
            barcode: Value(data['barcode'] as String? ?? existing?.barcode),
            itemType: Value(data['item_type'] as String? ?? existing?.itemType),
            price: Value(Money.toCents((data['price'] as num?) ?? 0)),
            isActive: Value(data['is_active'] != false),
            isDeleted: const Value(false),
            payloadJson: Value(jsonEncode({...data, 'id': serverId})),
            stock: Value((data['stock'] as num?)?.toInt() ?? existing?.stock),
            updatedAt: now,
            serverVersion: Value(version ?? (data['version'] as num?)?.toInt()),
          ),
        );
  }

  Future<void> _applyCategory(
    int workspaceId,
    String operation,
    int? serverId,
    Map<String, dynamic> data, {
    int? version,
  }) async {
    if (serverId == null || serverId <= 0) return;
    final existing = await (_db.select(_db.localCategories)..where(
          (t) =>
              t.workspaceId.equals(workspaceId) & t.serverId.equals(serverId),
        ))
        .getSingleOrNull();
    if (_isStaleVersion(version, existing?.serverVersion)) return;
    final localId =
        existing?.localId ?? LocalIds.category(workspaceId, serverId);
    final versionValue =
        version == null ? const Value<int?>.absent() : Value<int?>(version);
    if (operation == 'delete') {
      await (_db.update(_db.localCategories)
            ..where((t) =>
                t.workspaceId.equals(workspaceId) &
                (t.localId.equals(localId) | t.serverId.equals(serverId))))
          .write(
        LocalCategoriesCompanion(
          isDeleted: const Value(true),
          isActive: const Value(false),
          updatedAt: Value(DateTime.now()),
          serverVersion: versionValue,
        ),
      );
      return;
    }

    if (existing != null) {
      await (_db.update(_db.localCategories)
            ..where((t) => t.localId.equals(existing.localId)))
          .write(
        LocalCategoriesCompanion(
          serverId: Value(serverId),
          name: Value('${data['name'] ?? existing.name}'),
          sortOrder: Value((data['sort_order'] as num?)?.toInt() ??
              existing.sortOrder),
          isActive: Value(data['is_active'] != false),
          isDeleted: const Value(false),
          updatedAt: Value(DateTime.now()),
          serverVersion: versionValue,
        ),
      );
      return;
    }

    await _db.into(_db.localCategories).insertOnConflictUpdate(
          LocalCategoriesCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            serverId: Value(serverId),
            name: '${data['name'] ?? existing?.name ?? ''}',
            sortOrder: Value((data['sort_order'] as num?)?.toInt() ??
                existing?.sortOrder ??
                0),
            isActive: Value(data['is_active'] != false),
            isDeleted: const Value(false),
            updatedAt: DateTime.now(),
            serverVersion: versionValue,
          ),
        );
  }

  Future<void> _applyTable(
    int workspaceId,
    String operation,
    int? serverId,
    Map<String, dynamic> data, {
    int? version,
  }) async {
    if (serverId == null || serverId <= 0) return;
    final existing = await _findLocalTable(
      workspaceId: workspaceId,
      serverId: serverId,
    );
    // Re-delivered history (cursor rollback / replay from 0) must be a no-op:
    // the row already reflects a newer or equal server version.
    if (_isStaleVersion(version, existing?.serverVersion)) return;
    final localId = existing?.localId ?? LocalIds.table(workspaceId, serverId);
    if (operation == 'delete') {
      await (_db.delete(_db.localTables)
            ..where((t) =>
                t.workspaceId.equals(workspaceId) &
                (t.localId.equals(localId) | t.serverId.equals(serverId))))
          .go();
      return;
    }

    Map<String, dynamic> payload = {};
    if (existing != null) {
      try {
        final decoded = jsonDecode(existing.payloadJson);
        if (decoded is Map) payload = Map<String, dynamic>.from(decoded);
      } catch (_) {}
    }
    final localClient = '${payload['session_client_id'] ?? ''}'.trim();
    final localOpenedAt = payload['opened_at'];
    final remoteSessionId = data.containsKey('session_id')
        ? (data['session_id'] as num?)?.toInt()
        : null;
    final remoteSessionOpen = data['session_open'] == true ||
        (remoteSessionId != null && remoteSessionId > 0);
    final pendingLocalClose = existing != null &&
        await _hasPendingSessionClose(workspaceId, existing.localId);
    final pendingLocalOpen = existing != null &&
        await _hasPendingSessionOpen(workspaceId, existing.localId);
    // A local sitting Laravel has not acknowledged yet (open still queued, or
    // no server session id) must survive a pull that says "available" —
    // otherwise offline opens get clobbered. Once the server knows the
    // sitting (session_server_id set, open drained), Laravel is authoritative:
    // "no open session" means it was closed there and must close here too.
    final localOpenUnacked = existing != null &&
        (pendingLocalOpen ||
            existing.sessionServerId == null ||
            existing.sessionServerId! <= 0);
    // Laravel can keep status=available while a TableSession is still open
    // (QR guest visit with no billable order yet). Occupancy follows the
    // session, not the dining-table status flag alone.
    final preserveLocalOpen = existing != null &&
        existing.status == 'occupied' &&
        localClient.isNotEmpty &&
        localOpenUnacked &&
        !remoteSessionOpen &&
        !pendingLocalClose &&
        (data['status'] == 'available' ||
            data['session_open'] == false ||
            data['session_id'] == null);
    payload.addAll(data);
    payload['id'] = serverId;
    final status = '${data['status'] ?? existing?.status ?? 'available'}';
    final available = pendingLocalClose ||
        (!preserveLocalOpen &&
            !remoteSessionOpen &&
            (status == 'available' ||
                status == 'closed' ||
                data['session_open'] == false));
    int? sessionServerId = existing?.sessionServerId;
    if (data.containsKey('session_id')) {
      sessionServerId = remoteSessionId;
    }
    if (available) {
      sessionServerId = null;
      payload['status'] = 'available';
      payload['session_open'] = false;
      payload['session_id'] = null;
      payload['session_client_id'] = null;
      payload['opened_at'] = null;
      payload['orders'] = const [];
    } else {
      payload['status'] = (preserveLocalOpen || remoteSessionOpen)
          ? 'occupied'
          : status;
      if (preserveLocalOpen) {
        payload['session_client_id'] = localClient;
        payload['session_open'] = true;
        if (payload['opened_at'] == null) {
          payload['opened_at'] = localOpenedAt;
        }
      }
      if (sessionServerId != null && sessionServerId > 0) {
        payload['session_id'] = sessionServerId;
        payload['session_open'] = true;
      }
    }
    final nextStatus = available
        ? 'available'
        : ((preserveLocalOpen || remoteSessionOpen) ? 'occupied' : status);
    if (existing != null) {
      await (_db.update(_db.localTables)
            ..where((t) => t.localId.equals(existing.localId)))
          .write(
        LocalTablesCompanion(
          serverId: Value(serverId),
          name: Value('${data['name'] ?? existing.name}'),
          status: Value(nextStatus),
          capacity: Value(
            (data['capacity'] as num?)?.toInt() ?? existing.capacity,
          ),
          sessionServerId: Value(sessionServerId),
          payloadJson: Value(jsonEncode(payload)),
          updatedAt: Value(DateTime.now()),
          serverVersion:
              version == null ? const Value.absent() : Value(version),
        ),
      );
      if (available) {
        await _closeOpenLocalSessions(existing.localId, DateTime.now());
      } else if (sessionServerId != null && sessionServerId > 0) {
        await _ensureServerTableSession(
          workspaceId: workspaceId,
          tableLocalId: existing.localId,
          serverSessionId: sessionServerId,
          openedAtRaw: data['opened_at'] ?? payload['opened_at'],
          currentSessionLocalId: localClient,
        );
      }
      return;
    }
    await _db.into(_db.localTables).insertOnConflictUpdate(
          LocalTablesCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            serverId: Value(serverId),
            name: '${data['name'] ?? existing?.name ?? ''}',
            status: Value(nextStatus),
            capacity: Value(
              (data['capacity'] as num?)?.toInt() ?? existing?.capacity,
            ),
            sessionServerId: Value(sessionServerId),
            payloadJson: Value(jsonEncode(payload)),
            updatedAt: DateTime.now(),
            serverVersion: Value(version),
          ),
        );
    if (!available && sessionServerId != null && sessionServerId > 0) {
      await _ensureServerTableSession(
        workspaceId: workspaceId,
        tableLocalId: localId,
        serverSessionId: sessionServerId,
        openedAtRaw: data['opened_at'],
      );
    }
  }

  Future<void> _applyOrder({
    required int workspaceId,
    required String operation,
    required int? serverId,
    required Map<String, dynamic> data,
    required int version,
    String? originDeviceId,
    String? ourDeviceId,
  }) async {
    var clientRef = '${data['client_reference'] ?? ''}'.trim();
    if (clientRef.toLowerCase() == 'null') {
      clientRef = '';
    }
    final local = await _findLocalOrder(
      workspaceId: workspaceId,
      clientRef: clientRef,
      serverId: serverId,
    );
    if (_isStaleVersion(version, local?.serverVersion)) return;

    final isEcho = ourDeviceId != null &&
        originDeviceId != null &&
        originDeviceId == ourDeviceId;

    if (operation == 'delete') {
      if (local == null) return;
      if (_hasOpenLocalWork(local) && !isEcho) {
        await _conflicts.record(
          workspaceId: workspaceId,
          entityType: 'order',
          entityId: local.localId,
          strategy: 'keep_local_pending',
          reason: 'server_delete_vs_local_pending',
          local: _orderSnapshot(local),
          server: data,
          deviceId: ourDeviceId,
          operation: operation,
          serverVersion: version,
        );
        return;
      }
      await (_db.update(_db.localOrders)
            ..where((t) =>
                t.workspaceId.equals(workspaceId) &
                t.localId.equals(local!.localId)))
          .write(
        LocalOrdersCompanion(
          posStatus: const Value('cancelled'),
          syncStatus: const Value('synced'),
          serverId: Value(serverId ?? local.serverId),
          serverVersion: Value(version),
          updatedAt: Value(DateTime.now()),
          syncedAt: Value(DateTime.now()),
        ),
      );
      return;
    }

    if (local != null && _hasOpenLocalWork(local) && !isEcho) {
      // Do not Last-Write-Wins over pending local order work.
      await _conflicts.record(
        workspaceId: workspaceId,
        entityType: 'order',
        entityId: local.localId,
        strategy: 'keep_local_pending',
        reason: 'concurrent_order_edit',
        local: _orderSnapshot(local),
        server: data,
        deviceId: ourDeviceId,
        operation: operation,
        serverVersion: version,
      );
      // Still bind server_id for reconciliation without overwriting items.
      if (serverId != null && local.serverId == null) {
        await (_db.update(_db.localOrders)
              ..where((t) => t.localId.equals(local!.localId)))
            .write(
          LocalOrdersCompanion(
            serverId: Value(serverId),
            updatedAt: Value(DateTime.now()),
          ),
        );
      }
      return;
    }

    final localId = local?.localId ??
        (clientRef.isNotEmpty ? clientRef : 'w${workspaceId}_ord_${serverId ?? const Uuid().v4()}');
    final tableServerId = (data['dining_table_id'] as num?)?.toInt();
    final now = DateTime.now();
    final tableLocalId = await _ensureKitchenTable(
      workspaceId: workspaceId,
      tableServerId: tableServerId,
      tableName: '${data['table_name'] ?? ''}',
      now: now,
    );
    final sessionHint = '${data['session_local_id'] ?? ''}'.trim();
    final tableSessionId = (data['table_session_id'] as num?)?.toInt();
    final sessionLocalId = await _resolvePulledOrderSession(
      workspaceId: workspaceId,
      tableLocalId: tableLocalId,
      tableSessionId: tableSessionId,
      sessionHint: sessionHint,
      fallbackSessionLocalId: local?.sessionLocalId,
      openedAtRaw: data['placed_at'] ?? data['created_at'],
    );
    await _db.into(_db.localOrders).insertOnConflictUpdate(
          LocalOrdersCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            deviceId: local?.deviceId ?? ourDeviceId ?? 'server',
            serverId: Value(serverId),
            clientReference:
                clientRef.isNotEmpty ? clientRef : localId,
            orderType: '${data['order_type'] ?? local?.orderType ?? 'table'}',
            tableServerId: Value(tableServerId),
            tableLocalId: Value(
              await _db.existingFk(
                'local_tables',
                'local_id',
                tableLocalId,
              ),
            ),
            sessionLocalId: Value(
              await _db.existingFk(
                'local_sessions',
                'local_id',
                sessionLocalId,
              ),
            ),
            notes: Value(data['notes'] as String?),
            subtotal: Value(Money.toCents((data['subtotal'] as num?) ?? 0)),
            taxAmount: Value(Money.toCents((data['tax_amount'] as num?) ?? 0)),
            discountAmount:
                Value(Money.toCents((data['discount_amount'] as num?) ?? 0)),
            totalAmount:
                Value(Money.toCents((data['total_amount'] as num?) ?? 0)),
            posStatus: Value(_kitchenPosStatus(local: local, data: data)),
            paymentStatus: Value(_kitchenPaymentStatus(data['payment_status'])),
            syncStatus: const Value('synced'),
            createdAt: local?.createdAt ??
                parseOpenedAt(data['placed_at']) ??
                now,
            updatedAt: now,
            syncedAt: Value(now),
            serverVersion: Value(version),
          ),
        );

    await (_db.delete(_db.localOrderItems)
          ..where((t) =>
              t.orderLocalId.equals(localId) &
              t.workspaceId.equals(workspaceId)))
        .go();

    final items = data['items'] is List ? data['items'] as List : const [];
    for (final raw in items) {
      if (raw is! Map) continue;
      final item = Map<String, dynamic>.from(raw);
      final itemServerId = (item['id'] as num?)?.toInt();
      final productServerId = (item['pos_menu_item_id'] as num?)?.toInt();
      final itemLocalId = itemServerId != null
          ? 'w${workspaceId}_oi_$itemServerId'
          : const Uuid().v4();
      await _db.into(_db.localOrderItems).insert(
            LocalOrderItemsCompanion.insert(
              localId: itemLocalId,
              workspaceId: workspaceId,
              orderLocalId: localId,
              serverId: Value(itemServerId),
              productServerId: Value(productServerId),
              productLocalId: Value(
                await _db.existingFk(
                  'local_products',
                  'local_id',
                  productServerId == null
                      ? null
                      : LocalIds.product(workspaceId, productServerId),
                ),
              ),
              name: '${item['product_name'] ?? item['name'] ?? 'صنف'}',
              quantity: (item['quantity'] as num?)?.toInt() ?? 0,
              unitPrice: Money.toCents((item['unit_price'] as num?) ?? 0),
              discountAmount: Value(
                Money.toCents((item['discount_amount'] as num?) ?? 0),
              ),
              totalAmount: Money.toCents((item['total_amount'] as num?) ?? 0),
              notes: Value(
                '${item['notes'] ?? ''}'.trim().isEmpty
                    ? null
                    : '${item['notes']}'.trim(),
              ),
              updatedAt: now,
            ),
          );
    }
    await _attachPulledOrderToTable(
      workspaceId: workspaceId,
      tableLocalId: tableLocalId,
      tableServerId: tableServerId,
      sessionLocalId: sessionLocalId,
      tableSessionId: tableSessionId,
      orderLocalId: localId,
      data: data,
      now: now,
    );
  }

  Future<void> _applyCustomer({
    required int workspaceId,
    required String operation,
    required int? serverId,
    required Map<String, dynamic> data,
    required int version,
    String? originDeviceId,
    String? ourDeviceId,
  }) async {
    final clientRef = '${data['client_reference'] ?? ''}'.trim();
    LocalCustomer? local;
    if (clientRef.isNotEmpty) {
      local = await (_db.select(_db.localCustomers)
            ..where((t) =>
                t.workspaceId.equals(workspaceId) &
                t.localId.equals(clientRef)))
          .getSingleOrNull();
    }
    local ??= serverId == null
        ? null
        : await (_db.select(_db.localCustomers)
              ..where((t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.serverId.equals(serverId)))
            .getSingleOrNull();

    final isEcho = ourDeviceId != null &&
        originDeviceId != null &&
        originDeviceId == ourDeviceId;

    if (operation == 'delete') {
      if (local == null) return;
      await (_db.delete(_db.localCustomers)
            ..where((t) => t.localId.equals(local!.localId)))
          .go();
      return;
    }

    if (local != null &&
        (local.syncStatus == 'pending' || local.syncStatus == 'failed') &&
        !isEcho) {
      await _conflicts.record(
        workspaceId: workspaceId,
        entityType: 'customer',
        entityId: local.localId,
        strategy: 'keep_local_pending',
        reason: 'concurrent_customer_edit',
        local: {
          'name': local.name,
          'phone': local.phone,
          'server_id': local.serverId,
        },
        server: data,
        deviceId: ourDeviceId,
        operation: operation,
        serverVersion: version,
      );
      if (serverId != null && local.serverId == null) {
        await (_db.update(_db.localCustomers)
              ..where((t) => t.localId.equals(local!.localId)))
            .write(LocalCustomersCompanion(serverId: Value(serverId)));
      }
      return;
    }

    final localId = local?.localId ??
        (clientRef.isNotEmpty
            ? clientRef
            : LocalIds.customer(workspaceId, serverId ?? 0));
    await _db.into(_db.localCustomers).insertOnConflictUpdate(
          LocalCustomersCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            serverId: Value(serverId),
            name: '${data['name'] ?? ''}',
            phone: Value(data['phone'] as String?),
            payloadJson: Value(jsonEncode({...data, 'id': serverId})),
            updatedAt: DateTime.now(),
            syncStatus: const Value('synced'),
          ),
        );
  }

  bool _hasOpenLocalWork(LocalOrder order) {
    return order.syncStatus == 'pending' ||
        order.syncStatus == 'syncing' ||
        order.syncStatus == 'failed';
  }

  Future<LocalOrder?> _findLocalOrder({
    required int workspaceId,
    required String clientRef,
    required int? serverId,
  }) async {
    if (clientRef.isNotEmpty) {
      final matches = await (_db.select(_db.localOrders)
            ..where((t) =>
                t.workspaceId.equals(workspaceId) &
                t.clientReference.equals(clientRef)))
          .get();
      if (matches.length == 1) return matches.first;
      if (matches.isNotEmpty && serverId != null) {
        for (final row in matches) {
          if (row.serverId == serverId) return row;
        }
      }
      if (matches.isNotEmpty) return matches.first;
    }
    if (serverId == null) return null;
    final byServer = await (_db.select(_db.localOrders)
          ..where((t) =>
              t.workspaceId.equals(workspaceId) & t.serverId.equals(serverId)))
        .get();
    return byServer.isEmpty ? null : byServer.first;
  }

  Future<LocalTable?> _findLocalTable({
    required int workspaceId,
    required int serverId,
  }) async {
    final matches = await (_db.select(_db.localTables)
          ..where((t) =>
              t.workspaceId.equals(workspaceId) & t.serverId.equals(serverId)))
        .get();
    return matches.isEmpty ? null : matches.first;
  }

  /// Invoice close sets Laravel `pos_status=completed`. That means the sale
  /// was invoiced, not that the kitchen finished cooking. Keep takeaway /
  /// delivery tickets on the board until this kitchen device marks them done.
  /// Table tickets follow the remote status so a closed sitting leaves the board.
  String _kitchenPosStatus({
    required LocalOrder? local,
    required Map<String, dynamic> data,
  }) {
    final remote =
        '${data['pos_status'] ?? local?.posStatus ?? 'new'}'.trim().toLowerCase();
    final type =
        '${data['order_type'] ?? local?.orderType ?? ''}'.trim().toLowerCase();
    const known = {
      'new',
      'accepted',
      'preparing',
      'ready',
      'delivered',
      'completed',
      'cancelled',
    };
    if (remote == 'cancelled') return 'cancelled';
    final invoiceClosed =
        remote == 'completed' && (type == 'takeaway' || type == 'delivery');
    if (invoiceClosed) {
      final localStatus = local?.posStatus.trim().toLowerCase() ?? '';
      if (localStatus == 'cancelled') return 'cancelled';
      if (localStatus == 'completed' || localStatus == 'delivered') {
        return localStatus;
      }
      if (localStatus.isNotEmpty) return local!.posStatus;
      return 'new';
    }
    if (remote.isEmpty || !known.contains(remote)) {
      final localStatus = local?.posStatus.trim() ?? '';
      return localStatus.isEmpty ? 'new' : localStatus;
    }
    return remote;
  }

  String _kitchenPaymentStatus(Object? raw) {
    final value = '$raw'.trim().toLowerCase();
    if (value.isEmpty || value == 'null') return 'unpaid';
    if (value == 'pending') return 'unpaid';
    return value;
  }

  /// Kitchen devices may not have table-master yet. Seed a name-only stub so
  /// tickets can show "طاولة 5" without opening a live sitting.
  Future<String?> _ensureKitchenTable({
    required int workspaceId,
    required int? tableServerId,
    required String tableName,
    required DateTime now,
  }) async {
    if (tableServerId == null || tableServerId <= 0) return null;
    final scopedId = LocalIds.table(workspaceId, tableServerId);
    final byScoped = await (_db.select(_db.localTables)
          ..where((t) => t.localId.equals(scopedId)))
        .get();
    if (byScoped.isNotEmpty) return byScoped.first.localId;

    final byServer = await _findLocalTable(
      workspaceId: workspaceId,
      serverId: tableServerId,
    );
    if (byServer != null) return byServer.localId;

    final name = tableName.trim().isEmpty ? 'طاولة' : tableName.trim();
    await _db.into(_db.localTables).insertOnConflictUpdate(
          LocalTablesCompanion.insert(
            localId: scopedId,
            workspaceId: workspaceId,
            serverId: Value(tableServerId),
            name: name,
            updatedAt: now,
          ),
        );
    return scopedId;
  }

  Map<String, dynamic> _orderSnapshot(LocalOrder order) {
    return {
      'local_id': order.localId,
      'client_reference': order.clientReference,
      'server_id': order.serverId,
      'sync_status': order.syncStatus,
      'total_amount': order.totalAmount,
      'pos_status': order.posStatus,
      'updated_at': order.updatedAt.toIso8601String(),
    };
  }

  Future<void> _applyInvoiceOrPayment({
    required int workspaceId,
    required String entity,
    required String operation,
    required int? serverId,
    required Map<String, dynamic> data,
    required int version,
    required String? ourDeviceId,
  }) async {
    if (operation == 'delete' || serverId == null) return;
    if (entity == 'invoice') {
      final existing = await (_db.select(_db.localInvoices)
            ..where((t) =>
                t.workspaceId.equals(workspaceId) & t.serverId.equals(serverId)))
          .getSingleOrNull();
      final pendingLocal = await (_db.select(_db.localInvoices)
            ..where((t) =>
                t.workspaceId.equals(workspaceId) &
                t.syncStatus.equals('pending')))
          .get();
      // If we have a divergent pending local invoice, record conflict — no LWW.
      if (pendingLocal.isNotEmpty && existing == null) {
        await _conflicts.record(
          workspaceId: workspaceId,
          entityType: 'invoice',
          entityId: '$serverId',
          strategy: 'detect_and_record',
          reason: 'local_pending_invoice_vs_server',
          local: {
            'pending_count': pendingLocal.length,
            'local_ids': [for (final p in pendingLocal) p.localId],
          },
          server: data,
          deviceId: ourDeviceId,
          operation: operation,
          serverVersion: version,
        );
      }
      final invoiceLocalId =
          existing?.localId ?? 'w${workspaceId}_inv_$serverId';
      await _db.into(_db.localInvoices).insertOnConflictUpdate(
            LocalInvoicesCompanion.insert(
              localId: invoiceLocalId,
              workspaceId: workspaceId,
              deviceId: ourDeviceId ?? 'server',
              serverId: Value(serverId),
              invoiceNumber: Value(data['invoice_number']?.toString()),
              totalAmount: Value(
                Money.toCents((data['total_amount'] as num?) ?? 0),
              ),
              syncStatus: const Value('synced'),
              payloadJson: Value(jsonEncode({...data, 'id': serverId})),
              createdAt: existing?.createdAt ?? DateTime.now(),
            ),
          );
      return;
    }
    // payment: informational cache only
    if (serverId <= 0) return;
    final paymentLocalId = 'w${workspaceId}_pay_$serverId';
    await _db.into(_db.localPayments).insertOnConflictUpdate(
          LocalPaymentsCompanion.insert(
            localId: paymentLocalId,
            workspaceId: workspaceId,
            deviceId: ourDeviceId ?? 'server',
            serverId: Value(serverId),
            method: '${data['method'] ?? data['payment_method'] ?? 'cash'}',
            amount: Money.toCents(
              (data['amount'] as num?) ??
                  (data['total_amount'] as num?) ??
                  0,
            ),
            syncStatus: const Value('synced'),
            clientReference: '${data['client_reference'] ?? paymentLocalId}',
            createdAt: DateTime.now(),
          ),
        );
  }

  Future<void> _applyStock({
    required int workspaceId,
    required String operation,
    required int? serverId,
    required Map<String, dynamic> data,
    String? deviceId,
  }) async {
    if (operation == 'delete') return;
    final movementId = serverId ?? (data['id'] as num?)?.toInt();
    final localId = movementId != null
        ? 'w${workspaceId}_stock_$movementId'
        : 'w${workspaceId}_stock_${const Uuid().v4()}';
    final catalogProductId = (data['product_id'] as num?)?.toInt();
    final after = (data['after_quantity'] as num?)?.toInt();
    await _db.into(_db.localStockMovements).insertOnConflictUpdate(
          LocalStockMovementsCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            deviceId: deviceId ?? 'server',
            catalogProductId: Value(catalogProductId),
            kind: '${data['type'] ?? data['kind'] ?? 'adjustment'}',
            quantity: (data['quantity'] as num?)?.toInt() ?? 0,
            referenceType: Value(data['reference_type']?.toString()),
            referenceId: Value(data['reference_id']?.toString()),
            syncStatus: const Value('synced'),
            clientReference: localId,
            payloadJson: Value(jsonEncode(data)),
            createdAt: DateTime.now(),
          ),
        );
    if (catalogProductId == null || after == null) return;
    final products = await (_db.select(_db.localProducts)
          ..where((t) => t.workspaceId.equals(workspaceId)))
        .get();
    for (final product in products) {
      var linked = false;
      try {
        final payload = jsonDecode(product.payloadJson);
        if (payload is Map &&
            (payload['product_id'] as num?)?.toInt() == catalogProductId) {
          linked = true;
        }
      } catch (_) {}
      if (!linked) continue;
      await (_db.update(_db.localProducts)
            ..where((t) => t.localId.equals(product.localId)))
          .write(
        LocalProductsCompanion(
          stock: Value(after),
          updatedAt: Value(DateTime.now()),
        ),
      );
    }
  }

  Future<String?> _resolvePulledOrderSession({
    required int workspaceId,
    required String? tableLocalId,
    required int? tableSessionId,
    required String sessionHint,
    String? fallbackSessionLocalId,
    Object? openedAtRaw,
  }) async {
    if (sessionHint.isNotEmpty) {
      final hinted = await _db.existingFk(
        'local_sessions',
        'local_id',
        sessionHint,
      );
      if (hinted != null) return hinted;
    }
    if (fallbackSessionLocalId != null &&
        fallbackSessionLocalId.trim().isNotEmpty) {
      final existing = await _db.existingFk(
        'local_sessions',
        'local_id',
        fallbackSessionLocalId,
      );
      if (existing != null) return existing;
    }
    if (tableLocalId == null || tableLocalId.isEmpty) return null;
    if (tableSessionId != null && tableSessionId > 0) {
      return _ensureServerTableSession(
        workspaceId: workspaceId,
        tableLocalId: tableLocalId,
        serverSessionId: tableSessionId,
        openedAtRaw: openedAtRaw,
      );
    }
    return _openSessionLocalId(tableLocalId);
  }

  Future<String> _ensureServerTableSession({
    required int workspaceId,
    required String tableLocalId,
    required int serverSessionId,
    Object? openedAtRaw,
    String? currentSessionLocalId,
  }) async {
    // 1. A local row already bound to this server sitting (open ACK or an
    //    earlier pull) is the identity — never mint a second one for it.
    final bound = await _sessionByServerId(workspaceId, serverSessionId);
    if (bound != null) {
      await _upsertOpenLocalSession(
        sessionLocalId: bound.localId,
        workspaceId: workspaceId,
        tableLocalId: bound.tableLocalId,
        openedAtRaw: openedAtRaw,
        serverId: serverSessionId,
      );
      return bound.localId;
    }
    // 2. Adopt the sitting this device already has open (offline open that
    //    Laravel unified into this server session).
    final current = (currentSessionLocalId ?? '').trim();
    if (current.isNotEmpty) {
      await _upsertOpenLocalSession(
        sessionLocalId: current,
        workspaceId: workspaceId,
        tableLocalId: tableLocalId,
        openedAtRaw: openedAtRaw,
        serverId: serverSessionId,
      );
      return current;
    }
    final open = await _openSessionLocalId(tableLocalId);
    if (open != null && open.isNotEmpty) {
      await _upsertOpenLocalSession(
        sessionLocalId: open,
        workspaceId: workspaceId,
        tableLocalId: tableLocalId,
        openedAtRaw: openedAtRaw,
        serverId: serverSessionId,
      );
      return open;
    }
    // 3. Server-originated sitting (QR / web): deterministic local id.
    final generated = LocalIds.session(workspaceId, serverSessionId);
    await _upsertOpenLocalSession(
      sessionLocalId: generated,
      workspaceId: workspaceId,
      tableLocalId: tableLocalId,
      openedAtRaw: openedAtRaw,
      serverId: serverSessionId,
    );
    return generated;
  }

  Future<LocalSession?> _sessionByServerId(int workspaceId, int serverId) {
    return (_db.select(_db.localSessions)
          ..where(
            (t) =>
                t.workspaceId.equals(workspaceId) & t.serverId.equals(serverId),
          )
          ..orderBy([(t) => OrderingTerm.desc(t.updatedAt)])
          ..limit(1))
        .getSingleOrNull();
  }

  Future<void> _upsertOpenLocalSession({
    required String sessionLocalId,
    required int workspaceId,
    required String tableLocalId,
    Object? openedAtRaw,
    int? serverId,
  }) async {
    final id = sessionLocalId.trim();
    if (id.isEmpty || tableLocalId.trim().isEmpty) return;
    final now = DateTime.now();
    final openedAt = parseOpenedAt(openedAtRaw) ?? now;
    final existing = await (_db.select(
      _db.localSessions,
    )..where((t) => t.localId.equals(id))).getSingleOrNull();
    final serverIdValue = serverId == null || serverId <= 0
        ? const Value<int?>.absent()
        : Value<int?>(serverId);
    if (existing != null) {
      if (existing.status == 'open') {
        if (serverIdValue.present && existing.serverId != serverId) {
          await (_db.update(
            _db.localSessions,
          )..where((t) => t.localId.equals(id))).write(
            LocalSessionsCompanion(
              serverId: serverIdValue,
              updatedAt: Value(now),
            ),
          );
        }
        return;
      }
      await (_db.update(
        _db.localSessions,
      )..where((t) => t.localId.equals(id))).write(
        LocalSessionsCompanion(
          status: const Value('open'),
          openedAt: Value(openedAt),
          closedAt: const Value(null),
          serverId: serverIdValue,
          updatedAt: Value(now),
        ),
      );
      return;
    }
    await _db.into(_db.localSessions).insert(
          LocalSessionsCompanion.insert(
            localId: id,
            workspaceId: workspaceId,
            tableLocalId: tableLocalId,
            serverId: serverIdValue,
            status: const Value('open'),
            openedAt: openedAt,
            createdAt: now,
            updatedAt: now,
          ),
        );
  }

  /// True when [incoming] is at or below the version already applied to the
  /// row. Rows that never recorded a version (snapshot / legacy) always
  /// accept the change.
  bool _isStaleVersion(int? incoming, int? applied) {
    if (incoming == null || applied == null) return false;
    return incoming <= applied;
  }

  Future<bool> _hasPendingSessionClose(
    int workspaceId,
    String tableLocalId,
  ) =>
      _hasPendingSessionOp(workspaceId, tableLocalId, 'close');

  Future<bool> _hasPendingSessionOpen(
    int workspaceId,
    String tableLocalId,
  ) =>
      _hasPendingSessionOp(workspaceId, tableLocalId, 'open');

  Future<bool> _hasPendingSessionOp(
    int workspaceId,
    String tableLocalId,
    String operation,
  ) async {
    final rows = await (_db.select(_db.syncQueueItems)
          ..where(
            (t) =>
                t.workspaceId.equals(workspaceId) &
                t.entityType.equals('table_session') &
                t.entityId.equals(tableLocalId) &
                t.operation.equals(operation) &
                (t.status.equals('pending') |
                    t.status.equals('failed') |
                    t.status.equals('syncing')),
          )
          ..limit(1))
        .get();
    return rows.isNotEmpty;
  }

  Future<void> _closeOpenLocalSessions(String tableLocalId, DateTime now) {
    return (_db.update(_db.localSessions)..where(
          (t) => t.tableLocalId.equals(tableLocalId) & t.status.equals('open'),
        ))
        .write(
          LocalSessionsCompanion(
            status: const Value('closed'),
            closedAt: Value(now),
            updatedAt: Value(now),
          ),
        );
  }

  Future<String?> _openSessionLocalId(String tableLocalId) async {
    final open = await (_db.select(_db.localSessions)
          ..where(
            (t) =>
                t.tableLocalId.equals(tableLocalId) & t.status.equals('open'),
          )
          ..orderBy([(t) => OrderingTerm.desc(t.openedAt)])
          ..limit(1))
        .getSingleOrNull();
    return open?.localId;
  }

  Future<void> _attachPulledOrderToTable({
    required int workspaceId,
    required String? tableLocalId,
    required int? tableServerId,
    required String? sessionLocalId,
    required int? tableSessionId,
    required String orderLocalId,
    required Map<String, dynamic> data,
    required DateTime now,
  }) async {
    if (tableLocalId == null || tableLocalId.isEmpty) return;
    final posStatus = _kitchenPosStatus(local: null, data: data);
    final paymentStatus = _kitchenPaymentStatus(data['payment_status']);
    if (posStatus == 'cancelled') return;
    final live = paymentStatus != 'paid' && posStatus != 'completed';
    if (!live) return;

    var sessionId = (sessionLocalId ?? '').trim();
    if (tableSessionId != null && tableSessionId > 0) {
      sessionId = await _ensureServerTableSession(
        workspaceId: workspaceId,
        tableLocalId: tableLocalId,
        serverSessionId: tableSessionId,
        openedAtRaw: data['placed_at'] ?? data['created_at'] ?? data['opened_at'],
        currentSessionLocalId: sessionId,
      );
    } else if (sessionId.isEmpty) {
      sessionId = await _openSessionLocalId(tableLocalId) ?? '';
    }
    if (sessionId.isEmpty) {
      sessionId = const Uuid().v4();
      await _upsertOpenLocalSession(
        sessionLocalId: sessionId,
        workspaceId: workspaceId,
        tableLocalId: tableLocalId,
        openedAtRaw: data['placed_at'] ?? data['created_at'],
      );
    }

    await (_db.update(_db.localOrders)..where(
          (t) =>
              t.localId.equals(orderLocalId) &
              t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalOrdersCompanion(
            sessionLocalId: Value(sessionId),
            tableLocalId: Value(tableLocalId),
            tableServerId: Value(tableServerId),
            updatedAt: Value(now),
          ),
        );

    final table = await (_db.select(_db.localTables)..where(
          (t) =>
              t.localId.equals(tableLocalId) &
              t.workspaceId.equals(workspaceId),
        ))
        .getSingleOrNull();
    if (table == null) return;

    Map<String, dynamic> payload = {};
    try {
      final decoded = jsonDecode(table.payloadJson);
      if (decoded is Map) payload = Map<String, dynamic>.from(decoded);
    } catch (_) {}
    final openedAt = parseOpenedAt(payload['opened_at']) ??
        parseOpenedAt(data['placed_at']) ??
        parseOpenedAt(data['created_at']) ??
        now.toUtc();
    final snapshot = {
      'id': (data['id'] as num?)?.toInt() ?? orderLocalId,
      'local_id': orderLocalId,
      'client_reference': '${data['client_reference'] ?? orderLocalId}',
      'order_number': '${data['order_number'] ?? orderLocalId}',
      'pos_status': posStatus,
      'payment_status': paymentStatus,
      'session_local_id': sessionId,
      'session_id': tableSessionId,
      'created_at': (data['placed_at'] ?? data['created_at'] ?? now.toUtc().toIso8601String()).toString(),
      'subtotal': data['subtotal'] ?? 0,
      'tax_amount': data['tax_amount'] ?? 0,
      'discount_amount': data['discount_amount'] ?? 0,
      'total_amount': data['total_amount'] ?? 0,
      'source': data['source'],
      'items': data['items'] is List ? data['items'] : const [],
    };
    final previous = payload['orders'] is List
        ? [
            for (final item in payload['orders'] as List)
              if (item is Map) Map<String, dynamic>.from(item),
          ]
        : const <Map<String, dynamic>>[];
    final merged = mergeTableSessionOrders(
      sessionOrders: filterOrdersForOpenTableSession(
        orders: previous,
        openedAt: openedAt,
        currentSessionLocalId: sessionId,
      ),
      liveOrders: [snapshot],
    );
    var total = 0.0;
    for (final order in merged) {
      final value = order['total_amount'];
      if (value is num) total += value.toDouble();
    }
    final next = {
      ...payload,
      if (tableServerId != null) 'id': tableServerId,
      'status': 'occupied',
      'session_open': true,
      'session_client_id': sessionId,
      'session_id': tableSessionId ?? payload['session_id'],
      'opened_at': payload['opened_at'] ?? openedAt.toUtc().toIso8601String(),
      'orders': merged,
      'orders_count': merged.length,
      'open_orders_count': merged.length,
      if (total > 0) 'total': total,
    };
    await (_db.update(_db.localTables)..where(
          (t) =>
              t.localId.equals(tableLocalId) &
              t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalTablesCompanion(
            status: const Value('occupied'),
            sessionServerId: Value(
              tableSessionId ?? table.sessionServerId,
            ),
            payloadJson: Value(jsonEncode(next)),
            updatedAt: Value(now),
          ),
        );
  }
}
