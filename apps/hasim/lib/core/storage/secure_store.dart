import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class SecureStore {
  SecureStore({FlutterSecureStorage? storage})
    : _storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(encryptedSharedPreferences: true),
          );

  final FlutterSecureStorage _storage;
  static const _tokenKey = 'hasim_access_token';

  String? _cachedToken;
  bool _tokenLoaded = false;

  Future<void> saveToken(String token) async {
    _cachedToken = token;
    _tokenLoaded = true;
    await _storage.write(key: _tokenKey, value: token);
  }

  Future<String?> readToken() async {
    if (_tokenLoaded) {
      return _cachedToken;
    }
    _cachedToken = await _storage.read(key: _tokenKey);
    _tokenLoaded = true;
    return _cachedToken;
  }

  Future<void> clearToken() async {
    _cachedToken = null;
    _tokenLoaded = true;
    await _storage.delete(key: _tokenKey);
  }

  void invalidateCache() {
    _cachedToken = null;
    _tokenLoaded = false;
  }
}
