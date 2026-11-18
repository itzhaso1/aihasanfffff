import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class SecureStore {
  SecureStore({FlutterSecureStorage? storage})
    : _storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(
              encryptedSharedPreferences: true,
              resetOnError: true,
            ),
          );

  final FlutterSecureStorage _storage;
  static const _tokenKey = 'hasim_access_token';

  String? _cachedToken;
  bool _tokenLoaded = false;

  Future<void> saveToken(String token) async {
    _cachedToken = token;
    _tokenLoaded = true;
    try {
      await _storage.write(key: _tokenKey, value: token);
    } catch (_) {}
  }

  Future<String?> readToken() async {
    if (_tokenLoaded) {
      return _cachedToken;
    }
    try {
      _cachedToken = await _storage
          .read(key: _tokenKey)
          .timeout(const Duration(seconds: 2));
    } catch (_) {
      _cachedToken = null;
    }
    _tokenLoaded = true;
    return _cachedToken;
  }

  Future<void> clearToken() async {
    _cachedToken = null;
    _tokenLoaded = true;
    try {
      await _storage.delete(key: _tokenKey).timeout(const Duration(seconds: 2));
    } catch (_) {}
  }

  void invalidateCache() {
    _cachedToken = null;
    _tokenLoaded = false;
  }
}
