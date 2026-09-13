import 'dart:convert';

import 'package:drift/drift.dart';

import '../api/cashier_api.dart';
import '../config/app_config.dart';
import '../pos/domain/pricing_service.dart';
import '../pos/pos_mode.dart';
import 'app_database.dart';
import 'local_ids.dart';
import 'workspace_scope.dart';

/// Downloads workspace POS baseline into SQLite using existing Cashier APIs.
///
/// Order: bootstrap → categories → ALL product pages → tables → integer
/// cursor anchor (`POST /sync/pull` with `limit: 0`). Does not apply
/// changelog events and does not mix Laravel data into workspace 900001.
class InitialSyncService {
  InitialSyncService(this._db, this._api, {this.deviceId});

  static const catalogPageSize = 100;
  static const tablesPageSize = 100;
  static const maxPages = 1000;

  final AppDatabase _db;
  final CashierApiClient _api;
  final String? deviceId;

  Future<InitialSyncResult> ensureReady(int workspaceId) async {
    if (workspaceId <= 0) {
      return const InitialSyncResult(
        ready: false,
        message: 'لا توجد مساحة عمل محددة.',
      );
    }
    if (await _db.isOfflinePosReady(workspaceId)) {
      return InitialSyncResult(
        ready: true,
        message: 'Local DB جاهزة',
        fromCache: true,
        productCount: await _db.productCount(workspaceId),
        categoryCount: await _db.categoryCount(workspaceId),
        tableCount: await _db.tableCount(workspaceId),
      );
    }
    if (AppConfig.offlineOnly) {
      return InitialSyncResult(
        ready: true,
        message: 'وضع أوفلاين — بدون مزامنة من الخادم',
        fromCache: true,
        productCount: await _db.productCount(workspaceId),
        categoryCount: await _db.categoryCount(workspaceId),
        tableCount: await _db.tableCount(workspaceId),
      );
    }
    return run(workspaceId);
  }

  /// Online snapshot. Allowed during first-connect even when [AppConfig.offlineOnly]
  /// is true — the HTTP gate is [CashierNetworkPolicy], not this flag.
  Future<InitialSyncResult> run(
    int workspaceId, {
    String? deviceId,
  }) async {
    if (workspaceId <= 0) {
      throw ArgumentError('workspaceId required');
    }
    if (PosMode.isReservedStandaloneWorkspace(workspaceId)) {
      throw ApiException(
        'لا يمكن تنزيل بيانات Laravel إلى مساحة العمل المحلية 900001.',
        statusCode: 422,
      );
    }

    final boundDeviceId = (deviceId ?? this.deviceId)?.trim() ?? '';
    final bootstrap = await _get('/bootstrap');
    if (bootstrap['pos_enabled'] != true) {
      throw ApiException(
        'الكاشير غير متاح في باقتك الحالية',
        statusCode: 403,
      );
    }

    final categories = await _fetchAllCategories();
    final items = await _fetchAllItems();
    final tables = await _fetchAllTables();

    final now = DateTime.now();
    await _db.transaction(() async {
      if (boundDeviceId.isNotEmpty) {
        await _db.into(_db.localDevices).insertOnConflictUpdate(
              LocalDevicesCompanion.insert(
                deviceId: boundDeviceId,
                workspaceId: Value(workspaceId),
                name: const Value('كاشير حاسم'),
                registeredAt: Value(now),
                lastSeenAt: Value(now),
              ),
            );
      }

      await _persistCategories(workspaceId, categories, now);
      await _persistProducts(workspaceId, items, now);
      await _persistTables(workspaceId, tables, now);
      await _persistBootstrap(workspaceId, bootstrap, now);
    });

    if (boundDeviceId.isEmpty) {
      throw ApiException('معرّف الجهاز غير صالح.', statusCode: 422);
    }

    final pull = await _post('/sync/pull', {
      'device_id': boundDeviceId,
      'cursor': 0,
      'limit': 0,
    });
    if (pull['changes'] is List && (pull['changes'] as List).isNotEmpty) {
      throw ApiException(
        'مرساة المؤشر أعادت أحداثاً تاريخية. أوقف العملية.',
        statusCode: 422,
      );
    }

    final anchor = SyncCursor.requireInteger(
      pull['server_cursor'] ?? pull['cursor'] ?? 0,
    );

    await _db.transaction(() async {
      await _db.writeCursor(
        workspaceId,
        '$anchor',
        deviceId: boundDeviceId,
      );
      await _db.writeMeta(
        workspaceId,
        SyncMetaKeys.lastPullAt,
        now.toUtc().toIso8601String(),
        deviceId: boundDeviceId,
      );
      await _db.markInitialSyncCompleted(workspaceId, deviceId: boundDeviceId);
    });

    return InitialSyncResult(
      ready: true,
      message: 'اكتمل Initial Sync',
      fromCache: false,
      productCount: items.length,
      categoryCount: categories.length,
      tableCount: tables.length,
      cursor: anchor,
    );
  }

  Future<List<Map<String, dynamic>>> _fetchAllCategories() async {
    final data = await _get('/catalog/categories');
    return _asMapList(data['categories']);
  }

  Future<List<Map<String, dynamic>>> _fetchAllItems() async {
    final items = <Map<String, dynamic>>[];
    var page = 1;
    int? expectedTotal;
    while (page <= maxPages) {
      final data = await _get(
        '/catalog/items',
        query: {
          'page': page,
          'per_page': catalogPageSize,
          'active_only': false,
        },
      );
      final batch = _asMapList(_unwrapList(data['items']));
      items.addAll(batch);
      final meta = _meta(data);
      expectedTotal ??= _asInt(meta['total']);
      final lastPage = _asInt(meta['last_page']);
      if (lastPage != null) {
        if (page >= lastPage) break;
        page += 1;
        continue;
      }
      if (batch.length < catalogPageSize) break;
      page += 1;
    }
    if (page > maxPages) {
      throw ApiException(
        'تجاوز تنزيل الأصناف الحد الأقصى للصفحات.',
        statusCode: 500,
      );
    }
    if (expectedTotal != null && items.length != expectedTotal) {
      throw ApiException(
        'لم يتم تنزيل جميع الأصناف (${items.length} / $expectedTotal).',
        statusCode: 500,
      );
    }
    return items;
  }

  Future<List<Map<String, dynamic>>> _fetchAllTables() async {
    final tables = <Map<String, dynamic>>[];
    var page = 1;
    int? expectedTotal;
    while (page <= maxPages) {
      final data = await _get(
        '/tables',
        query: {
          'page': page,
          'per_page': tablesPageSize,
        },
      );
      final batch = _asMapList(data['tables']);
      tables.addAll(batch);
      final meta = _meta(data);
      expectedTotal ??= _asInt(meta['total']);
      final lastPage = _asInt(meta['last_page']);
      if (lastPage != null) {
        if (page >= lastPage) break;
        page += 1;
        continue;
      }
      if (batch.length < tablesPageSize) break;
      page += 1;
    }
    if (page > maxPages) {
      throw ApiException(
        'تجاوز تنزيل الطاولات الحد الأقصى للصفحات.',
        statusCode: 500,
      );
    }
    if (expectedTotal != null && tables.length != expectedTotal) {
      throw ApiException(
        'لم يتم تنزيل جميع الطاولات (${tables.length} / $expectedTotal).',
        statusCode: 500,
      );
    }
    return tables;
  }

  Future<void> _persistCategories(
    int workspaceId,
    List<Map<String, dynamic>> categories,
    DateTime now,
  ) async {
    final existing = await (_db.select(_db.localCategories)
          ..where((t) => t.workspaceId.equals(workspaceId)))
        .get();
    for (final cat in categories) {
      final serverId = _requireServerId(cat['id'], 'تصنيف');
      var localId = LocalIds.category(workspaceId, serverId);
      for (final row in existing) {
        if (row.serverId == serverId) {
          localId = row.localId;
          break;
        }
      }
      await _db.into(_db.localCategories).insertOnConflictUpdate(
            LocalCategoriesCompanion.insert(
              localId: localId,
              workspaceId: workspaceId,
              serverId: Value(serverId),
              name: '${cat['name'] ?? ''}',
              sortOrder: Value(_asInt(cat['sort_order']) ?? 0),
              isActive: Value(cat['is_active'] != false),
              isDeleted: const Value(false),
              updatedAt: now,
            ),
          );
    }
  }

  Future<void> _persistProducts(
    int workspaceId,
    List<Map<String, dynamic>> items,
    DateTime now,
  ) async {
    final existing = await (_db.select(_db.localProducts)
          ..where((t) => t.workspaceId.equals(workspaceId)))
        .get();
    final categories = await (_db.select(_db.localCategories)
          ..where((t) => t.workspaceId.equals(workspaceId)))
        .get();
    final categoryLocalByServer = <int, String>{
      for (final row in categories)
        if (row.serverId != null) row.serverId!: row.localId,
    };

    for (final item in items) {
      final serverId = _requireServerId(item['id'], 'صنف');
      var localId = LocalIds.product(workspaceId, serverId);
      LocalProduct? previous;
      for (final row in existing) {
        if (row.serverId == serverId || row.localId == localId) {
          previous = row;
          localId = row.localId;
          break;
        }
      }
      final catServerId = _asInt(item['pos_item_category_id']) ??
          _asInt(
            item['category'] is Map ? (item['category'] as Map)['id'] : null,
          );
      final categoryLocalId = catServerId == null
          ? previous?.categoryLocalId
          : categoryLocalByServer[catServerId] ?? previous?.categoryLocalId;
      final productId = _asInt(item['product_id']);
      await _db.into(_db.localProducts).insertOnConflictUpdate(
            LocalProductsCompanion.insert(
              localId: localId,
              workspaceId: workspaceId,
              serverId: Value(serverId),
              categoryLocalId: Value(categoryLocalId),
              categoryServerId: Value(catServerId),
              name: '${item['name'] ?? previous?.name ?? ''}',
              sku: Value(item['sku'] as String? ?? previous?.sku),
              barcode: Value(item['barcode'] as String? ?? previous?.barcode),
              itemType: Value(
                item['item_type'] as String? ?? previous?.itemType,
              ),
              price: Value(Money.toCents(item['price'] ?? 0)),
              isActive: Value(item['is_active'] != false),
              isDeleted: const Value(false),
              payloadJson: Value(jsonEncode(_productPayload(item, serverId))),
              stock: Value(_asInt(item['stock']) ?? previous?.stock),
              trackStock: Value(productId != null),
              createdAt: Value(previous?.createdAt ?? now),
              updatedAt: now,
            ),
          );
    }
  }

  Future<void> _persistTables(
    int workspaceId,
    List<Map<String, dynamic>> tables,
    DateTime now,
  ) async {
    final existing = await (_db.select(_db.localTables)
          ..where((t) => t.workspaceId.equals(workspaceId)))
        .get();
    for (final table in tables) {
      final serverId = _requireServerId(table['id'], 'طاولة');
      var localId = LocalIds.table(workspaceId, serverId);
      LocalTable? previous;
      for (final row in existing) {
        if (row.serverId == serverId || row.localId == localId) {
          previous = row;
          localId = row.localId;
          break;
        }
      }
      final previousPayload = _decodeMap(previous?.payloadJson);
      final hasLocalSession = _hasLocalOperationalState(previousPayload);
      final mergedPayload = mergeTableStructure(
        previousJson: previous?.payloadJson,
        serverTable: table,
      );
      final status = hasLocalSession
          ? (previous?.status ?? 'occupied')
          : (previous?.status ?? 'available');
      await _db.into(_db.localTables).insertOnConflictUpdate(
            LocalTablesCompanion.insert(
              localId: localId,
              workspaceId: workspaceId,
              serverId: Value(serverId),
              name: '${table['name'] ?? previous?.name ?? ''}',
              status: Value(status),
              capacity: Value(_asInt(table['capacity']) ?? previous?.capacity),
              sessionServerId: Value(previous?.sessionServerId),
              tableNumber: Value(
                table['table_number']?.toString() ?? previous?.tableNumber,
              ),
              payloadJson: Value(jsonEncode(mergedPayload)),
              createdAt: Value(previous?.createdAt ?? now),
              updatedAt: now,
            ),
          );
    }
  }

  Future<void> _persistBootstrap(
    int workspaceId,
    Map<String, dynamic> bootstrap,
    DateTime now,
  ) async {
    final settings = <String, dynamic>{};
    if (bootstrap['settings'] is Map) {
      settings.addAll(Map<String, dynamic>.from(bootstrap['settings'] as Map));
    }
    settings['pos_enabled'] = bootstrap['pos_enabled'] == true;
    if (bootstrap['workspace'] is Map) {
      final workspace = Map<String, dynamic>.from(bootstrap['workspace'] as Map);
      settings['workspace_id'] = workspace['id'];
      settings['workspace_name'] = workspace['name'];
    }
    await _db.into(_db.localSettings).insertOnConflictUpdate(
          LocalSettingsCompanion.insert(
            key: 'pos',
            workspaceId: workspaceId,
            valueJson: jsonEncode(settings),
            updatedAt: now,
          ),
        );
    if (bootstrap['entitlements'] is Map) {
      await _db.into(_db.localSettings).insertOnConflictUpdate(
            LocalSettingsCompanion.insert(
              key: 'pos_entitlements',
              workspaceId: workspaceId,
              valueJson: jsonEncode(bootstrap['entitlements']),
              updatedAt: now,
            ),
          );
    }
    if (bootstrap['permissions'] is Map) {
      await _db.into(_db.localSettings).insertOnConflictUpdate(
            LocalSettingsCompanion.insert(
              key: 'pos_permissions',
              workspaceId: workspaceId,
              valueJson: jsonEncode(bootstrap['permissions']),
              updatedAt: now,
            ),
          );
    }

    final permissions = bootstrap['permissions'];
    final userId = bootstrap['user'] is Map
        ? _asInt((bootstrap['user'] as Map)['id'])
        : null;
    if (permissions is Map && userId != null) {
      for (final entry in permissions.entries) {
        await _db.into(_db.localPermissions).insertOnConflictUpdate(
              LocalPermissionsCompanion.insert(
                key: '${entry.key}',
                workspaceId: workspaceId,
                userId: userId,
                allowed: Value(entry.value == true),
                updatedAt: now,
              ),
            );
      }
    }
  }

  /// Server structure only. Local session / occupancy / pending POS stay.
  static Map<String, dynamic> mergeTableStructure({
    String? previousJson,
    required Map<String, dynamic> serverTable,
  }) {
    final previous = _decodeMap(previousJson);
    final merged = Map<String, dynamic>.from(previous);
    merged['id'] = serverTable['id'];
    if (serverTable['name'] != null) merged['name'] = serverTable['name'];
    if (serverTable['qr_token'] != null) {
      merged['qr_token'] = serverTable['qr_token'];
    }
    if (serverTable['menu_url'] != null) {
      merged['menu_url'] = serverTable['menu_url'];
    }
    if (serverTable['capacity'] != null) {
      merged['capacity'] = serverTable['capacity'];
    }
    if (serverTable['table_number'] != null) {
      merged['table_number'] = serverTable['table_number'];
    }
    if (serverTable['is_active'] != null) {
      merged['is_active'] = serverTable['is_active'];
    }
    if (serverTable['is_deleted'] != null) {
      merged['is_deleted'] = serverTable['is_deleted'];
    }
    for (final key in _serverOperationalKeys) {
      if (previous.containsKey(key)) {
        merged[key] = previous[key];
      } else {
        merged.remove(key);
      }
    }
    return merged;
  }

  static const _serverOperationalKeys = <String>{
    'session_id',
    'session_status',
    'session_open',
    'session_client_id',
    'opened_at',
    'customer_name',
    'open_orders_count',
    'orders_count',
    'items_count',
    'lines',
    'orders',
    'sessions',
    'subtotal',
    'discount_amount',
    'tax_amount',
    'total',
    'notes',
  };

  static bool _hasLocalOperationalState(Map<String, dynamic> payload) {
    return payload['session_client_id'] != null ||
        payload['session_open'] == true ||
        (payload['orders'] is List && (payload['orders'] as List).isNotEmpty) ||
        (payload['lines'] is List && (payload['lines'] as List).isNotEmpty);
  }

  static Map<String, dynamic> _productPayload(
    Map<String, dynamic> item,
    int serverId,
  ) {
    return {
      'id': serverId,
      'name': item['name'],
      'sku': item['sku'],
      'barcode': item['barcode'],
      'item_type': item['item_type'],
      'description': item['description'],
      'price': item['price'],
      'currency': item['currency'],
      'is_active': item['is_active'] != false,
      'sort_order': item['sort_order'],
      'product_id': item['product_id'],
      'stock': item['stock'],
      'updated_at': item['updated_at'],
      'pos_item_category_id': item['pos_item_category_id'],
      'category': item['category'],
      'image_url': item['image_url'],
      'size_label': item['size_label'],
    };
  }

  Future<Map<String, dynamic>> _get(
    String path, {
    Map<String, dynamic>? query,
  }) {
    return _retry(() => _api.get(path, query: query));
  }

  Future<Map<String, dynamic>> _post(
    String path,
    Map<String, dynamic> data,
  ) {
    return _retry(() => _api.post(path, data: data));
  }

  Future<Map<String, dynamic>> _retry(
    Future<Map<String, dynamic>> Function() send,
  ) async {
    ApiException? last;
    for (var attempt = 0; attempt < 3; attempt++) {
      try {
        return await send();
      } on ApiException catch (e) {
        last = e;
        if (e.isUnauthorized || e.isForbidden || e.statusCode == 422) {
          rethrow;
        }
        if (!e.isUnavailable) rethrow;
      }
    }
    throw last!;
  }

  static Map<String, dynamic> _meta(Map<String, dynamic> data) {
    final meta = data['meta'];
    if (meta is Map) return Map<String, dynamic>.from(meta);
    return const {};
  }

  static Object? _unwrapList(Object? raw) {
    if (raw is Map && raw['data'] is List) return raw['data'];
    return raw;
  }

  static List<Map<String, dynamic>> _asMapList(Object? raw) {
    if (raw is! List) return const [];
    return [
      for (final item in raw)
        if (item is Map) Map<String, dynamic>.from(item),
    ];
  }

  static int _requireServerId(Object? raw, String label) {
    final id = _asInt(raw);
    if (id == null || id <= 0) {
      throw ApiException(
        'تعذر حفظ $label بدون معرّف خادم حقيقي.',
        statusCode: 422,
      );
    }
    return id;
  }

  static int? _asInt(Object? raw) {
    if (raw is int) return raw;
    if (raw is num) return raw.toInt();
    return int.tryParse('$raw');
  }

  static Map<String, dynamic> _decodeMap(String? raw) {
    if (raw == null || raw.isEmpty) return <String, dynamic>{};
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
    } catch (_) {}
    return <String, dynamic>{};
  }
}

class InitialSyncResult {
  const InitialSyncResult({
    required this.ready,
    required this.message,
    this.fromCache = false,
    this.productCount = 0,
    this.categoryCount = 0,
    this.tableCount = 0,
    this.cursor,
  });

  final bool ready;
  final String message;
  final bool fromCache;
  final int productCount;
  final int categoryCount;
  final int tableCount;
  final int? cursor;
}
