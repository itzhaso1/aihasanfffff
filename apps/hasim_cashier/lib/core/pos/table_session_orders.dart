import '../util/occupied_duration.dart';

/// Stable id for a table-session order snapshot (SQLite row or payload).
String tableOrderIdentity(Map<String, dynamic> order) {
  for (final key in [
    'local_id',
    'invoice_local_id',
    'client_reference',
    'id',
  ]) {
    final value = '${order[key] ?? ''}'.trim();
    if (value.isNotEmpty) return value;
  }
  final number = '${order['order_number'] ?? ''}'.trim();
  if (number.isNotEmpty) return 'num:$number';
  return '';
}

bool isCancelledTableOrder(Map<String, dynamic> order) =>
    '${order['pos_status'] ?? ''}' == 'cancelled';

/// Cashier occupy snapshots are paid/invoiced and must survive later adds.
bool isPaidTableOrder(Map<String, dynamic> order) {
  final status = '${order['pos_status'] ?? ''}';
  final pay = '${order['payment_status'] ?? ''}';
  if (pay == 'paid' || status == 'completed') return true;
  return '${order['invoice_local_id'] ?? ''}'.trim().isNotEmpty;
}

String tableSessionIdentity(Map<String, dynamic> order) {
  for (final key in ['session_local_id', 'session_client_id', 'session_id']) {
    final value = '${order[key] ?? ''}'.trim();
    if (value.isNotEmpty) return value;
  }
  return '';
}

DateTime? tableOrderCreatedAt(Map<String, dynamic> order) {
  return parseOpenedAt(
    order['created_at'] ??
        order['createdAt'] ??
        order['placed_at'] ??
        order['completed_at'],
  );
}

int tableSessionItemsCount(List<Map<String, dynamic>> orders) {
  var count = 0;
  for (final order in orders) {
    final items = order['items'];
    if (items is List) {
      count += items.length;
    }
  }
  return count;
}

/// Append live (usually unpaid) orders onto existing session snapshots.
/// Never drops paid/invoiced rows from [sessionOrders].
List<Map<String, dynamic>> mergeTableSessionOrders({
  required List<Map<String, dynamic>> sessionOrders,
  required List<Map<String, dynamic>> liveOrders,
}) {
  final out = <Map<String, dynamic>>[];
  final seen = <String>{};
  final liveKeys = {for (final order in liveOrders) tableOrderIdentity(order)}
    ..removeWhere((key) => key.isEmpty);

  void add(Map<String, dynamic> order) {
    if (isCancelledTableOrder(order)) return;
    var key = tableOrderIdentity(order);
    if (key.isEmpty) {
      key = 'anon:${out.length}:${order['total_amount']}';
    }
    if (!seen.add(key)) return;
    out.add(Map<String, dynamic>.from(order));
  }

  for (final order in sessionOrders) {
    final key = tableOrderIdentity(order);
    if (key.isNotEmpty && liveKeys.contains(key)) {
      continue;
    }
    if (isPaidTableOrder(order) || liveOrders.isEmpty) {
      add(order);
    }
  }
  for (final order in liveOrders) {
    add(order);
  }
  return out;
}

bool _isUnpaidTableOrder({
  required String posStatus,
  required String paymentStatus,
}) {
  return paymentStatus != 'paid' && posStatus != 'completed';
}

/// True when a SQLite order belongs to the currently open table session.
///
/// [table_id] is never enough. Paid/completed history stays in SQLite for
/// invoices and reports; it must not attach to a later sitting.
bool isOrderInOpenTableSession({
  required String posStatus,
  required String paymentStatus,
  required DateTime createdAt,
  DateTime? openedAt,
  DateTime? completedAt,
  String? orderSessionLocalId,
  String? currentSessionLocalId,
}) {
  if (posStatus == 'cancelled') return false;

  final current = (currentSessionLocalId ?? '').trim();
  if (current.isEmpty) return false;

  final orderSession = (orderSessionLocalId ?? '').trim();
  if (orderSession.isNotEmpty) {
    return orderSession == current;
  }

  // Legacy rows minted before session_local_id was stamped. Never attach
  // paid/completed history to a new sitting; only in-progress unpaid work
  // created after this sitting opened may still show.
  if (!_isUnpaidTableOrder(
    posStatus: posStatus,
    paymentStatus: paymentStatus,
  )) {
    return false;
  }
  if (openedAt == null) return false;
  return !createdAt.toUtc().isBefore(openedAt.toUtc());
}

/// Payload snapshots for the open sitting. Cards must carry this sitting's
/// session id (or be unpaid leftover from the same payload). Untagged paid
/// history is never reused just because a new sitting is open.
bool isSnapshotInOpenTableSession(
  Map<String, dynamic> order, {
  DateTime? openedAt,
  String? currentSessionLocalId,
}) {
  if (isCancelledTableOrder(order)) return false;

  final current = (currentSessionLocalId ?? '').trim();
  if (current.isEmpty) return false;

  final orderSession = tableSessionIdentity(order);
  if (orderSession.isNotEmpty) {
    return orderSession == current;
  }

  if (!isPaidTableOrder(order)) {
    final created = tableOrderCreatedAt(order);
    if (created == null) return true;
    if (openedAt == null) return false;
    return !created.toUtc().isBefore(openedAt.toUtc());
  }

  final created = tableOrderCreatedAt(order);
  if (created == null) return true;
  if (openedAt == null) return false;
  return !created.toUtc().isBefore(openedAt.toUtc());
}

List<Map<String, dynamic>> filterOrdersForOpenTableSession({
  required List<Map<String, dynamic>> orders,
  DateTime? openedAt,
  String? currentSessionLocalId,
}) {
  return [
    for (final order in orders)
      if (isSnapshotInOpenTableSession(
        order,
        openedAt: openedAt,
        currentSessionLocalId: currentSessionLocalId,
      ))
        order,
  ];
}

bool looksLikeFlatOrderLines(List<Map<String, dynamic>> rows) {
  if (rows.isEmpty) return false;
  return rows.every((row) {
    if (row['items'] is List) return false;
    return row.containsKey('item_name') ||
        row.containsKey('quantity') ||
        row.containsKey('unit_price') ||
        row.containsKey('product_name');
  });
}
