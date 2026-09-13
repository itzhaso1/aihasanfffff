import 'dart:convert';

import 'package:drift/drift.dart';

import '../local_db/app_database.dart';
import '../pos/pos_mode.dart';
import '../repositories/sync_queue_repository.dart';

/// Why a queue row is still visible after a successful takeaway push.
enum SyncQueueBucket {
  /// A — kitchen/sale order create (paid or unpaid) ready for /sync/push.
  ready,

  /// Invoice waiting for its local order.server_id.
  waitingParent,

  /// B — permanently failed.
  failed,

  /// C — local entity already has a server id; queue row was not ACKed.
  alreadyApplied,

  /// D — table_session / stock / order update outside the invoice contract.
  unsupported,

  /// E — reserved standalone workspace 900001 mixed into a connected queue.
  standalone,

  /// Paid sale not yet ready (backoff or missing product id).
  blocked,
}

class SyncQueueClassification {
  const SyncQueueClassification({
    required this.row,
    required this.bucket,
    required this.reason,
  });

  final SyncQueueItem row;
  final SyncQueueBucket bucket;
  final String reason;
}

class SyncQueueCountsByBucket {
  const SyncQueueCountsByBucket({
    this.ready = 0,
    this.waitingParent = 0,
    this.failed = 0,
    this.alreadyApplied = 0,
    this.unsupported = 0,
    this.standalone = 0,
    this.blocked = 0,
  });

  final int ready;
  final int waitingParent;
  final int failed;
  final int alreadyApplied;
  final int unsupported;
  final int standalone;
  final int blocked;

  /// Kitchen orders + invoices + menu + table-master + table sessions.
  /// Sale stock leftovers are alreadyApplied, not this count.
  int get invoicePending => ready + waitingParent;

  int get scopedPending => invoicePending;

  int get totalOpen =>
      ready +
      waitingParent +
      failed +
      alreadyApplied +
      unsupported +
      standalone +
      blocked;
}

/// Classifies sync_queue rows without deleting or rewriting them.
class SyncQueueClassifier {
  const SyncQueueClassifier(this._db);

  final AppDatabase _db;

  static const saleTypes = {'takeaway', 'table', 'delivery'};

  static const kitchenStatuses = {
    'new',
    'accepted',
    'preparing',
    'ready',
    'delivered',
    'completed',
    'cancelled',
  };

  static bool isKitchenStatusOp(SyncQueueItem row) {
    if (row.entityType != 'order' || row.operation != 'update') return false;
    final payload = decodePayload(row.payloadJson);
    final status = '${payload['pos_status'] ?? ''}'.trim().toLowerCase();
    if (!kitchenStatuses.contains(status)) return false;
    if (payload['kitchen_status'] == true) return true;
    final items = payload['items'];
    return items == null || (items is List && items.isEmpty);
  }

  static const menuTypes = {'category', 'product'};

  static const tableMasterType = 'table';

  static Map<String, dynamic> decodePayload(String raw) {
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
    } catch (_) {}
    return const {};
  }

  static String? saleTypeOf(SyncQueueItem row) {
    final payload = decodePayload(row.payloadJson);
    final type = '${payload['order_type'] ?? ''}'.trim().toLowerCase();
    if (saleTypes.contains(type)) return type;
    return null;
  }

  Future<List<SyncQueueClassification>> classifyWorkspace(int workspaceId) async {
    final rows = await SyncQueueRepository(_db).pendingForWorkspace(workspaceId);
    return [for (final row in rows) await classify(row)];
  }

  Future<SyncQueueCountsByBucket> counts(int workspaceId) async {
    final items = await classifyWorkspace(workspaceId);
    var ready = 0;
    var waitingParent = 0;
    var failed = 0;
    var alreadyApplied = 0;
    var unsupported = 0;
    var standalone = 0;
    var blocked = 0;
    for (final item in items) {
      switch (item.bucket) {
        case SyncQueueBucket.ready:
          ready++;
        case SyncQueueBucket.waitingParent:
          waitingParent++;
        case SyncQueueBucket.failed:
          failed++;
        case SyncQueueBucket.alreadyApplied:
          alreadyApplied++;
        case SyncQueueBucket.unsupported:
          unsupported++;
        case SyncQueueBucket.standalone:
          standalone++;
        case SyncQueueBucket.blocked:
          blocked++;
      }
    }
    return SyncQueueCountsByBucket(
      ready: ready,
      waitingParent: waitingParent,
      failed: failed,
      alreadyApplied: alreadyApplied,
      unsupported: unsupported,
      standalone: standalone,
      blocked: blocked,
    );
  }

  Future<String?> firstReadyLastError(int workspaceId) async {
    final items = await classifyWorkspace(workspaceId);
    for (final item in items) {
      if (item.bucket != SyncQueueBucket.ready) continue;
      final error = item.row.lastError?.trim() ?? '';
      if (error.isNotEmpty) return error;
    }
    return null;
  }

  Future<String?> firstFailedHint(int workspaceId) async {
    final items = await classifyWorkspace(workspaceId);
    String? fallback;
    for (final item in items) {
      if (item.bucket != SyncQueueBucket.failed) continue;
      final error = (item.row.lastError ?? item.reason).trim();
      if (error.isEmpty) continue;
      if (SyncQueueRepository.isInContract(item.row)) return error;
      fallback ??= error;
    }
    return fallback;
  }

  Future<SyncQueueClassification> classify(SyncQueueItem row) async {
    if (PosMode.isReservedStandaloneWorkspace(row.workspaceId)) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.standalone,
        reason: 'عملية على المساحة المحلية 900001 وليست مساحة السحابة.',
      );
    }
    if (row.status == 'failed') {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.failed,
        reason: row.lastError ?? 'فشلت العملية بشكل دائم.',
      );
    }

    if (row.entityType == 'order' && row.operation == 'update') {
      return _classifyKitchenStatus(row);
    }

    if (row.entityType == 'order' && row.operation == 'delete') {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.unsupported,
        reason:
            'حذف الطلب خارج عقد المزامنة الحالي.',
      );
    }
    if (row.entityType == 'table_session') {
      return _classifyTableSession(row);
    }
    if (row.entityType == 'stock' || row.entityType == 'stock_movement') {
      return _classifyStock(row);
    }

    if (row.entityType == 'customer' && row.operation == 'create') {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.ready,
        reason: 'عميل جديد مطلوب قبل دفع الطلب.',
      );
    }

    if (row.entityType == 'order' && row.operation == 'create') {
      return _classifyOrderCreate(row);
    }
    if (row.entityType == 'invoice' && row.operation == 'create') {
      return _classifyInvoiceCreate(row);
    }
    if (menuTypes.contains(row.entityType) &&
        (row.operation == 'create' ||
            row.operation == 'update' ||
            row.operation == 'delete')) {
      return _classifyMenu(row);
    }
    if (row.entityType == tableMasterType &&
        (row.operation == 'create' ||
            row.operation == 'update' ||
            row.operation == 'delete')) {
      return _classifyTableMaster(row);
    }

    return SyncQueueClassification(
      row: row,
      bucket: SyncQueueBucket.unsupported,
      reason: 'نوع العملية ${row.entityType}.${row.operation} غير مدعوم للدفع.',
    );
  }

  Future<SyncQueueClassification> _classifyKitchenStatus(
    SyncQueueItem row,
  ) async {
    if (!isKitchenStatusOp(row)) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.unsupported,
        reason: 'تحديث الطلب خارج عقد حالة المطبخ.',
      );
    }
    final payload = decodePayload(row.payloadJson);
    final order = await _order(row.workspaceId, row.entityId);
    final serverId = order?.serverId ??
        (payload['order_server_id'] as num?)?.toInt() ??
        (payload['server_order_id'] as num?)?.toInt();
    if (serverId == null || serverId <= 0) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.waitingParent,
        reason: 'حالة المطبخ تنتظر وصول الطلب إلى Laravel أولاً.',
      );
    }
    return SyncQueueClassification(
      row: row,
      bucket: SyncQueueBucket.ready,
      reason: 'تحديث حالة المطبخ جاهز للمزامنة.',
    );
  }

  Future<SyncQueueClassification> _classifyOrderCreate(SyncQueueItem row) async {
    final payload = decodePayload(row.payloadJson);
    final type = '${payload['order_type'] ?? ''}'.trim().toLowerCase();
    if (!saleTypes.contains(type)) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.unsupported,
        reason: 'نوع الطلب "$type" خارج عقد الفاتورة (سفري/طاولة/توصيل).',
      );
    }
    final order = await _order(row.workspaceId, row.entityId);
    if (order?.serverId != null && order!.serverId! > 0) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.alreadyApplied,
        reason: 'الطلب المحلي لديه server_id=${order.serverId} والطابور لم يُحدَّث.',
      );
    }
    final items = payload['items'];
    if (items is! List || items.isEmpty) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.blocked,
        reason: 'لا توجد عناصر صالحة للدفع.',
      );
    }
    for (final item in items) {
      if (item is! Map) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.blocked,
          reason: 'عنصر بدون معرّف سحابة.',
        );
      }
      final menuId = (item['pos_menu_item_id'] as num?)?.toInt() ?? 0;
      final qty = (item['quantity'] as num?)?.toInt() ?? 0;
      if (menuId <= 0 || qty < 1) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.blocked,
          reason: 'صنف محلي بدون server id — لا يُرسل إلى Laravel.',
        );
      }
    }
    final customerLocal = '${payload['customer_local_id'] ?? ''}'.trim();
    final payloadCustomerId = (payload['customer_id'] as num?)?.toInt() ?? 0;
    if (customerLocal.isNotEmpty && payloadCustomerId <= 0) {
      final customer = await _customer(row.workspaceId, customerLocal);
      if (customer == null ||
          customer.serverId == null ||
          customer.serverId! <= 0) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.waitingParent,
          reason: 'الطلب ينتظر وصول العميل إلى Laravel أولاً.',
        );
      }
    }
    if (type == 'table') {
      final waitingTable = await _tableNeedsServerId(row, payload);
      if (waitingTable) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.waitingParent,
          reason: 'طلب الطاولة ينتظر وصول الطاولة إلى Laravel أولاً.',
        );
      }
    }
    final paid = order?.paymentStatus.trim().toLowerCase() == 'paid';
    return SyncQueueClassification(
      row: row,
      bucket: SyncQueueBucket.ready,
      reason: type == 'table'
          ? (paid
              ? 'طلب طاولة مدفوع جاهز للدفع.'
              : 'طلب مطبخ (طاولة) جاهز للمزامنة.')
          : type == 'delivery'
              ? (paid
                  ? 'طلب توصيل جاهز للدفع.'
                  : 'طلب مطبخ (توصيل) جاهز للمزامنة.')
              : (paid
                  ? 'طلب سفري جاهز للدفع.'
                  : 'طلب مطبخ (سفري) جاهز للمزامنة.'),
    );
  }

  Future<SyncQueueClassification> _classifyMenu(SyncQueueItem row) async {
    if (row.entityType == 'category') {
      final category = await _category(row.workspaceId, row.entityId);
      if (row.operation == 'create' &&
          category?.serverId != null &&
          category!.serverId! > 0) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.alreadyApplied,
          reason: 'التصنيف المحلي لديه server_id=${category.serverId}.',
        );
      }
      if (row.operation != 'create' &&
          (category == null ||
              category.serverId == null ||
              category.serverId! <= 0)) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.waitingParent,
          reason: 'تحديث/حذف التصنيف ينتظر معرّف السحابة.',
        );
      }
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.ready,
        reason: 'تغيير منيو (تصنيف) جاهز للدفع.',
      );
    }

    final product = await _product(row.workspaceId, row.entityId);
    if (row.operation == 'create' &&
        product?.serverId != null &&
        product!.serverId! > 0) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.alreadyApplied,
        reason: 'الصنف المحلي لديه server_id=${product.serverId}.',
      );
    }
    if (row.operation != 'create' &&
        (product == null ||
            product.serverId == null ||
            product.serverId! <= 0)) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.waitingParent,
        reason: 'تحديث/حذف الصنف ينتظر معرّف السحابة.',
      );
    }
    final payload = decodePayload(row.payloadJson);
    final categoryLocalId =
        '${payload['category_local_id'] ?? product?.categoryLocalId ?? ''}'
            .trim();
    if (categoryLocalId.isNotEmpty) {
      final category = await _category(row.workspaceId, categoryLocalId);
      if (category == null ||
          category.serverId == null ||
          category.serverId! <= 0) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.waitingParent,
          reason: 'الصنف ينتظر وصول التصنيف إلى Laravel أولاً.',
        );
      }
    }
    return SyncQueueClassification(
      row: row,
      bucket: SyncQueueBucket.ready,
      reason: 'تغيير منيو (صنف) جاهز للدفع.',
    );
  }

  Future<SyncQueueClassification> _classifyTableMaster(
    SyncQueueItem row,
  ) async {
    final table = await _table(row.workspaceId, row.entityId);
    if (row.operation == 'create' &&
        table?.serverId != null &&
        table!.serverId! > 0) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.alreadyApplied,
        reason: 'الطاولة المحلية لديها server_id=${table.serverId}.',
      );
    }
    if (row.operation != 'create') {
      final payload = decodePayload(row.payloadJson);
      final serverId = table?.serverId ??
          (payload['server_id'] as num?)?.toInt() ??
          (payload['table_server_id'] as num?)?.toInt();
      if (serverId == null || serverId <= 0) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.waitingParent,
          reason: 'تحديث/حذف الطاولة ينتظر معرّف السحابة.',
        );
      }
    }
    return SyncQueueClassification(
      row: row,
      bucket: SyncQueueBucket.ready,
      reason: 'بيانات الطاولة الأساسية جاهزة للدفع.',
    );
  }

  static const _sessionOps = {
    'open',
    'close',
    'cancel',
    'note',
    'discount',
    'transfer',
    'merge',
    'split',
  };

  Future<SyncQueueClassification> _classifyTableSession(
    SyncQueueItem row,
  ) async {
    if (!_sessionOps.contains(row.operation)) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.unsupported,
        reason: 'عملية جلسة غير معروفة: ${row.operation}.',
      );
    }
    final payload = decodePayload(row.payloadJson);
    final tableServerId = (payload['table_server_id'] as num?)?.toInt() ??
        (payload['dining_table_id'] as num?)?.toInt() ??
        0;
    if (tableServerId <= 0) {
      final waitingTable = await _tableNeedsServerId(row, payload);
      if (waitingTable) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.waitingParent,
          reason: 'جلسة الطاولة تنتظر وصول الطاولة إلى Laravel أولاً.',
        );
      }
    }
    if (row.operation == 'close') {
      final waitingOpen = await _hasPendingSessionOpen(row);
      if (waitingOpen) {
        return SyncQueueClassification(
          row: row,
          bucket: SyncQueueBucket.waitingParent,
          reason: 'إغلاق الطاولة ينتظر وصول فتح الجلسة إلى Laravel أولاً.',
        );
      }
    }
    return SyncQueueClassification(
      row: row,
      bucket: SyncQueueBucket.ready,
      reason: 'عملية جلسة الطاولة جاهزة للمزامنة.',
    );
  }

  Future<SyncQueueClassification> _classifyStock(SyncQueueItem row) async {
    final payload = decodePayload(row.payloadJson);
    final kind =
        '${payload['kind'] ?? payload['type'] ?? ''}'.trim().toLowerCase();
    if (kind == 'sale' || kind == 'remove') {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.alreadyApplied,
        reason: 'خصم البيع يتم عبر order.created — لا يُعاد إرسال الحركة.',
      );
    }
    if (kind.isEmpty) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.unsupported,
        reason: 'حركة مخزون بلا نوع.',
      );
    }
    return SyncQueueClassification(
      row: row,
      bucket: SyncQueueBucket.ready,
      reason: 'حركة مخزون غير مرتبطة بالبيع جاهزة للمزامنة.',
    );
  }

  Future<SyncQueueClassification> _classifyInvoiceCreate(
    SyncQueueItem row,
  ) async {
    final payload = decodePayload(row.payloadJson);
    var type = '${payload['order_type'] ?? ''}'.trim().toLowerCase();
    final orderLocalId = '${payload['order_local_id'] ?? ''}'.trim();
    final invoice = await _invoice(row.workspaceId, row.entityId);
    if (invoice?.serverId != null && invoice!.serverId! > 0) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.alreadyApplied,
        reason:
            'الفاتورة المحلية لديها server_id=${invoice.serverId} والطابور لم يُحدَّث.',
      );
    }
    if (orderLocalId.isEmpty) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.blocked,
        reason: 'الفاتورة بلا order_local_id.',
      );
    }
    final order = await _order(row.workspaceId, orderLocalId);
    if (order == null) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.blocked,
        reason: 'الطلب المحلي للفاتورة غير موجود.',
      );
    }
    if (type.isEmpty) {
      type = order.orderType.trim().toLowerCase();
    }
    if (!saleTypes.contains(type)) {
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.unsupported,
        reason: 'فاتورة لنوع طلب خارج العقد.',
      );
    }
    if (order.serverId == null || order.serverId! <= 0) {
      final parentErr = await _parentQueueError(
        row.workspaceId,
        'order',
        orderLocalId,
        'create',
      );
      return SyncQueueClassification(
        row: row,
        bucket: SyncQueueBucket.waitingParent,
        reason: parentErr == null
            ? 'الفاتورة تنتظر وصول الطلب إلى Laravel أولاً.'
            : 'الفاتورة تنتظر طلباً فشل: $parentErr',
      );
    }
    return SyncQueueClassification(
      row: row,
      bucket: SyncQueueBucket.ready,
      reason: 'فاتورة جاهزة بعد وجود طلب السحابة.',
    );
  }

  Future<String?> _parentQueueError(
    int workspaceId,
    String entityType,
    String entityId,
    String operation,
  ) async {
    final rows = await SyncQueueRepository(_db).pendingForWorkspace(workspaceId);
    for (final row in rows) {
      if (row.entityType != entityType ||
          row.entityId != entityId ||
          row.operation != operation) {
        continue;
      }
      if (row.status == 'failed') {
        return (row.lastError ?? 'فشل دائم').trim();
      }
    }
    return null;
  }

  Future<LocalCustomer?> _customer(int workspaceId, String localId) {
    return (_db.select(_db.localCustomers)..where(
          (t) => t.workspaceId.equals(workspaceId) & t.localId.equals(localId),
        ))
        .getSingleOrNull();
  }

  Future<LocalOrder?> _order(int workspaceId, String localId) {
    return (_db.select(_db.localOrders)..where(
          (t) => t.workspaceId.equals(workspaceId) & t.localId.equals(localId),
        ))
        .getSingleOrNull();
  }

  Future<LocalInvoice?> _invoice(int workspaceId, String localId) {
    return (_db.select(_db.localInvoices)..where(
          (t) => t.workspaceId.equals(workspaceId) & t.localId.equals(localId),
        ))
        .getSingleOrNull();
  }

  Future<LocalCategory?> _category(int workspaceId, String localId) {
    return (_db.select(_db.localCategories)..where(
          (t) => t.workspaceId.equals(workspaceId) & t.localId.equals(localId),
        ))
        .getSingleOrNull();
  }

  Future<LocalProduct?> _product(int workspaceId, String localId) {
    return (_db.select(_db.localProducts)..where(
          (t) => t.workspaceId.equals(workspaceId) & t.localId.equals(localId),
        ))
        .getSingleOrNull();
  }

  Future<LocalTable?> _table(int workspaceId, String localId) {
    return (_db.select(_db.localTables)..where(
          (t) => t.workspaceId.equals(workspaceId) & t.localId.equals(localId),
        ))
        .getSingleOrNull();
  }

  Future<bool> _tableNeedsServerId(
    SyncQueueItem row,
    Map<String, dynamic> payload,
  ) async {
    final tableLocalId = '${payload['table_local_id'] ?? ''}'.trim();
    if (tableLocalId.isEmpty) return false;
    final table = await _table(row.workspaceId, tableLocalId);
    return table == null || table.serverId == null || table.serverId! <= 0;
  }

  Future<bool> _hasPendingSessionOpen(SyncQueueItem closeRow) async {
    final rows = await SyncQueueRepository(_db).pendingForWorkspace(
      closeRow.workspaceId,
    );
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
}
