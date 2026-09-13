
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';

class NotificationRepository {
  NotificationRepository(this._api);
  final ApiClient _api;

  Future<List<AppNotificationModel>> list() async {
    final res = await _api.get('/notifications');
    return asMapList(res.data).map(AppNotificationModel.fromJson).toList();
  }

  Future<void> markRead(String id) async => _api.post('/notifications/$id/read');
  Future<void> markAllRead() async => _api.post('/notifications/read-all');

  Future<Map<String, dynamic>> preferences() async {
    final res = await _api.get('/notification-preferences');
    return asMap(res.data) ?? {};
  }

  Future<Map<String, dynamic>> updatePreferences(Map<String, dynamic> body) async {
    final res = await _api.put('/notification-preferences', body: body);
    return asMap(res.data) ?? {};
  }
}
