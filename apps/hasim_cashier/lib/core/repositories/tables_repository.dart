import 'dart:async';
import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../api/cashier_api.dart';
import '../config/app_config.dart';
import '../local_db/app_database.dart';
import '../pos/domain/pricing_service.dart';
import '../pos/pos_mode.dart';
import '../pos/table_session_orders.dart';
import '../local_db/local_ids.dart';
import '../util/json_numbers.dart';
import '../util/occupied_duration.dart';
import 'sync_queue_repository.dart';

/// Tables UI reads/writes through this repository only.
/// Local SQLite is the source for display; remote refresh is an implementation detail.
class TablesRepository {
  TablesRepository(
    this._db,
    this._queue, {
    CashierApiClient? api,
    String Function()? newId,
  }) : _api = api,
       _newId = newId ?? (() => const Uuid().v4());

  final AppDatabase _db;
  final SyncQueueRepository _queue;
  final CashierApiClient? _api;
  final String Function() _newId;

  Future<List<Map<String, dynamic>>> listTables(int workspaceId) async {
    if (workspaceId <= 0) return const [];
    await _ensureLocalBoardIds(workspaceId);
    final rows =
        await (_db.select(_db.localTables)
              ..where((t) => t.workspaceId.equals(workspaceId))
              ..orderBy([(t) => OrderingTerm.asc(t.name)]))
            .get();
    final sessionOrders = await _nonCancelledOrdersInWorkspace(workspaceId);
    final openByTable = await _openSessionIdsByTable(workspaceId);
    return [
      for (final row in rows)
        _rowToBoardMap(
          row,
          sessionOrders: _sessionOrdersMatchingTable(
            row,
            sessionOrders,
            currentSessionLocalId: _resolvedOperationalSessionId(
              row,
              openByTable[row.localId],
            ),
          ),
          currentSessionLocalId: _resolvedOperationalSessionId(
            row,
            openByTable[row.localId],
          ),
        ),
    ];
  }

  Future<Map<String, dynamic>?> getTable(
    int workspaceId,
    int tableServerId,
  ) async {
    final row = await _findTable(workspaceId, serverId: tableServerId);
    if (row == null) return null;
    final currentSession = _resolvedOperationalSessionId(
      row,
      await _openSessionLocalId(row),
    );
    final live = await _liveSessionOrdersForTable(
      row,
      currentSessionLocalId: currentSession,
    );
    final liveMaps = await _mapsForOrders(live);
    return _rowToDetailMap(
      row,
      liveOrders: live,
      liveOrderMaps: liveMaps,
      currentSessionLocalId: currentSession,
    );
  }

  /// Workspace-scoped local PK so the same server table id can exist in A and B.
  static String tableLocalId(int workspaceId, int serverId) =>
      LocalIds.table(workspaceId, serverId);

  Future<LocalTable?> _findTable(
    int workspaceId, {
    String? localId,
    int? serverId,
  }) async {
    if (workspaceId <= 0) return null;
    final lid = localId?.trim();
    if (lid != null && lid.isNotEmpty) {
      final byLocal =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.localId.equals(lid) & t.workspaceId.equals(workspaceId),
              ))
              .getSingleOrNull();
      if (byLocal != null) return byLocal;
    }
    if (serverId != null && serverId > 0) {
      final byServer =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.workspaceId.equals(workspaceId) &
                    t.serverId.equals(serverId),
              ))
              .getSingleOrNull();
      if (byServer != null) return byServer;
      final scoped =
          await (_db.select(_db.localTables)..where(
                (t) =>
                    t.localId.equals(tableLocalId(workspaceId, serverId)) &
                    t.workspaceId.equals(workspaceId),
              ))
              .getSingleOrNull();
      if (scoped != null) return scoped;
      final rows = await (_db.select(
        _db.localTables,
      )..where((t) => t.workspaceId.equals(workspaceId))).get();
      for (final row in rows) {
        if (boardNumericId(row) == serverId) return row;
      }
    }
    return null;
  }

  Future<LocalTable> _requireTableByServerId(
    int workspaceId,
    int tableServerId,
  ) async {
    final table = await _findTable(workspaceId, serverId: tableServerId);
    if (table == null) {
      throw StateError('الطاولة غير متاحة محليًا.');
    }
    return table;
  }

  Future<void> _ensureLocalBoardIds(int workspaceId) async {
    final rows = await (_db.select(
      _db.localTables,
    )..where((t) => t.workspaceId.equals(workspaceId))).get();
    final missing = [
      for (final row in rows)
        if (row.serverId == null && boardNumericId(row) == null) row,
    ];
    if (missing.isEmpty) return;
    var next = 0;
    for (final row in rows) {
      final board = boardNumericId(row);
      if (board != null && board > next) next = board;
    }
    for (final row in missing) {
      next += 1;
      final payload = _safeMap(row.payloadJson);
      payload['board_id'] = next;
      payload['name'] = row.name;
      payload['status'] = row.status;
      await (_db.update(
        _db.localTables,
      )..where((t) => t.localId.equals(row.localId))).write(
        LocalTablesCompanion(
          payloadJson: Value(jsonEncode(payload)),
          updatedAt: Value(DateTime.now()),
        ),
      );
    }
  }

  static int? boardNumericId(LocalTable row) {
    if (row.serverId != null && row.serverId! > 0) return row.serverId;
    try {
      final decoded = jsonDecode(row.payloadJson);
      if (decoded is Map) {
        return asInt(decoded['board_id']) ?? asInt(decoded['id']);
      }
    } catch (_) {}
    return null;
  }

  Future<void> replaceBoard(
    int workspaceId,
    List<Map<String, dynamic>> tables,
  ) async {
    if (workspaceId <= 0) return;
    final now = DateTime.now();
    await _db.transaction(() async {
      final existing = await (_db.select(
        _db.localTables,
      )..where((t) => t.workspaceId.equals(workspaceId))).get();
      final keep = <String>{};
      for (final table in tables) {
        final serverId = asInt(table['id']);
        if (serverId == null) continue;
        final localId = tableLocalId(workspaceId, serverId);
        keep.add(localId);
        LocalTable? previous;
        for (final e in existing) {
          if (e.localId == localId || e.serverId == serverId) {
            previous = e;
            break;
          }
        }
        // Drop legacy unscoped local_id (table_N) when migrating to w{ws}_table_N.
        if (previous != null && previous.localId != localId) {
          await (_db.delete(
            _db.localTables,
          )..where((t) => t.localId.equals(previous!.localId))).go();
        }
        // Preserve richer detail payload when board snapshot is thinner.
        final mergedPayload = _mergePayload(previous?.payloadJson, table);
        // Preserve offline-open client session until close sync completes.
        final prevMap = previous == null
            ? const <String, dynamic>{}
            : _safeMap(previous.payloadJson);
        final remoteSessionId = asInt(table['session_id']);
        final remoteSessionOpen = remoteSessionId != null && remoteSessionId > 0;
        // Only an offline sitting Laravel has not acknowledged yet (no server
        // session id) survives a snapshot that says the table is free. Once
        // acknowledged, the snapshot is authoritative — same rule as pull.
        final prevAcked =
            previous?.sessionServerId != null && previous!.sessionServerId! > 0;
        final offlineOpen =
            prevMap['session_client_id'] != null &&
            previous?.status == 'occupied' &&
            !prevAcked &&
            !remoteSessionOpen &&
            (table['status'] == 'available' || table['session_id'] == null);
        // Occupancy follows the open session, not the status flag alone
        // (QR guest visit keeps dining_tables.status = available).
        final status = (offlineOpen || remoteSessionOpen)
            ? 'occupied'
            : '${table['status'] ?? previous?.status ?? 'available'}';
        final sessionServerId = offlineOpen
            ? previous?.sessionServerId
            : (remoteSessionOpen ? remoteSessionId : null);
        if (offlineOpen && prevMap['session_client_id'] != null) {
          mergedPayload['session_client_id'] = prevMap['session_client_id'];
          mergedPayload['session_open'] = true;
          if (prevMap['opened_at'] != null) {
            mergedPayload['opened_at'] = prevMap['opened_at'];
          }
        } else if (remoteSessionOpen) {
          mergedPayload['status'] = 'occupied';
          mergedPayload['session_open'] = true;
          mergedPayload['session_id'] = remoteSessionId;
        } else if (!table.containsKey('session_id') || table['session_id'] == null) {
          if (status == 'available') {
            mergedPayload['session_open'] = false;
            mergedPayload['session_id'] = null;
            mergedPayload['session_client_id'] = null;
            mergedPayload['opened_at'] = null;
            mergedPayload['orders'] = const [];
          }
        }
        await _db
            .into(_db.localTables)
            .insertOnConflictUpdate(
              LocalTablesCompanion.insert(
                localId: localId,
                workspaceId: workspaceId,
                serverId: Value(serverId),
                name: '${table['name'] ?? previous?.name ?? ''}',
                status: Value(status),
                capacity: Value(asInt(table['capacity']) ?? previous?.capacity),
                sessionServerId: Value(sessionServerId),
                payloadJson: Value(jsonEncode(mergedPayload)),
                updatedAt: now,
              ),
            );
      }
    });
  }

  Future<void> upsertTableDetail(
    int workspaceId,
    int tableServerId,
    Map<String, dynamic> detail,
  ) async {
    if (workspaceId <= 0 || tableServerId <= 0) return;
    final now = DateTime.now();
    final localId = tableLocalId(workspaceId, tableServerId);
    final previous = await (_db.select(
      _db.localTables,
    )..where((t) => t.localId.equals(localId))).getSingleOrNull();
    final prevMap = previous == null
        ? const <String, dynamic>{}
        : _safeMap(previous.payloadJson);
    final offlineOpen =
        prevMap['session_client_id'] != null &&
        previous?.status == 'occupied' &&
        (detail['status'] == 'available' || detail['session_id'] == null);
    final merged = {
      ...detail,
      'id': tableServerId,
      if (offlineOpen) ...{
        'session_client_id': prevMap['session_client_id'],
        'session_open': true,
        if (prevMap['opened_at'] != null) 'opened_at': prevMap['opened_at'],
      },
    };
    await _db
        .into(_db.localTables)
        .insertOnConflictUpdate(
          LocalTablesCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            serverId: Value(tableServerId),
            name: '${detail['name'] ?? previous?.name ?? ''}',
            status: Value(
              offlineOpen
                  ? 'occupied'
                  : '${detail['status'] ?? previous?.status ?? 'available'}',
            ),
            capacity: Value(asInt(detail['capacity']) ?? previous?.capacity),
            sessionServerId: Value(
              offlineOpen
                  ? previous?.sessionServerId
                  : asInt(detail['session_id']),
            ),
            payloadJson: Value(jsonEncode(merged)),
            updatedAt: now,
          ),
        );
  }

  /// Local-first open session — works fully offline; syncs when online.
  Future<Map<String, dynamic>> openSessionLocal({
    required int workspaceId,
    required String deviceId,
    required int tableServerId,
  }) async {
    if (workspaceId <= 0 || tableServerId <= 0) {
      throw ArgumentError('workspaceId and tableServerId required');
    }
    final existing = await _findTable(workspaceId, serverId: tableServerId);
    if (existing == null) {
      throw StateError('الطاولة غير متاحة محليًا.');
    }
    final localId = existing.localId;
    final payload = _safeMap(existing.payloadJson);
    final existingClient = '${payload['session_client_id'] ?? ''}'.trim();
    final openId = await _openSessionLocalId(existing);
    if (await _isCurrentOpenSitting(
      table: existing,
      payloadSessionId: existingClient,
      openSessionId: openId,
    )) {
      return await getTable(workspaceId, tableServerId) ??
          _rowToDetailMap(existing);
    }

    final sessionClientId = _newId();
    final openedAt = DateTime.now().toUtc().toIso8601String();
    final now = DateTime.now();
    final nextPayload = {
      ...payload,
      'id': tableServerId,
      'status': 'occupied',
      'session_open': true,
      'session_client_id': sessionClientId,
      'opened_at': openedAt,
      'session_id': existing.sessionServerId,
      'orders': const [],
      'last_sale_items': const [],
      'last_sale_total': 0,
      'last_invoice_local_id': null,
      'last_invoice_number': null,
      'last_local_invoice': null,
      'subtotal': 0,
      'tax_amount': 0,
      'discount_amount': 0,
      'total': 0,
    };

    await _db.transaction(() async {
      await _closeOpenLocalSessions(localId, now);
      await _insertOpenLocalSession(
        sessionLocalId: sessionClientId,
        workspaceId: workspaceId,
        tableLocalId: localId,
        now: now,
        openedAt: parseOpenedAt(openedAt) ?? now,
      );
      await (_db.update(_db.localTables)..where(
            (t) =>
                t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
          ))
          .write(
            LocalTablesCompanion(
              status: const Value('occupied'),
              payloadJson: Value(jsonEncode(nextPayload)),
              updatedAt: Value(now),
            ),
          );
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'table_session',
        entityId: localId,
        operation: 'open',
        payload: {
          'table_server_id': tableServerId,
          'table_local_id': localId,
          'session_client_id': sessionClientId,
        },
        clientReference: sessionClientId,
      );
    });

    final refreshed = await getTable(workspaceId, tableServerId);
    return refreshed ?? nextPayload;
  }

  /// Occupy a dining table after cashier checkout.
  ///
  /// The sale is already paid + invoiced; the table stays busy with a live
  /// [opened_at] until [closeSessionLocal] frees it. Does not insert another
  /// invoice.
  Future<Map<String, dynamic>?> occupyFromCheckout({
    required int workspaceId,
    required String deviceId,
    String? tableLocalId,
    int? tableServerId,
    required String invoiceLocalId,
    required String invoiceNumber,
    String? orderLocalId,
    required double total,
    List<Map<String, dynamic>> items = const [],
  }) async {
    if (workspaceId <= 0) return null;
    final existing = await _findTable(
      workspaceId,
      localId: tableLocalId,
      serverId: tableServerId,
    );
    if (existing == null) return null;

    final payload = _safeMap(existing.payloadJson);
    final existingClient = '${payload['session_client_id'] ?? ''}'.trim();
    final existingOpened = '${payload['opened_at'] ?? ''}'.trim();
    final openId = await _openSessionLocalId(existing);
    final alreadyOpen = await _isCurrentOpenSitting(
      table: existing,
      payloadSessionId: existingClient,
      openSessionId: openId,
    );
    final sessionClientId = alreadyOpen ? existingClient : _newId();
    final openedAt = alreadyOpen && existingOpened.isNotEmpty
        ? payload['opened_at']
        : DateTime.now().toUtc().toIso8601String();
    final now = DateTime.now();
    final sid = existing.serverId ?? tableServerId;
    final saleOrder = _checkoutSessionOrder(
      invoiceLocalId: invoiceLocalId,
      invoiceNumber: invoiceNumber,
      orderLocalId: orderLocalId,
      total: total,
      items: items,
      sessionClientId: sessionClientId,
      createdAt: openedAt,
    );
    final previousOrders = alreadyOpen
        ? filterOrdersForOpenTableSession(
            orders: _normalizeActiveOrders(payload['orders']),
            openedAt: parseOpenedAt(openedAt),
            currentSessionLocalId: sessionClientId,
          )
        : const <Map<String, dynamic>>[];
    final nextPayload = {
      ...payload,
      if (sid != null) 'id': sid,
      'status': 'occupied',
      'session_open': true,
      'session_client_id': sessionClientId,
      'opened_at': openedAt,
      'last_invoice_local_id': invoiceLocalId,
      'last_invoice_number': invoiceNumber,
      'last_sale_total': total,
      'last_sale_items': saleOrder['items'],
      'total': total,
      'orders': [...previousOrders, saleOrder],
    };

    await _db.transaction(() async {
      if (!alreadyOpen) {
        await _closeOpenLocalSessions(existing.localId, now);
        await _insertOpenLocalSession(
          sessionLocalId: sessionClientId,
          workspaceId: workspaceId,
          tableLocalId: existing.localId,
          now: now,
          openedAt: parseOpenedAt(openedAt) ?? now,
        );
        final leftovers = await _unpaidOrdersForTable(existing);
        for (final order in leftovers) {
          await (_db.update(_db.localOrders)..where(
                (t) =>
                    t.localId.equals(order.localId) &
                    t.workspaceId.equals(workspaceId),
              ))
              .write(
                LocalOrdersCompanion(
                  posStatus: const Value('cancelled'),
                  updatedAt: Value(now),
                ),
              );
        }
      } else {
        await _insertOpenLocalSession(
          sessionLocalId: sessionClientId,
          workspaceId: workspaceId,
          tableLocalId: existing.localId,
          now: now,
          openedAt: parseOpenedAt(openedAt) ?? now,
        );
      }
      final occupyOrderId = orderLocalId?.trim() ?? '';
      if (occupyOrderId.isNotEmpty) {
        await (_db.update(_db.localOrders)..where(
              (t) =>
                  t.localId.equals(occupyOrderId) &
                  t.workspaceId.equals(workspaceId),
            ))
            .write(
              LocalOrdersCompanion(
                sessionLocalId: Value(sessionClientId),
                updatedAt: Value(now),
              ),
            );
      }
      await (_db.update(_db.localTables)..where(
            (t) =>
                t.localId.equals(existing.localId) &
                t.workspaceId.equals(workspaceId),
          ))
          .write(
            LocalTablesCompanion(
              status: const Value('occupied'),
              payloadJson: Value(jsonEncode(nextPayload)),
              updatedAt: Value(now),
            ),
          );
      if (!alreadyOpen) {
        await _queue.enqueue(
          workspaceId: workspaceId,
          deviceId: deviceId.trim(),
          entityType: 'table_session',
          entityId: existing.localId,
          operation: 'open',
          payload: {
            if (sid != null) 'table_server_id': sid,
            'table_local_id': existing.localId,
            'session_client_id': sessionClientId,
            'source': 'cashier_checkout',
          },
          clientReference: sessionClientId,
        );
      }
    });

    if (sid != null && sid > 0) {
      return await getTable(workspaceId, sid) ?? nextPayload;
    }
    return nextPayload;
  }

  Stream<List<Map<String, dynamic>>> watchBoard(int workspaceId) {
    return (_db.select(_db.localTables)
          ..where((t) => t.workspaceId.equals(workspaceId)))
        .watch()
        .asyncMap((_) => listTables(workspaceId));
  }

  /// Emits whenever the table row, its sessions, or any order attached to it
  /// changes in SQLite (local action or sync pull). Consumers reload the
  /// detail snapshot so pulled QR orders / remote closes show without leaving
  /// the screen.
  Stream<void> watchTableActivity(int workspaceId, int tableServerId) {
    // One drift stream keyed on all three tables (same mechanics as
    // [watchBoard]); a hand-rolled merge controller does not close cleanly
    // under the widget-test fake async zone.
    return _db
        .customSelect(
          'SELECT '
          '(SELECT COUNT(*) FROM local_tables WHERE workspace_id = ?1 '
          '   AND server_id = ?2) AS tables_n, '
          '(SELECT MAX(updated_at) FROM local_tables WHERE workspace_id = ?1 '
          '   AND server_id = ?2) AS tables_at, '
          '(SELECT COUNT(*) FROM local_orders WHERE workspace_id = ?1 '
          '   AND table_server_id = ?2) AS orders_n, '
          '(SELECT MAX(updated_at) FROM local_orders WHERE workspace_id = ?1 '
          '   AND table_server_id = ?2) AS orders_at, '
          '(SELECT COUNT(*) FROM local_sessions WHERE workspace_id = ?1) '
          '   AS sessions_n, '
          '(SELECT MAX(updated_at) FROM local_sessions WHERE workspace_id = ?1) '
          '   AS sessions_at',
          variables: [
            Variable.withInt(workspaceId),
            Variable.withInt(tableServerId),
          ],
          readsFrom: {_db.localTables, _db.localOrders, _db.localSessions},
        )
        .watch()
        .map((_) {});
  }

  /// Local-first close + payment + invoice draft. Queues sync; no online required.
  ///
  /// If the table was occupied by a cashier checkout (already invoiced, no
  /// unpaid orders), this only frees the table — it does not write a second
  /// empty invoice.
  Future<Map<String, dynamic>> closeSessionLocal({
    required int workspaceId,
    required String deviceId,
    required int tableServerId,
    String? paymentMethod,
  }) async {
    if (workspaceId <= 0 || tableServerId <= 0) {
      throw ArgumentError('workspaceId and tableServerId required');
    }
    final table = await _requireTableByServerId(workspaceId, tableServerId);
    final localId = table.localId;
    final payload = _safeMap(table.payloadJson);
    final sessionClientId = '${payload['session_client_id'] ?? _newId()}';
    final closeClientId = _newId();
    final now = DateTime.now();

    final activeOrders = await _unpaidOrdersForTable(table);
    if (activeOrders.isEmpty) {
      await _db.transaction(() async {
        await _freeTableRow(
          workspaceId: workspaceId,
          localId: localId,
          tableServerId: tableServerId,
          payload: payload,
          now: now,
        );
        await _closeOpenLocalSessions(localId, now);
        await _queue.enqueue(
          workspaceId: workspaceId,
          deviceId: deviceId.trim(),
          entityType: 'table_session',
          entityId: localId,
          operation: 'close',
          payload: {
            'table_server_id': tableServerId,
            'table_local_id': localId,
            'session_server_id': table.sessionServerId,
            'session_client_id': sessionClientId,
            'payment_method': (paymentMethod ?? 'cash').trim(),
            'freed_only': true,
          },
          clientReference: closeClientId,
        );
      });
      return {
        'invoice': null,
        'freed_only': true,
        'table': await getTable(workspaceId, tableServerId),
      };
    }

    final invoiceLocalId = _newId();
    final paymentLocalId = _newId();

    var subtotalCents = 0;
    var taxCents = 0;
    var discountCents = 0;
    var totalCents = 0;
    final invoiceItems = <Map<String, dynamic>>[];
    for (final order in activeOrders) {
      subtotalCents += order.subtotal;
      taxCents += order.taxAmount;
      discountCents += order.discountAmount;
      totalCents += order.totalAmount;
      final items =
          await (_db.select(_db.localOrderItems)..where(
                (t) =>
                    t.orderLocalId.equals(order.localId) &
                    t.isRemoved.equals(false),
              ))
              .get();
      for (final item in items) {
        invoiceItems.add({
          'item_name': item.name,
          'quantity': item.quantity,
          'unit_price': Money.fromCents(item.unitPrice),
          'total_amount': Money.fromCents(item.totalAmount),
        });
      }
    }
    if (totalCents <= 0) {
      totalCents = Money.toCents(payload['total'] ?? payload['subtotal']);
      subtotalCents = Money.toCents(payload['subtotal']);
      taxCents = Money.toCents(payload['tax_amount']);
      discountCents = Money.toCents(payload['discount_amount']);
    }

    final method = (paymentMethod ?? 'cash').trim();
    final invoiceNumber = 'LOCAL-$invoiceLocalId';
    final invoicePayload = {
      'local_id': invoiceLocalId,
      'invoice_number': invoiceNumber,
      'total_amount': Money.fromCents(totalCents),
      'subtotal': Money.fromCents(subtotalCents),
      'tax_amount': Money.fromCents(taxCents),
      'discount_amount': Money.fromCents(discountCents),
      'payment_method': method,
      'closed_at': now.toUtc().toIso8601String(),
      'table': {'id': tableServerId, 'name': table.name},
      'items': invoiceItems,
      'sync_status': 'pending',
    };

    await _db.transaction(() async {
      await _db
          .into(_db.localInvoices)
          .insert(
            LocalInvoicesCompanion.insert(
              localId: invoiceLocalId,
              workspaceId: workspaceId,
              deviceId: deviceId.trim(),
              invoiceNumber: Value(invoiceNumber),
              localInvoiceNumber: Value(invoiceNumber),
              status: const Value('closed'),
              subtotal: Value(subtotalCents),
              discountAmount: Value(discountCents),
              taxAmount: Value(taxCents),
              totalAmount: Value(totalCents),
              syncStatus: const Value('pending'),
              payloadJson: Value(jsonEncode(invoicePayload)),
              createdAt: now,
            ),
          );
      await _db
          .into(_db.localPayments)
          .insert(
            LocalPaymentsCompanion.insert(
              localId: paymentLocalId,
              workspaceId: workspaceId,
              deviceId: deviceId.trim(),
              invoiceLocalId: Value(invoiceLocalId),
              method: method,
              amount: totalCents,
              syncStatus: const Value('pending'),
              clientReference: closeClientId,
              createdAt: now,
            ),
          );
      for (final order in activeOrders) {
        await (_db.update(_db.localOrders)..where(
              (t) =>
                  t.localId.equals(order.localId) &
                  t.workspaceId.equals(workspaceId),
            ))
            .write(
              LocalOrdersCompanion(
                paymentStatus: const Value('paid'),
                posStatus: const Value('completed'),
                updatedAt: Value(now),
              ),
            );
      }
      await _freeTableRow(
        workspaceId: workspaceId,
        localId: localId,
        tableServerId: tableServerId,
        payload: payload,
        now: now,
        lastInvoice: invoicePayload,
      );
      await _closeOpenLocalSessions(localId, now);
      if (activeOrders.length == 1) {
        await (_db.update(
          _db.localInvoices,
        )..where((t) => t.localId.equals(invoiceLocalId))).write(
          LocalInvoicesCompanion(
            orderLocalId: Value(activeOrders.first.localId),
          ),
        );
      }
      for (final order in activeOrders) {
        await _enqueuePaidTableInvoice(
          workspaceId: workspaceId,
          deviceId: deviceId.trim(),
          order: order,
          invoiceLocalId: activeOrders.length == 1
              ? invoiceLocalId
              : '${invoiceLocalId}-${order.localId}',
          invoiceNumber: activeOrders.length == 1
              ? invoiceNumber
              : invoiceNumber,
          paymentMethod: method,
        );
      }
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'table_session',
        entityId: localId,
        operation: 'close',
        payload: {
          'table_server_id': tableServerId,
          'table_local_id': localId,
          'session_server_id': table.sessionServerId,
          'session_client_id': sessionClientId,
          'payment_method': method,
          'invoice_local_id': invoiceLocalId,
          'payment_local_id': paymentLocalId,
        },
        clientReference: closeClientId,
      );
    });

    return {
      'invoice': invoicePayload,
      'table': await getTable(workspaceId, tableServerId),
    };
  }

  Future<void> _enqueuePaidTableInvoice({
    required int workspaceId,
    required String deviceId,
    required LocalOrder order,
    required String invoiceLocalId,
    required String invoiceNumber,
    required String paymentMethod,
  }) async {
    if (PosMode.isReservedStandaloneWorkspace(workspaceId)) return;
    final items =
        await (_db.select(_db.localOrderItems)..where(
              (t) =>
                  t.orderLocalId.equals(order.localId) &
                  t.isRemoved.equals(false),
            ))
            .get();
    if (items.isEmpty) return;
    for (final item in items) {
      final serverId = item.productServerId;
      if (serverId == null || serverId <= 0 || item.quantity < 1) return;
    }
    String? tableName;
    if (order.tableLocalId != null && order.tableLocalId!.trim().isNotEmpty) {
      final named = await (_db.select(_db.localTables)
            ..where((t) => t.localId.equals(order.tableLocalId!)))
          .getSingleOrNull();
      final name = named?.name.trim() ?? '';
      if (name.isNotEmpty) tableName = name;
    }
    final orderPayload = {
      'order_type': 'table',
      'offline_sale': true,
      if (order.tableServerId != null) 'dining_table_id': order.tableServerId,
      if (order.tableLocalId != null && order.tableLocalId!.trim().isNotEmpty)
        'table_local_id': order.tableLocalId!.trim(),
      if (order.sessionLocalId != null &&
          order.sessionLocalId!.trim().isNotEmpty)
        'session_local_id': order.sessionLocalId!.trim(),
      if (tableName != null) 'table_name': tableName,
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
            'pos_menu_item_id': item.productServerId,
            'quantity': item.quantity,
            'unit_price': Money.fromCents(item.unitPrice),
            'tax_amount': Money.fromCents(item.taxAmount),
            'name': item.name,
            'product_name': item.name,
          },
      ],
    };
    if (order.serverId == null || order.serverId! <= 0) {
      final existingOrder = await _queue.findOpenOp(
        workspaceId: workspaceId,
        entityType: 'order',
        entityId: order.localId,
        operation: 'create',
      );
      if (existingOrder == null) {
        await _queue.enqueue(
          workspaceId: workspaceId,
          deviceId: deviceId,
          entityType: 'order',
          entityId: order.localId,
          operation: 'create',
          payload: orderPayload,
          clientReference: order.clientReference,
        );
      } else {
        await _queue.updateOpenPayload(
          workspaceId: workspaceId,
          entityType: 'order',
          entityId: order.localId,
          operation: 'create',
          payload: orderPayload,
        );
      }
    }
    final existingInvoice = await _queue.findOpenOp(
      workspaceId: workspaceId,
      entityType: 'invoice',
      entityId: invoiceLocalId,
      operation: 'create',
    );
    if (existingInvoice != null) return;
    await _queue.enqueue(
      workspaceId: workspaceId,
      deviceId: deviceId,
      entityType: 'invoice',
      entityId: invoiceLocalId,
      operation: 'create',
      payload: {
        'order_type': 'table',
        'order_local_id': order.localId,
        'local_invoice_number': invoiceNumber,
        'closed_at': DateTime.now().toUtc().toIso8601String(),
        'currency': 'SAR',
        'subtotal_amount': Money.fromCents(order.subtotal),
        'discount_amount': Money.fromCents(order.discountAmount),
        'tax_amount': Money.fromCents(order.taxAmount),
        'total_amount': Money.fromCents(order.totalAmount),
        'payment_method': paymentMethod,
      },
      clientReference: invoiceLocalId,
    );
  }

  Future<void> _freeTableRow({
    required int workspaceId,
    required String localId,
    required int tableServerId,
    required Map<String, dynamic> payload,
    required DateTime now,
    Map<String, dynamic>? lastInvoice,
  }) {
    final nextTablePayload = {
      ...payload,
      'id': tableServerId,
      'status': 'available',
      'session_open': false,
      'session_id': null,
      'session_client_id': null,
      'opened_at': null,
      'orders': const [],
      'last_sale_items': const [],
      'last_sale_total': 0,
      'last_invoice_local_id': null,
      'last_invoice_number': null,
      'subtotal': 0,
      'tax_amount': 0,
      'discount_amount': 0,
      'total': 0,
      if (lastInvoice != null) 'last_local_invoice': lastInvoice,
    };
    return (_db.update(_db.localTables)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalTablesCompanion(
            status: const Value('available'),
            sessionServerId: const Value(null),
            payloadJson: Value(jsonEncode(nextTablePayload)),
            updatedAt: Value(now),
          ),
        );
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

  Future<void> _insertOpenLocalSession({
    required String sessionLocalId,
    required int workspaceId,
    required String tableLocalId,
    required DateTime now,
    required DateTime openedAt,
  }) async {
    final id = sessionLocalId.trim();
    if (id.isEmpty || tableLocalId.trim().isEmpty) return;
    final existing = await (_db.select(
      _db.localSessions,
    )..where((t) => t.localId.equals(id))).getSingleOrNull();
    if (existing != null) {
      if (existing.status == 'open') return;
      await (_db.update(
        _db.localSessions,
      )..where((t) => t.localId.equals(id))).write(
        LocalSessionsCompanion(
          status: const Value('open'),
          openedAt: Value(openedAt),
          closedAt: const Value(null),
          updatedAt: Value(now),
        ),
      );
      return;
    }
    await _db
        .into(_db.localSessions)
        .insert(
          LocalSessionsCompanion.insert(
            localId: id,
            workspaceId: workspaceId,
            tableLocalId: tableLocalId,
            status: const Value('open'),
            openedAt: openedAt,
            createdAt: now,
            updatedAt: now,
          ),
        );
  }

  Future<String?> _openSessionLocalId(LocalTable table) async {
    final preferred =
        '${_safeMap(table.payloadJson)['session_client_id'] ?? ''}'.trim();
    if (preferred.isNotEmpty) {
      final byId = await (_db.select(
        _db.localSessions,
      )..where((t) => t.localId.equals(preferred))).getSingleOrNull();
      if (byId != null && byId.status == 'open') return byId.localId;
      if (byId == null && table.status == 'occupied') return preferred;
    }
    final open =
        await (_db.select(_db.localSessions)
              ..where(
                (t) =>
                    t.workspaceId.equals(table.workspaceId) &
                    t.tableLocalId.equals(table.localId) &
                    t.status.equals('open'),
              )
              ..orderBy([(t) => OrderingTerm.desc(t.openedAt)])
              ..limit(1))
            .getSingleOrNull();
    return open?.localId;
  }

  String _resolvedOperationalSessionId(LocalTable row, String? openSessionId) {
    final open = (openSessionId ?? '').trim();
    if (open.isNotEmpty) return open;
    final payload = _safeMap(row.payloadJson);
    if (row.status != 'occupied' && !_payloadSessionOpen(payload)) return '';
    final client = '${payload['session_client_id'] ?? ''}'.trim();
    if (client.isNotEmpty) return client;
    return '__payload__';
  }

  Future<bool> _isCurrentOpenSitting({
    required LocalTable table,
    required String payloadSessionId,
    required String? openSessionId,
  }) async {
    if (table.status != 'occupied' || payloadSessionId.isEmpty) return false;
    if (openSessionId == payloadSessionId) return true;
    if (openSessionId != null) return false;
    final row = await (_db.select(
      _db.localSessions,
    )..where((t) => t.localId.equals(payloadSessionId))).getSingleOrNull();
    return row == null;
  }

  Future<Map<String, String>> _openSessionIdsByTable(int workspaceId) async {
    final rows =
        await (_db.select(_db.localSessions)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) & t.status.equals('open'),
            ))
            .get();
    final out = <String, String>{};
    final openedAt = <String, DateTime>{};
    for (final row in rows) {
      final previous = openedAt[row.tableLocalId];
      if (previous == null || row.openedAt.isAfter(previous)) {
        out[row.tableLocalId] = row.localId;
        openedAt[row.tableLocalId] = row.openedAt;
      }
    }
    return out;
  }

  /// Cancel session + unpaid orders locally (no network required).
  Future<void> cancelSessionLocal({
    required int workspaceId,
    required String deviceId,
    required int tableServerId,
  }) async {
    final table = await _requireTableByServerId(workspaceId, tableServerId);
    final localId = table.localId;
    final payload = _safeMap(table.payloadJson);
    final sessionClientId = '${payload['session_client_id'] ?? _newId()}';
    final clientRef = _newId();
    final now = DateTime.now();

    final activeOrders = await _unpaidOrdersForTable(table);

    final nextPayload = {
      ...payload,
      'id': tableServerId,
      'status': 'available',
      'session_open': false,
      'session_id': null,
      'session_client_id': null,
      'opened_at': null,
      'orders': const [],
      'last_sale_items': const [],
      'last_sale_total': 0,
      'last_invoice_local_id': null,
      'last_invoice_number': null,
      'subtotal': 0,
      'tax_amount': 0,
      'discount_amount': 0,
      'total': 0,
      'notes': null,
    };

    await _db.transaction(() async {
      for (final order in activeOrders) {
        await (_db.update(
          _db.localOrders,
        )..where((t) => t.localId.equals(order.localId))).write(
          LocalOrdersCompanion(
            posStatus: const Value('cancelled'),
            updatedAt: Value(now),
          ),
        );
      }
      await (_db.update(_db.localTables)..where(
            (t) =>
                t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
          ))
          .write(
            LocalTablesCompanion(
              status: const Value('available'),
              sessionServerId: const Value(null),
              payloadJson: Value(jsonEncode(nextPayload)),
              updatedAt: Value(now),
            ),
          );
      await _closeOpenLocalSessions(localId, now);
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'table_session',
        entityId: localId,
        operation: 'cancel',
        payload: {
          'table_server_id': tableServerId,
          'table_local_id': localId,
          'session_server_id': table.sessionServerId,
          'session_client_id': sessionClientId,
        },
        clientReference: clientRef,
      );
    });
  }

  /// Update session note locally.
  Future<void> setNoteLocal({
    required int workspaceId,
    required String deviceId,
    required int tableServerId,
    required String notes,
  }) async {
    final table = await _requireTableByServerId(workspaceId, tableServerId);
    final localId = table.localId;
    final payload = _safeMap(table.payloadJson);
    final trimmed = notes.trim();
    final next = {...payload, 'notes': trimmed};
    final clientRef = _newId();
    await _db.transaction(() async {
      await (_db.update(
        _db.localTables,
      )..where((t) => t.localId.equals(localId))).write(
        LocalTablesCompanion(
          payloadJson: Value(jsonEncode(next)),
          updatedAt: Value(DateTime.now()),
        ),
      );
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'table_session',
        entityId: localId,
        operation: 'note',
        payload: {
          'table_server_id': tableServerId,
          'table_local_id': localId,
          'session_server_id': table.sessionServerId,
          'session_client_id': payload['session_client_id'],
          'notes': trimmed,
        },
        clientReference: clientRef,
      );
    });
  }

  /// Apply session discount locally and recompute displayed totals.
  Future<void> applyDiscountLocal({
    required int workspaceId,
    required String deviceId,
    required int tableServerId,
    required double discountAmount,
  }) async {
    if (discountAmount < 0) throw ArgumentError('discountAmount');
    final table = await _requireTableByServerId(workspaceId, tableServerId);
    final localId = table.localId;
    final payload = _safeMap(table.payloadJson);
    final subtotal = asDoubleOr(payload['subtotal']);
    final tax = asDoubleOr(payload['tax_amount']);
    final total = (subtotal - discountAmount + tax).clamp(0, double.infinity);
    final next = {
      ...payload,
      'discount_amount': discountAmount,
      'total': total,
    };
    final clientRef = _newId();
    await _db.transaction(() async {
      await (_db.update(
        _db.localTables,
      )..where((t) => t.localId.equals(localId))).write(
        LocalTablesCompanion(
          payloadJson: Value(jsonEncode(next)),
          updatedAt: Value(DateTime.now()),
        ),
      );
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'table_session',
        entityId: localId,
        operation: 'discount',
        payload: {
          'table_server_id': tableServerId,
          'table_local_id': localId,
          'session_server_id': table.sessionServerId,
          'session_client_id': payload['session_client_id'],
          'discount_amount': discountAmount,
        },
        clientReference: clientRef,
      );
    });
  }

  /// Move open session + unpaid orders to another table (local-first).
  Future<void> transferSessionLocal({
    required int workspaceId,
    required String deviceId,
    required int fromTableServerId,
    required int toTableServerId,
  }) async {
    if (fromTableServerId == toTableServerId) {
      throw ArgumentError('target must differ');
    }
    final fromLocalId = tableLocalId(workspaceId, fromTableServerId);
    final toLocalId = tableLocalId(workspaceId, toTableServerId);
    final from = await _requireTable(workspaceId, fromLocalId);
    final to = await _requireTable(workspaceId, toLocalId);
    final fromPayload = _safeMap(from.payloadJson);
    final toPayload = _safeMap(to.payloadJson);
    final sessionClientId = '${fromPayload['session_client_id'] ?? _newId()}';
    final clientRef = _newId();
    final now = DateTime.now();

    final orders =
        await (_db.select(_db.localOrders)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.tableServerId.equals(fromTableServerId) &
                  t.posStatus.isNotValue('cancelled'),
            ))
            .get();

    final movedPayload = {
      ...toPayload,
      ...fromPayload,
      'id': toTableServerId,
      'name': to.name,
      'status': 'occupied',
      'session_open': true,
      'session_client_id': sessionClientId,
      'session_id': from.sessionServerId ?? fromPayload['session_id'],
    };
    final clearedFrom = {
      ...fromPayload,
      'id': fromTableServerId,
      'status': 'available',
      'session_open': false,
      'session_id': null,
      'session_client_id': null,
      'orders': const [],
      'subtotal': 0,
      'tax_amount': 0,
      'discount_amount': 0,
      'total': 0,
      'notes': null,
    };

    await _db.transaction(() async {
      for (final order in orders) {
        await (_db.update(
          _db.localOrders,
        )..where((t) => t.localId.equals(order.localId))).write(
          LocalOrdersCompanion(
            tableServerId: Value(toTableServerId),
            tableLocalId: Value(toLocalId),
            updatedAt: Value(now),
          ),
        );
      }
      await (_db.update(
        _db.localTables,
      )..where((t) => t.localId.equals(toLocalId))).write(
        LocalTablesCompanion(
          status: const Value('occupied'),
          sessionServerId: Value(from.sessionServerId),
          payloadJson: Value(jsonEncode(movedPayload)),
          updatedAt: Value(now),
        ),
      );
      await (_db.update(
        _db.localTables,
      )..where((t) => t.localId.equals(fromLocalId))).write(
        LocalTablesCompanion(
          status: const Value('available'),
          sessionServerId: const Value(null),
          payloadJson: Value(jsonEncode(clearedFrom)),
          updatedAt: Value(now),
        ),
      );
      await (_db.update(_db.localSessions)
            ..where(
              (t) =>
                  t.tableLocalId.equals(fromLocalId) & t.status.equals('open'),
            ))
          .write(
            LocalSessionsCompanion(
              tableLocalId: Value(toLocalId),
              updatedAt: Value(now),
            ),
          );
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'table_session',
        entityId: fromLocalId,
        operation: 'transfer',
        payload: {
          'table_server_id': fromTableServerId,
          'target_table_id': toTableServerId,
          'session_server_id': from.sessionServerId,
          'session_client_id': sessionClientId,
        },
        clientReference: clientRef,
      );
    });
  }

  /// Merge source session into target table (local-first).
  Future<void> mergeSessionLocal({
    required int workspaceId,
    required String deviceId,
    required int fromTableServerId,
    required int toTableServerId,
  }) async {
    if (fromTableServerId == toTableServerId) {
      throw ArgumentError('target must differ');
    }
    final fromLocalId = tableLocalId(workspaceId, fromTableServerId);
    final toLocalId = tableLocalId(workspaceId, toTableServerId);
    final from = await _requireTable(workspaceId, fromLocalId);
    final to = await _requireTable(workspaceId, toLocalId);
    final fromPayload = _safeMap(from.payloadJson);
    final toPayload = _safeMap(to.payloadJson);
    final clientRef = _newId();
    final now = DateTime.now();

    final orders =
        await (_db.select(_db.localOrders)..where(
              (t) =>
                  t.workspaceId.equals(workspaceId) &
                  t.tableServerId.equals(fromTableServerId) &
                  t.posStatus.isNotValue('cancelled'),
            ))
            .get();

    final mergedPayload = {
      ...toPayload,
      'id': toTableServerId,
      'status': 'occupied',
      'session_open': true,
      'subtotal':
          asDoubleOr(toPayload['subtotal']) +
          asDoubleOr(fromPayload['subtotal']),
      'tax_amount':
          asDoubleOr(toPayload['tax_amount']) +
          asDoubleOr(fromPayload['tax_amount']),
      'discount_amount':
          asDoubleOr(toPayload['discount_amount']) +
          asDoubleOr(fromPayload['discount_amount']),
      'total':
          asDoubleOr(toPayload['total']) + asDoubleOr(fromPayload['total']),
    };
    final clearedFrom = {
      ...fromPayload,
      'id': fromTableServerId,
      'status': 'available',
      'session_open': false,
      'session_id': null,
      'session_client_id': null,
      'orders': const [],
      'subtotal': 0,
      'tax_amount': 0,
      'discount_amount': 0,
      'total': 0,
      'notes': null,
    };

    await _db.transaction(() async {
      for (final order in orders) {
        await (_db.update(
          _db.localOrders,
        )..where((t) => t.localId.equals(order.localId))).write(
          LocalOrdersCompanion(
            tableServerId: Value(toTableServerId),
            tableLocalId: Value(toLocalId),
            updatedAt: Value(now),
          ),
        );
      }
      await (_db.update(
        _db.localTables,
      )..where((t) => t.localId.equals(toLocalId))).write(
        LocalTablesCompanion(
          status: const Value('occupied'),
          payloadJson: Value(jsonEncode(mergedPayload)),
          updatedAt: Value(now),
        ),
      );
      await (_db.update(
        _db.localTables,
      )..where((t) => t.localId.equals(fromLocalId))).write(
        LocalTablesCompanion(
          status: const Value('available'),
          sessionServerId: const Value(null),
          payloadJson: Value(jsonEncode(clearedFrom)),
          updatedAt: Value(now),
        ),
      );
      await _closeOpenLocalSessions(fromLocalId, now);
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'table_session',
        entityId: fromLocalId,
        operation: 'merge',
        payload: {
          'table_server_id': fromTableServerId,
          'target_table_id': toTableServerId,
          'session_server_id': from.sessionServerId,
          'session_client_id': fromPayload['session_client_id'],
        },
        clientReference: clientRef,
      );
    });
  }

  /// Split selected quantities into a new local order on the same table.
  Future<void> splitSessionLocal({
    required int workspaceId,
    required String deviceId,
    required int tableServerId,
    required List<Map<String, dynamic>> moveItems,
  }) async {
    if (moveItems.isEmpty) throw ArgumentError('moveItems required');
    final table = await _requireTableByServerId(workspaceId, tableServerId);
    final localId = table.localId;
    final payload = _safeMap(table.payloadJson);
    final clientRef = _newId();
    final newOrderId = _newId();
    final now = DateTime.now();

    await _db.transaction(() async {
      var newSubtotal = 0;
      for (final move in moveItems) {
        final itemLocalId = '${move['item_local_id'] ?? ''}';
        final qty = asIntOr(move['quantity']);
        if (itemLocalId.isEmpty || qty <= 0) continue;
        final item =
            await (_db.select(_db.localOrderItems)..where(
                  (t) =>
                      t.localId.equals(itemLocalId) &
                      t.workspaceId.equals(workspaceId),
                ))
                .getSingleOrNull();
        if (item == null || item.isRemoved) continue;
        final leave = item.quantity - qty;
        if (leave < 0) continue;
        final unit = item.unitPrice;
        if (leave == 0) {
          await (_db.update(
            _db.localOrderItems,
          )..where((t) => t.localId.equals(itemLocalId))).write(
            LocalOrderItemsCompanion(
              isRemoved: const Value(true),
              quantity: const Value(0),
              totalAmount: const Value(0),
              updatedAt: Value(now),
            ),
          );
        } else {
          await (_db.update(
            _db.localOrderItems,
          )..where((t) => t.localId.equals(itemLocalId))).write(
            LocalOrderItemsCompanion(
              quantity: Value(leave),
              totalAmount: Value(leave * unit),
              updatedAt: Value(now),
            ),
          );
        }
        final movedLocalId = _newId();
        final lineTotal = qty * unit;
        newSubtotal += lineTotal;
        await _db
            .into(_db.localOrderItems)
            .insert(
              LocalOrderItemsCompanion.insert(
                localId: movedLocalId,
                workspaceId: workspaceId,
                orderLocalId: newOrderId,
                productServerId: Value(item.productServerId),
                productLocalId: Value(item.productLocalId),
                name: item.name,
                quantity: qty,
                unitPrice: unit,
                totalAmount: lineTotal,
                updatedAt: now,
              ),
            );
      }

      await _db
          .into(_db.localOrders)
          .insert(
            LocalOrdersCompanion.insert(
              localId: newOrderId,
              workspaceId: workspaceId,
              deviceId: deviceId.trim(),
              clientReference: newOrderId,
              orderType: 'table',
              tableServerId: Value(tableServerId),
              tableLocalId: Value(localId),
              notes: const Value('تقسيم حساب (محلي)'),
              subtotal: Value(newSubtotal),
              taxAmount: const Value(0),
              discountAmount: const Value(0),
              totalAmount: Value(newSubtotal),
              posStatus: const Value('new'),
              paymentStatus: const Value('unpaid'),
              syncStatus: const Value('pending'),
              createdAt: now,
              updatedAt: now,
            ),
          );
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'order',
        entityId: newOrderId,
        operation: 'create',
        payload: {
          'order_type': 'table',
          'table_id': tableServerId,
          'client_reference': newOrderId,
          'notes': 'تقسيم حساب (محلي)',
          'items': [
            for (final move in moveItems)
              if (asInt(move['quantity']) != null)
                {
                  'pos_menu_item_id': move['pos_menu_item_id'],
                  'quantity': move['quantity'],
                  'unit_price': move['unit_price'],
                },
          ],
        },
        clientReference: newOrderId,
      );
      await _queue.enqueue(
        workspaceId: workspaceId,
        deviceId: deviceId.trim(),
        entityType: 'table_session',
        entityId: localId,
        operation: 'split',
        payload: {
          'table_server_id': tableServerId,
          'session_server_id': table.sessionServerId,
          'session_client_id': payload['session_client_id'],
          'new_order_local_id': newOrderId,
          'move_items': moveItems,
        },
        clientReference: clientRef,
      );
    });
  }

  Future<LocalTable> _requireTable(int workspaceId, String localId) async {
    final table =
        await (_db.select(_db.localTables)..where(
              (t) =>
                  t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
            ))
            .getSingleOrNull();
    if (table == null) {
      throw StateError('الطاولة غير متاحة محليًا.');
    }
    return table;
  }

  /// Best-effort remote refresh. UI must not branch on connectivity —
  /// always returns the best local snapshot after attempting update.
  Future<List<Map<String, dynamic>>> loadBoard(int workspaceId) async {
    final local = await listTables(workspaceId);
    if (AppConfig.offlineOnly) return local;
    final api = _api;
    if (api == null || workspaceId <= 0) return local;
    try {
      final data = await api.get('/tables');
      final list = <Map<String, dynamic>>[];
      if (data['tables'] is List) {
        for (final item in data['tables'] as List) {
          if (item is Map) list.add(Map<String, dynamic>.from(item));
        }
      }
      if (list.isNotEmpty) {
        await replaceBoard(workspaceId, list);
        return await listTables(workspaceId);
      }
    } catch (_) {
      // Keep local SQLite as source of truth for UI.
    }
    return local;
  }

  /// Load one table for detail UI: local first, then best-effort remote upsert.
  Future<Map<String, dynamic>?> loadTableDetail(
    int workspaceId,
    int tableServerId,
  ) async {
    final local = await getTable(workspaceId, tableServerId);
    if (AppConfig.offlineOnly) return local;
    final api = _api;
    if (api == null || workspaceId <= 0) return local;
    try {
      final detail = await api.get('/tables/$tableServerId');
      await upsertTableDetail(workspaceId, tableServerId, detail);
      // Refresh board list snapshot when available.
      try {
        final board = await api.get('/tables');
        if (board['tables'] is List) {
          final list = <Map<String, dynamic>>[];
          for (final item in board['tables'] as List) {
            if (item is Map) list.add(Map<String, dynamic>.from(item));
          }
          if (list.isNotEmpty) {
            await replaceBoard(workspaceId, list);
          }
        }
      } catch (_) {}
      return await getTable(workspaceId, tableServerId) ?? detail;
    } catch (_) {
      return local;
    }
  }

  bool _payloadSessionOpen(Map<String, dynamic> payload) {
    if (payload['session_open'] == true) return true;
    return '${payload['session_client_id'] ?? ''}'.trim().isNotEmpty;
  }

  /// Open [LocalSessions] is the source of truth. Legacy occupied rows
  /// without a session row stay occupied for compatibility.
  bool _isOccupiedFromSession({
    required LocalTable row,
    required Map<String, dynamic> payload,
    required String currentSessionLocalId,
    required bool hasLiveOrders,
  }) {
    if (currentSessionLocalId.trim().isNotEmpty) return true;
    if (hasLiveOrders) return true;
    if (row.status == 'occupied' && _payloadSessionOpen(payload)) return true;
    if (row.status == 'occupied' &&
        '${payload['session_id'] ?? ''}'.trim().isNotEmpty) {
      return true;
    }
    return false;
  }

  Map<String, dynamic> _rowToBoardMap(
    LocalTable row, {
    List<LocalOrder> sessionOrders = const [],
    String currentSessionLocalId = '',
  }) {
    final payload = _safeMap(row.payloadJson);
    final occupied = _isOccupiedFromSession(
      row: row,
      payload: payload,
      currentSessionLocalId: currentSessionLocalId,
      hasLiveOrders: sessionOrders.isNotEmpty,
    );
    final sessionClientId = currentSessionLocalId.trim().isNotEmpty
        ? currentSessionLocalId.trim()
        : '${payload['session_client_id'] ?? ''}'.trim();
    final openedAt = parseOpenedAt(payload['opened_at']);
    final activeOrders = !occupied
        ? const <Map<String, dynamic>>[]
        : mergeTableSessionOrders(
            sessionOrders: filterOrdersForOpenTableSession(
              orders: _normalizeActiveOrders(payload['orders']),
              openedAt: openedAt,
              currentSessionLocalId: currentSessionLocalId,
            ),
            liveOrders: [
              for (final order in sessionOrders) _orderIdentityMap(order),
            ],
          );
    final totalCents = sessionOrders.fold<int>(
      0,
      (sum, order) => sum + order.totalAmount,
    );
    final lastSale = asDouble(payload['last_sale_total'] ?? payload['total']);
    final mergedTotal = activeOrders.fold<double>(
      0,
      (sum, order) => sum + asDoubleOr(order['total_amount']),
    );
    return {
      ...payload,
      'id': row.serverId ?? boardNumericId(row) ?? row.localId,
      'local_id': row.localId,
      'name': row.name,
      'status': occupied ? 'occupied' : 'available',
      'capacity': row.capacity,
      'session_id':
          row.sessionServerId ??
          payload['session_id'] ??
          (sessionClientId.isEmpty ? null : sessionClientId),
      'session_client_id': sessionClientId.isEmpty
          ? payload['session_client_id']
          : sessionClientId,
      'session_open': occupied,
      'opened_at': payload['opened_at'],
      'workspace_id': row.workspaceId,
      'orders_count': activeOrders.length,
      'open_orders_count': activeOrders.length,
      'items_count': tableSessionItemsCount(activeOrders),
      if (activeOrders.isNotEmpty)
        'total': mergedTotal > 0
            ? mergedTotal
            : (totalCents > 0
                  ? Money.fromCents(totalCents)
                  : (lastSale ?? payload['total']))
      else if (occupied && lastSale != null && lastSale > 0)
        'total': lastSale
      else
        'total': 0,
    };
  }

  Map<String, dynamic> _rowToDetailMap(
    LocalTable row, {
    List<LocalOrder> liveOrders = const [],
    List<Map<String, dynamic>> liveOrderMaps = const [],
    String currentSessionLocalId = '',
  }) {
    final payload = _safeMap(row.payloadJson);
    final occupied = _isOccupiedFromSession(
      row: row,
      payload: payload,
      currentSessionLocalId: currentSessionLocalId,
      hasLiveOrders: liveOrders.isNotEmpty,
    );
    final sessionClientId = currentSessionLocalId.trim().isNotEmpty
        ? currentSessionLocalId.trim()
        : '${payload['session_client_id'] ?? ''}'.trim();
    final lastSale = asDouble(payload['last_sale_total'] ?? payload['total']);
    final openedAt = parseOpenedAt(payload['opened_at']);
    final activeOrders = !occupied
        ? const <Map<String, dynamic>>[]
        : mergeTableSessionOrders(
            sessionOrders: filterOrdersForOpenTableSession(
              orders: _normalizeActiveOrders(payload['orders']),
              openedAt: openedAt,
              currentSessionLocalId: currentSessionLocalId,
            ),
            liveOrders: liveOrderMaps,
          );
    final totalCents = liveOrders.fold<int>(
      0,
      (sum, order) => sum + order.totalAmount,
    );
    final mergedTotal = activeOrders.fold<double>(
      0,
      (sum, order) => sum + asDoubleOr(order['total_amount']),
    );
    return {
      ...payload,
      'id': row.serverId ?? boardNumericId(row) ?? row.localId,
      'local_id': row.localId,
      'name': row.name.isNotEmpty ? row.name : '${payload['name'] ?? ''}',
      'status': occupied ? 'occupied' : 'available',
      'capacity': row.capacity ?? payload['capacity'],
      'session_id':
          row.sessionServerId ??
          payload['session_id'] ??
          (sessionClientId.isEmpty ? null : sessionClientId),
      'session_client_id': sessionClientId.isEmpty
          ? payload['session_client_id']
          : sessionClientId,
      'session_open': occupied,
      'opened_at': payload['opened_at'],
      'workspace_id': row.workspaceId,
      'orders_count': activeOrders.length,
      'open_orders_count': activeOrders.length,
      'items_count': tableSessionItemsCount(activeOrders),
      if (activeOrders.isNotEmpty)
        'total': mergedTotal > 0
            ? mergedTotal
            : (payload['total'] ??
                  (totalCents > 0 ? Money.fromCents(totalCents) : lastSale))
      else if (occupied && lastSale != null && lastSale > 0)
        'total': lastSale
      else
        'total': 0,
      'orders': activeOrders,
    };
  }

  Future<List<Map<String, dynamic>>> _mapsForOrders(
    List<LocalOrder> orders,
  ) async {
    final out = <Map<String, dynamic>>[];
    for (final order in orders) {
      final items =
          await (_db.select(_db.localOrderItems)..where(
                (t) =>
                    t.orderLocalId.equals(order.localId) &
                    t.isRemoved.equals(false),
              ))
              .get();
      out.add({
        'id': order.serverId ?? order.localId,
        'local_id': order.localId,
        'order_number': order.orderNumber ?? order.localId,
        'pos_status': order.posStatus,
        'payment_status': order.paymentStatus,
        'session_local_id': order.sessionLocalId,
        'created_at': order.createdAt.toUtc().toIso8601String(),
        'discount_amount': Money.fromCents(order.discountAmount),
        'tax_amount': Money.fromCents(order.taxAmount),
        'total_amount': Money.fromCents(order.totalAmount),
        'items': [
          for (final item in items)
            _lineSnapshot({
              'product_local_id': item.productLocalId,
              'item_name': item.name,
              'quantity': item.quantity,
              'unit_price': Money.fromCents(item.unitPrice),
              'total_amount': Money.fromCents(item.totalAmount),
            }),
        ],
      });
    }
    return out;
  }

  Map<String, dynamic> _checkoutSessionOrder({
    required String invoiceLocalId,
    required String invoiceNumber,
    String? orderLocalId,
    required double total,
    required List<Map<String, dynamic>> items,
    required String sessionClientId,
    required Object? createdAt,
  }) {
    final lines = [for (final item in items) _lineSnapshot(item)];
    final lineTotal = lines.fold<double>(
      0,
      (sum, line) => sum + asDoubleOr(line['total_amount']),
    );
    return {
      'id': orderLocalId ?? invoiceLocalId,
      'local_id': orderLocalId ?? invoiceLocalId,
      'invoice_local_id': invoiceLocalId,
      'order_number': invoiceNumber,
      'pos_status': 'completed',
      'payment_status': 'paid',
      'session_client_id': sessionClientId,
      'created_at': createdAt,
      'total_amount': total > 0 ? total : lineTotal,
      'items': lines,
    };
  }

  List<Map<String, dynamic>> _normalizeActiveOrders(dynamic raw) {
    final rows = asMapList(raw);
    if (rows.isEmpty) return const [];
    if (looksLikeFlatOrderLines(rows)) {
      final lines = [for (final row in rows) _lineSnapshot(row)];
      final total = lines.fold<double>(
        0,
        (sum, line) => sum + asDoubleOr(line['total_amount']),
      );
      return [
        {
          'order_number': 'الطلب الحالي',
          'pos_status': 'completed',
          'payment_status': 'paid',
          'total_amount': total,
          'items': lines,
        },
      ];
    }
    return [
      for (final row in rows)
        {
          ...row,
          'items': [
            for (final item in asMapList(row['items'])) _lineSnapshot(item),
          ],
        },
    ];
  }

  Map<String, dynamic> _lineSnapshot(Map<String, dynamic> item) {
    final name = catalogItemName(item);
    final qty = asIntOr(item['quantity'], 1);
    final unit = asDoubleOr(item['unit_price']);
    final total =
        asDouble(item['total_amount'] ?? item['total']) ?? (unit * qty);
    final productLocalId =
        '${item['product_local_id'] ?? item['productLocalId'] ?? ''}'.trim();
    return {
      if (productLocalId.isNotEmpty) 'product_local_id': productLocalId,
      'item_name': name,
      'product_name': name,
      'name': name,
      'quantity': qty,
      'unit_price': unit,
      'total_amount': total,
    };
  }

  Future<List<LocalOrder>> _nonCancelledOrdersInWorkspace(int workspaceId) {
    return (_db.select(_db.localOrders)..where(
          (t) =>
              t.workspaceId.equals(workspaceId) &
              t.posStatus.isNotValue('cancelled'),
        ))
        .get();
  }

  Future<List<LocalOrder>> _unpaidOrdersInWorkspace(int workspaceId) {
    return (_db.select(_db.localOrders)..where(
          (t) =>
              t.workspaceId.equals(workspaceId) &
              t.posStatus.isNotValue('cancelled') &
              t.paymentStatus.isNotValue('paid') &
              t.posStatus.isNotValue('completed'),
        ))
        .get();
  }

  Future<List<LocalOrder>> _unpaidOrdersForTable(LocalTable table) async {
    final all = await _unpaidOrdersInWorkspace(table.workspaceId);
    return _ordersMatchingTable(table, all);
  }

  Future<List<LocalOrder>> _liveSessionOrdersForTable(
    LocalTable table, {
    String? currentSessionLocalId,
  }) async {
    final all = await _nonCancelledOrdersInWorkspace(table.workspaceId);
    return _sessionOrdersMatchingTable(
      table,
      all,
      currentSessionLocalId:
          currentSessionLocalId ?? await _openSessionLocalId(table) ?? '',
    );
  }

  List<LocalOrder> _sessionOrdersMatchingTable(
    LocalTable table,
    List<LocalOrder> orders, {
    required String currentSessionLocalId,
  }) {
    final payload = _safeMap(table.payloadJson);
    final openedAt = parseOpenedAt(payload['opened_at']);
    return [
      for (final order in _ordersMatchingTable(table, orders))
        if (isOrderInOpenTableSession(
          posStatus: order.posStatus,
          paymentStatus: order.paymentStatus,
          createdAt: order.createdAt,
          openedAt: openedAt,
          completedAt: order.completedAt,
          orderSessionLocalId: order.sessionLocalId,
          currentSessionLocalId: currentSessionLocalId,
        ))
          order,
    ];
  }

  Map<String, dynamic> _orderIdentityMap(LocalOrder order) => {
    'local_id': order.localId,
    'id': order.serverId ?? order.localId,
    'order_number': order.orderNumber ?? order.localId,
    'pos_status': order.posStatus,
    'payment_status': order.paymentStatus,
    'session_local_id': order.sessionLocalId,
    'created_at': order.createdAt.toUtc().toIso8601String(),
    'total_amount': Money.fromCents(order.totalAmount),
  };

  List<LocalOrder> _ordersMatchingTable(
    LocalTable table,
    List<LocalOrder> orders,
  ) {
    final sid = table.serverId;
    return [
      for (final order in orders)
        if (order.tableLocalId == table.localId ||
            (sid != null && order.tableServerId == sid))
          order,
    ];
  }

  Map<String, dynamic> _mergePayload(
    String? previousJson,
    Map<String, dynamic> boardRow,
  ) {
    final previous = previousJson == null
        ? const <String, dynamic>{}
        : _safeMap(previousJson);
    final merged = <String, dynamic>{...previous, ...boardRow};
    // Keep detailed orders/totals if an occupied board snapshot omits them.
    // Never restore historical lines onto an available table.
    if (boardRow['orders'] == null && previous['orders'] != null) {
      final incomingStatus =
          '${boardRow['status'] ?? previous['status'] ?? ''}';
      final incomingSessionOpen = (asInt(boardRow['session_id']) ?? 0) > 0;
      merged['orders'] = incomingStatus == 'available' && !incomingSessionOpen
          ? const []
          : previous['orders'];
    }
    if (boardRow['subtotal'] == null && previous['subtotal'] != null) {
      merged['subtotal'] = previous['subtotal'];
    }
    if (boardRow['tax_amount'] == null && previous['tax_amount'] != null) {
      merged['tax_amount'] = previous['tax_amount'];
    }
    if (boardRow['discount_amount'] == null &&
        previous['discount_amount'] != null) {
      merged['discount_amount'] = previous['discount_amount'];
    }
    if (boardRow['total'] == null && previous['total'] != null) {
      merged['total'] = previous['total'];
    }
    if (boardRow['notes'] == null && previous['notes'] != null) {
      merged['notes'] = previous['notes'];
    }
    if (boardRow['session_client_id'] == null &&
        previous['session_client_id'] != null) {
      merged['session_client_id'] = previous['session_client_id'];
    }
    return merged;
  }

  Map<String, dynamic> _safeMap(String raw) {
    if (raw.isEmpty) return const {};
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
    } catch (_) {}
    return const {};
  }
}
