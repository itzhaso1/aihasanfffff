import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/commerce/data/commerce_order_models.dart';

class CommerceOrderRepository {
  CommerceOrderRepository(this._api);

  final ApiClient _api;

  Future<List<CommerceOrderModel>> list({
    String? status,
    int? customerId,
    String? cursor,
    int perPage = 20,
  }) async {
    final query = <String, dynamic>{'per_page': perPage};
    if (status != null && status.isNotEmpty) query['status'] = status;
    if (customerId != null) query['customer_id'] = customerId;
    if (cursor != null && cursor.isNotEmpty) query['cursor'] = cursor;

    final res = await _api.get('/commerce/orders', query: query);
    return asMapList(res.data).map(CommerceOrderModel.fromJson).toList();
  }

  Future<CommerceOrderModel> show(int orderId) async {
    final res = await _api.get('/commerce/orders/$orderId');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'الطلب غير موجود.');
    return CommerceOrderModel.fromJson(map);
  }

  Future<CommerceOrderModel> cancel(int orderId) async {
    final res = await _api.post('/commerce/orders/$orderId/cancel');
    final map = asMap(res.data);
    if (map == null) throw ApiException(res.message ?? 'تعذر إلغاء الطلب.');
    return CommerceOrderModel.fromJson(map);
  }
}
