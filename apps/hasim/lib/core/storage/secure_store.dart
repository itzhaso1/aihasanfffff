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
  int _cacheEpoch = 0;
  Future<String?>? _inFlightRead;

  Future<void> saveToken(String token) async {
    _cacheEpoch++;
    _inFlightRead = null;
    _cachedToken = token;
    _tokenLoaded = true;
    try {
      await _storage.write(key: _tokenKey, value: token);
    } catch (_) {}
  }

  Future<String?> readToken() {
    if (_tokenLoaded) {
      return Future<String?>.value(_cachedToken);
    }
    return _inFlightRead ??= _performReadToken();
  }

  Future<String?> _performReadToken() async {
    final epoch = _cacheEpoch;
    String? value;
    try {
      value = await _storage
          .read(key: _tokenKey)
          .timeout(const Duration(seconds: 2));
    } catch (_) {
      value = null;
    } finally {
      _inFlightRead = null;
    }
    if (epoch == _cacheEpoch) {
      _cachedToken = value;
      _tokenLoaded = true;
      return _cachedToken;
    }
    return _cachedToken;
  }

  Future<void> clearToken() async {
    _cacheEpoch++;
    _inFlightRead = null;
    _cachedToken = null;
    _tokenLoaded = true;
    try {
      await _storage.delete(key: _tokenKey).timeout(const Duration(seconds: 2));
    } catch (_) {}
  }

  void invalidateCache() {
    _cacheEpoch++;
    _inFlightRead = null;
    _cachedToken = null;
    _tokenLoaded = false;
  }
}
