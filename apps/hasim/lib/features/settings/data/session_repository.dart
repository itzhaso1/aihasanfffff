
import 'package:hasim/core/local_db/settings_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';

/// Display fields only. Session secrets and network identifiers stay on the server.
Map<String, dynamic> sessionCachePayload(Map<String, dynamic> json) {
  return {
    'id': json['id'],
    'name': json['name'],
    'device_name': json['device_name'],
    'device_type': json['device_type'],
    'last_used_at': json['last_used_at'],
    'is_current': json['is_current'] == true,
  };
}

class SessionRepository {
  SessionRepository(
    this._api, {
    SettingsLocalStore? local,
    int? Function()? workspaceIdOf,
  }) : _local = local,
       _workspaceIdOf = workspaceIdOf;

  final ApiClient _api;
  final SettingsLocalStore? _local;
  final int? Function()? _workspaceIdOf;

  Future<List<DeviceSessionModel>> cached() async {
    final workspaceId = _workspaceIdOf?.call();
    if (workspaceId == null || _local == null) return const [];
    final raw = await _local.get(workspaceId, 'sessions');
    if (raw is! List) return const [];
    return asMapList(raw).map(DeviceSessionModel.fromJson).toList();
  }

  Future<List<DeviceSessionModel>> list() async {
    final res = await _api.get('/sessions');
    final items = [for (final item in asMapList(res.data)) sessionCachePayload(item)];
    final workspaceId = _workspaceIdOf?.call();
    if (workspaceId != null) await _local?.put(workspaceId, 'sessions', items);
    return items.map(DeviceSessionModel.fromJson).toList();
  }

  Future<void> revoke(int tokenId) async => _api.delete('/sessions/$tokenId');
  Future<void> revokeOthers() async => _api.delete('/sessions');
}

class DeviceRepository {
  DeviceRepository(this._api);
  final ApiClient _api;

  Future<int?> register({
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
    return (asMap(res.data)?['id'] as num?)?.toInt();
  }

  Future<void> revoke(int id) async => _api.delete('/devices/$id');
}
