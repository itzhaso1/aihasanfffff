import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Persists the Laravel link in existing Secure Storage.
/// Local POS rows stay on standalone workspace 900001.
class CloudLinkSnapshot {
  const CloudLinkSnapshot({
    required this.token,
    this.workspaceId,
    this.userId,
    this.deviceId,
    this.posEnabled = false,
    this.deviceRegistered = false,
  });

  final String token;
  final int? workspaceId;
  final int? userId;
  final String? deviceId;
  final bool posEnabled;
  final bool deviceRegistered;

  bool get isLinked =>
      deviceRegistered &&
      token.isNotEmpty &&
      workspaceId != null &&
      workspaceId! > 0 &&
      deviceId != null &&
      deviceId!.isNotEmpty;
}

class CloudLinkStore {
  CloudLinkStore(this._read, this._write, this._delete);

  factory CloudLinkStore.secure(FlutterSecureStorage storage) {
    return CloudLinkStore(
      (key) => storage.read(key: key),
      (key, value) => storage.write(key: key, value: value),
      (key) => storage.delete(key: key),
    );
  }

  factory CloudLinkStore.memory([Map<String, String>? data]) {
    final map = data ?? <String, String>{};
    return CloudLinkStore(
      (key) async => map[key],
      (key, value) async => map[key] = value,
      (key) async {
        map.remove(key);
      },
    );
  }

  static const tokenKey = 'cashier_cloud_token';
  static const workspaceKey = 'cashier_cloud_workspace_id';
  static const userKey = 'cashier_cloud_user_id';
  static const deviceKey = 'cashier_cloud_device_id';
  static const posEnabledKey = 'cashier_cloud_pos_enabled';
  static const registeredKey = 'cashier_cloud_device_registered';

  final Future<String?> Function(String key) _read;
  final Future<void> Function(String key, String value) _write;
  final Future<void> Function(String key) _delete;

  Future<void> save(CloudLinkSnapshot snapshot) async {
    await _write(tokenKey, snapshot.token);
    final workspaceId = snapshot.workspaceId;
    if (workspaceId != null) {
      await _write(workspaceKey, workspaceId.toString());
    }
    final userId = snapshot.userId;
    if (userId != null) {
      await _write(userKey, userId.toString());
    }
    final deviceId = snapshot.deviceId;
    if (deviceId != null && deviceId.isNotEmpty) {
      await _write(deviceKey, deviceId);
    }
    await _write(posEnabledKey, snapshot.posEnabled ? '1' : '0');
    await _write(registeredKey, snapshot.deviceRegistered ? '1' : '0');
  }

  Future<CloudLinkSnapshot?> read() async {
    final token = await _read(tokenKey);
    if (token == null || token.isEmpty) return null;
    return CloudLinkSnapshot(
      token: token,
      workspaceId: int.tryParse(await _read(workspaceKey) ?? ''),
      userId: int.tryParse(await _read(userKey) ?? ''),
      deviceId: await _read(deviceKey),
      posEnabled: await _read(posEnabledKey) == '1',
      deviceRegistered: await _read(registeredKey) == '1',
    );
  }

  Future<void> clear() async {
    await _delete(tokenKey);
    await _delete(workspaceKey);
    await _delete(userKey);
    await _delete(deviceKey);
    await _delete(posEnabledKey);
    await _delete(registeredKey);
  }
}
