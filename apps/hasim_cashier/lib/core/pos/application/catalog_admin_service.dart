import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../../local_db/app_database.dart';
import '../../repositories/sync_queue_repository.dart';
import '../../util/json_numbers.dart';
import '../domain/pricing_service.dart';
import '../pos_errors.dart';
import '../pos_mode.dart';
import '../pos_permissions.dart';

class CatalogAdminService {
  CatalogAdminService(
    this._db, {
    String Function()? newId,
    SyncQueueRepository? queue,
    Future<String> Function()? deviceId,
  }) : _newId = newId ?? (() => const Uuid().v4()),
       _queue = queue,
       _deviceId = deviceId;

  final AppDatabase _db;
  final String Function() _newId;
  final SyncQueueRepository? _queue;
  final Future<String> Function()? _deviceId;

  Future<String> createCategory({
    required int workspaceId,
    required String name,
    int sortOrder = 0,
    bool isActive = true,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.catalog);
    final id = _newId();
    final now = DateTime.now();
    await _db.into(_db.localCategories).insert(
          LocalCategoriesCompanion.insert(
            localId: id,
            workspaceId: workspaceId,
            name: name.trim(),
            sortOrder: Value(sortOrder),
            isActive: Value(isActive),
            createdAt: Value(now),
            updatedAt: now,
          ),
        );
    await _queueCategory(workspaceId, id, 'create');
    return id;
  }

  Future<void> updateCategory({
    required int workspaceId,
    required String localId,
    String? name,
    bool? isActive,
    int? sortOrder,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.catalog);
    final row = await _category(workspaceId, localId);
    if (row == null) {
      throw const DatabaseFailure('التصنيف غير موجود محلياً.');
    }
    await (_db.update(_db.localCategories)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalCategoriesCompanion(
            name: name == null ? const Value.absent() : Value(name.trim()),
            isActive: isActive == null ? const Value.absent() : Value(isActive),
            sortOrder: sortOrder == null
                ? const Value.absent()
                : Value(sortOrder),
            updatedAt: Value(DateTime.now()),
          ),
        );
    await _queueCategory(workspaceId, localId, 'update');
  }

  Future<void> deleteCategory({
    required int workspaceId,
    required String localId,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.catalog);
    final row = await _category(workspaceId, localId);
    if (row == null) {
      throw const DatabaseFailure('التصنيف غير موجود محلياً.');
    }
    final linked = await (_db.select(_db.localProducts)..where(
          (t) =>
              t.workspaceId.equals(workspaceId) &
              t.categoryLocalId.equals(localId) &
              t.isDeleted.equals(false),
        ))
        .get();
    if (linked.isNotEmpty) {
      throw const DatabaseFailure(
        'لا يمكن حذف تصنيف مرتبط بأصناف. انقل الأصناف أولاً.',
      );
    }
    await (_db.update(_db.localCategories)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalCategoriesCompanion(
            isDeleted: const Value(true),
            isActive: const Value(false),
            updatedAt: Value(DateTime.now()),
          ),
        );
    await _queueCategory(workspaceId, localId, 'delete');
  }

  Future<String> createProduct({
    required int workspaceId,
    required String name,
    required double price,
    String? categoryLocalId,
    String? sku,
    String? barcode,
    double cost = 0,
    double taxRate = 0,
    int? stock,
    bool trackStock = false,
    String? imagePath,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.catalog);
    final id = _newId();
    final now = DateTime.now();
    await _db.into(_db.localProducts).insert(
          LocalProductsCompanion.insert(
            localId: id,
            workspaceId: workspaceId,
            categoryLocalId: Value(categoryLocalId),
            name: name.trim(),
            sku: Value(sku),
            barcode: Value(barcode),
            price: Value(Money.toCents(price)),
            cost: Value(Money.toCents(cost)),
            taxRate: Value(taxRate),
            stock: Value(stock),
            trackStock: Value(trackStock || stock != null),
            imagePath: Value(imagePath),
            createdAt: Value(now),
            updatedAt: now,
          ),
        );
    await _queueProduct(workspaceId, id, 'create');
    return id;
  }

  Future<void> updateProduct({
    required int workspaceId,
    required String localId,
    String? name,
    double? price,
    double? cost,
    String? sku,
    String? barcode,
    int? stock,
    bool? trackStock,
    bool? isActive,
    String? categoryLocalId,
    String? imagePath,
    bool clearImage = false,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.catalog);
    await (_db.update(_db.localProducts)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalProductsCompanion(
            name: name == null ? const Value.absent() : Value(name),
            price: price == null
                ? const Value.absent()
                : Value(Money.toCents(price)),
            cost: cost == null
                ? const Value.absent()
                : Value(Money.toCents(cost)),
            sku: sku == null ? const Value.absent() : Value(sku),
            barcode: barcode == null ? const Value.absent() : Value(barcode),
            stock: stock == null ? const Value.absent() : Value(stock),
            trackStock: trackStock == null
                ? const Value.absent()
                : Value(trackStock),
            isActive: isActive == null ? const Value.absent() : Value(isActive),
            categoryLocalId: categoryLocalId == null
                ? const Value.absent()
                : Value(categoryLocalId),
            imagePath: clearImage
                ? const Value(null)
                : imagePath == null
                    ? const Value.absent()
                    : Value(imagePath),
            updatedAt: Value(DateTime.now()),
          ),
        );
    await _queueProduct(workspaceId, localId, 'update');
  }

  Future<Map<String, dynamic>?> findByBarcode({
    required int workspaceId,
    required String barcode,
  }) async {
    final q = barcode.trim();
    if (q.isEmpty) return null;
    final row = await (_db.select(_db.localProducts)..where(
          (t) =>
              t.workspaceId.equals(workspaceId) &
              t.isDeleted.equals(false) &
              t.isActive.equals(true) &
              (t.barcode.equals(q) | t.sku.equals(q)),
        ))
        .getSingleOrNull();
    if (row == null) return null;
    return {
      'id': row.localId,
      'local_id': row.localId,
      'name': row.name,
      'price': Money.fromCents(row.price),
      'barcode': row.barcode,
      'sku': row.sku,
      'stock': row.stock,
      'tax_rate': row.taxRate,
      'cost': Money.fromCents(row.cost),
      'image_path': row.imagePath,
    };
  }

  Future<void> deleteProduct({
    required int workspaceId,
    required String localId,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.catalog);
    final row = await _product(workspaceId, localId);
    if (row == null) {
      throw const DatabaseFailure('الصنف غير موجود محلياً.');
    }
    await (_db.update(_db.localProducts)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalProductsCompanion(
            isDeleted: const Value(true),
            isActive: const Value(false),
            updatedAt: Value(DateTime.now()),
          ),
        );
    await _queueProduct(workspaceId, localId, 'delete');
  }

  Future<String> createTable({
    required int workspaceId,
    required String name,
    String? number,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.tablesCreate);
    final trimmed = name.trim();
    final existing = await (_db.select(_db.localTables)
          ..where((t) => t.workspaceId.equals(workspaceId)))
        .get();
    final boardId = _nextBoardId(existing);
    final localId = _newId();
    final now = DateTime.now();
    await _db.into(_db.localTables).insert(
          LocalTablesCompanion.insert(
            localId: localId,
            workspaceId: workspaceId,
            name: trimmed,
            tableNumber: Value(number ?? trimmed),
            status: const Value('available'),
            payloadJson: Value(
              jsonEncode({
                'board_id': boardId,
                'name': trimmed,
                'status': 'available',
              }),
            ),
            createdAt: Value(now),
            updatedAt: now,
          ),
        );
    await _queueTable(workspaceId, localId, 'create');
    return localId;
  }

  Future<void> updateTable({
    required int workspaceId,
    required String localId,
    required String name,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.tablesEdit);
    final trimmed = name.trim();
    if (trimmed.isEmpty) return;
    final row = await _table(workspaceId, localId);
    if (row == null) return;
    Map<String, dynamic> payload = const {};
    try {
      final decoded = jsonDecode(row.payloadJson);
      if (decoded is Map) payload = Map<String, dynamic>.from(decoded);
    } catch (_) {}
    payload['name'] = trimmed;
    await (_db.update(_db.localTables)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .write(
          LocalTablesCompanion(
            name: Value(trimmed),
            tableNumber: Value(trimmed),
            payloadJson: Value(jsonEncode(payload)),
            updatedAt: Value(DateTime.now()),
          ),
        );
    await _queueTable(workspaceId, localId, 'update');
  }

  Future<void> deleteTable({
    required int workspaceId,
    required String localId,
    Map<String, dynamic>? permissions,
  }) async {
    PosPermissions.require(permissions, PosPermissions.tablesDelete);
    final openSessions = await (_db.select(_db.localSessions)..where(
          (t) =>
              t.tableLocalId.equals(localId) &
              t.workspaceId.equals(workspaceId) &
              t.status.equals('open'),
        ))
        .get();
    if (openSessions.isNotEmpty) {
      throw const DatabaseFailure(
        'لا يمكن حذف طاولة عليها جلسة مفتوحة. أغلق الطاولة أولاً.',
      );
    }
    final row = await _table(workspaceId, localId);
    if (row == null) return;
    await _queueTable(workspaceId, localId, 'delete');
    await (_db.delete(_db.localSessions)..where(
          (t) =>
              t.tableLocalId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .go();
    await (_db.delete(_db.localTables)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .go();
  }

  Future<LocalCategory?> _category(int workspaceId, String localId) {
    return (_db.select(_db.localCategories)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .getSingleOrNull();
  }

  Future<LocalProduct?> _product(int workspaceId, String localId) {
    return (_db.select(_db.localProducts)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .getSingleOrNull();
  }

  Future<LocalTable?> _table(int workspaceId, String localId) {
    return (_db.select(_db.localTables)..where(
          (t) => t.localId.equals(localId) & t.workspaceId.equals(workspaceId),
        ))
        .getSingleOrNull();
  }

  int _nextBoardId(List<LocalTable> existing) {
    var next = 1;
    for (final row in existing) {
      final board = _boardNumericId(row);
      if (board != null && board >= next) next = board + 1;
    }
    return next;
  }

  static int? _boardNumericId(LocalTable row) {
    if (row.serverId != null && row.serverId! > 0) return row.serverId;
    try {
      final decoded = jsonDecode(row.payloadJson);
      if (decoded is Map) {
        return asInt(decoded['board_id']) ?? asInt(decoded['id']);
      }
    } catch (_) {}
    return null;
  }

  Future<void> _queueCategory(
    int workspaceId,
    String localId,
    String operation,
  ) async {
    final row = await _category(workspaceId, localId);
    if (row == null) return;
    await _enqueueMaster(
      workspaceId: workspaceId,
      entityType: 'category',
      entityId: localId,
      operation: operation,
      serverId: row.serverId,
      payload: {
        'client_reference': localId,
        'name': row.name,
        'is_active': row.isActive,
        'sort_order': row.sortOrder,
        if (row.serverId != null && row.serverId! > 0) 'server_id': row.serverId,
      },
    );
  }

  Future<void> _queueProduct(
    int workspaceId,
    String localId,
    String operation,
  ) async {
    final row = await _product(workspaceId, localId);
    if (row == null) return;
    int? categoryServerId = row.categoryServerId;
    final categoryLocalId = row.categoryLocalId?.trim();
    if ((categoryServerId == null || categoryServerId <= 0) &&
        categoryLocalId != null &&
        categoryLocalId.isNotEmpty) {
      final category = await _category(workspaceId, categoryLocalId);
      categoryServerId = category?.serverId;
    }
    await _enqueueMaster(
      workspaceId: workspaceId,
      entityType: 'product',
      entityId: localId,
      operation: operation,
      serverId: row.serverId,
      payload: {
        'client_reference': localId,
        'name': row.name,
        'price': Money.fromCents(row.price),
        'currency': await _storeCurrency(workspaceId),
        'sku': row.sku,
        'barcode': row.barcode,
        'is_active': row.isActive,
        if (categoryLocalId != null && categoryLocalId.isNotEmpty)
          'category_local_id': categoryLocalId,
        if (categoryServerId != null && categoryServerId > 0)
          'pos_item_category_id': categoryServerId,
        if (row.serverId != null && row.serverId! > 0) 'server_id': row.serverId,
      },
    );
  }

  Future<void> _queueTable(
    int workspaceId,
    String localId,
    String operation,
  ) async {
    final row = await _table(workspaceId, localId);
    if (row == null) return;
    await _enqueueMaster(
      workspaceId: workspaceId,
      entityType: 'table',
      entityId: localId,
      operation: operation,
      serverId: row.serverId,
      payload: {
        'client_reference': localId,
        'name': row.name,
        if (row.serverId != null && row.serverId! > 0) ...{
          'server_id': row.serverId,
          'table_server_id': row.serverId,
        },
      },
    );
  }

  Future<void> _enqueueMaster({
    required int workspaceId,
    required String entityType,
    required String entityId,
    required String operation,
    required Map<String, dynamic> payload,
    int? serverId,
  }) async {
    final queue = _queue;
    if (queue == null) return;
    if (PosMode.isReservedStandaloneWorkspace(workspaceId)) return;
    final deviceId = ((await _deviceId?.call()) ?? '').trim();
    if (deviceId.isEmpty) return;

    final createOpen = await queue.findOpenOp(
      workspaceId: workspaceId,
      entityType: entityType,
      entityId: entityId,
      operation: 'create',
    );
    if (createOpen?.status == 'syncing' ||
        (operation != 'create' &&
            (await queue.findOpenOp(
                  workspaceId: workspaceId,
                  entityType: entityType,
                  entityId: entityId,
                  operation: operation,
                ))
                    ?.status ==
                'syncing')) {
      throw const DatabaseFailure(
        'العملية قيد المزامنة. حاول بعد ثوانٍ.',
      );
    }

    if (operation == 'delete') {
      if (createOpen != null) {
        await queue.cancelOpenOp(
          workspaceId: workspaceId,
          entityType: entityType,
          entityId: entityId,
          operation: 'create',
        );
        await queue.cancelOpenOp(
          workspaceId: workspaceId,
          entityType: entityType,
          entityId: entityId,
          operation: 'update',
        );
        return;
      }
      await queue.cancelOpenOp(
        workspaceId: workspaceId,
        entityType: entityType,
        entityId: entityId,
        operation: 'update',
      );
      if (serverId == null || serverId <= 0) return;
    }

    if (operation == 'update' && createOpen != null) {
      await queue.updateOpenPayload(
        workspaceId: workspaceId,
        entityType: entityType,
        entityId: entityId,
        operation: 'create',
        payload: payload,
      );
      return;
    }

    if (operation != 'create' && (serverId == null || serverId <= 0)) {
      return;
    }

    final existing = await queue.findOpenOp(
      workspaceId: workspaceId,
      entityType: entityType,
      entityId: entityId,
      operation: operation,
    );
    if (existing != null) {
      await queue.updateOpenPayload(
        workspaceId: workspaceId,
        entityType: entityType,
        entityId: entityId,
        operation: operation,
        payload: payload,
      );
      return;
    }

    await queue.enqueue(
      workspaceId: workspaceId,
      deviceId: deviceId,
      entityType: entityType,
      entityId: entityId,
      operation: operation,
      payload: payload,
      clientReference: entityId,
    );
  }

  Future<String> _storeCurrency(int workspaceId) async {
    final store = await (_db.select(
      _db.localStores,
    )..where((t) => t.workspaceId.equals(workspaceId))).getSingleOrNull();
    final currency = store?.currency.trim().toUpperCase() ?? '';
    if (RegExp(r'^[A-Z]{3}$').hasMatch(currency)) return currency;
    return 'SAR';
  }
}
