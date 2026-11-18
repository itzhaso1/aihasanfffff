import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/commerce/data/commerce_order_models.dart';

class CommerceOrderRepository {
  CommerceOrderRepository(
    this._api, {
    DomainLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final DomainLocalStore? _local;
  final int? Function()? _workspaceIdOf;
  bool lastReadFromCache = false;

  int? get _workspace => _workspaceIdOf?.call();

  Future<List<CommerceOrderModel>> cachedOrders({
    String? status,
    int? customerId,
  }) async {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const [];
    return _local.orders(workspaceId, status: status, customerId: customerId);
  }

  Stream<List<CommerceOrderModel>> watchOrders({
    String? status,
    int? customerId,
  }) {
    final workspaceId = _workspace;
    if (workspaceId == null || _local == null) return const Stream.empty();
    return _local.watchOrders(
      workspaceId,
      status: status,
      customerId: customerId,
    );
  }

  Future<List<CommerceOrderModel>> list({
    String? status,
    int? customerId,
    String? cursor,
    int perPage = 20,
  }) async {
    final workspaceId = _workspace;
    try {
      final query = <String, dynamic>{'per_page': perPage};
      if (status != null && status.isNotEmpty) query['status'] = status;
      if (customerId != null) query['customer_id'] = customerId;
      if (cursor != null && cursor.isNotEmpty) query['cursor'] = cursor;

      final res = await _api.get('/commerce/orders', query: query);
      final items = asMapList(
        res.data,
      ).map(CommerceOrderModel.fromJson).toList();
      lastReadFromCache = false;
      if (workspaceId != null && items.isNotEmpty) {
        await _local?.upsertOrders(workspaceId, items);
      }
      return items;
    } catch (_) {
      if (workspaceId != null && _local != null) {
        final cached = await _local.orders(
          workspaceId,
          status: status,
          customerId: customerId,
        );
        if (cached.isNotEmpty) {
          lastReadFromCache = true;
          return cached;
        }
      }
      rethrow;
    }
  }

  Future<CommerceOrderModel> show(int orderId) async {
    final workspaceId = _workspace;
    try {
      final res = await _api.get('/commerce/orders/$orderId');
      final map = asMap(res.data);
      if (map == null) throw ApiException(res.message ?? 'الطلب غير موجود.');
      final order = CommerceOrderModel.fromJson(map);
      lastReadFromCache = false;
      if (workspaceId != null) {
        await _local?.upsertOrders(workspaceId, [order]);
      }
      return order;
    } catch (_) {
      if (workspaceId != null && _local != null) {
        final cached = await _local.order(workspaceId, orderId);
        if (cached != null) {
          lastReadFromCache = true;
          return cached;
        }
      }
      rethrow;
    }
  }

  Future<CommerceOrderModel> cancel(int orderId) async {
    final res = await _api.post('/commerce/orders/$orderId/cancel');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إلغاء الطلب.');
    final order = CommerceOrderModel.fromJson(map);
    final workspaceId = _workspace;
    if (workspaceId != null) {
      await _local?.upsertOrders(workspaceId, [order]);
    }
    return order;
  }
}
