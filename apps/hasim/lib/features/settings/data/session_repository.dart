
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

class SessionRepository {
  SessionRepository(this._api);
  final ApiClient _api;

  Future<List<DeviceSessionModel>> list() async {
    final res = await _api.get('/sessions');
    return asMapList(res.data).map(DeviceSessionModel.fromJson).toList();
  }

  Future<void> revoke(int tokenId) async => _api.delete('/sessions/$tokenId');
  Future<void> revokeOthers() async => _api.delete('/sessions');
}

class DeviceRepository {
  DeviceRepository(this._api);
  final ApiClient _api;

  Future<void> register({
    required String token,
    required String provider,
    required String platform,
    String? deviceName,
  }) async {
    final res = await _api.post('/devices', body: {
      'token': token,
      'provider': provider,
      'platform': platform,
      'device_name': ?deviceName,
    });
    if (!res.success) throw ApiException(res.message ?? 'تعذر تسجيل الجهاز.');
  }

  Future<void> revoke(int id) async => _api.delete('/devices/$id');
}
