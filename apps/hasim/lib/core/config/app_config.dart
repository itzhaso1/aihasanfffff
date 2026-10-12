import 'package:flutter/foundation.dart';

/// حاسم — إعدادات التطبيق
class AppConfig {
  AppConfig._();

  static const String apiBaseDefineName = 'API_BASE';

  static const String _apiBaseFromEnvironment = String.fromEnvironment(
    apiBaseDefineName,
    defaultValue: '',
  );

  /// Default HTTPS host when [apiBaseDefineName] is omitted at build time.
  static String get debugFallbackApiBase {
    if (defaultTargetPlatform == TargetPlatform.android) {
      return 'https://hasim.online/';
    }

    return 'https://hasim.online/';
  }

  /// Host base URL used by the mobile API client.
  static String get apiBase => resolveApiBase();

  static String resolveApiBase({String? override}) {
    return resolveConfiguredHost(_apiBaseFromEnvironment, override: override);
  }

  static String resolveConfiguredHost(
    String rawFromEnvironment, {
    String? override,
  }) {
    if (override != null && override.trim().isNotEmpty) {
      final resolved = normalizeHostBase(override);
      if (kReleaseMode) {
        return assertProductionApiHost(resolved, defineName: apiBaseDefineName);
      }
      return resolved;
    }

    final raw = rawFromEnvironment.trim().isNotEmpty
        ? rawFromEnvironment
        : debugFallbackApiBase;

    if (kReleaseMode) {
      return assertProductionApiHost(
        normalizeHostBase(raw),
        defineName: apiBaseDefineName,
      );
    }

    return normalizeHostBase(raw);
  }

  /// Validates a normalized host for release builds. Throws [StateError] when invalid.
  static String assertProductionApiHost(
    String normalizedHost, {
    required String defineName,
  }) {
    if (normalizedHost.isEmpty) {
      throw StateError(
        'Missing production API URL. Pass --dart-define=$defineName=https://your-domain.com at build time.',
      );
    }

    final uri = Uri.tryParse(normalizedHost);
    if (uri == null || !uri.hasScheme || uri.host.isEmpty) {
      throw StateError('Invalid production API URL: $normalizedHost');
    }

    if (uri.scheme.toLowerCase() != 'https') {
      throw StateError('Production API URL must use HTTPS: $normalizedHost');
    }

    final host = uri.host.toLowerCase();
    const blockedHosts = {'localhost', '127.0.0.1', '10.0.2.2', '0.0.0.0'};
    if (blockedHosts.contains(host)) {
      throw StateError(
        'Production API URL cannot use development host "$host".',
      );
    }

    return normalizedHost;
  }

  /// Host only (no `/api`, no `/api/mobile/v1`). Trailing slashes stripped.
  static String normalizeHostBase(String raw) {
    var value = raw.trim();
    while (value.endsWith('/')) {
      value = value.substring(0, value.length - 1);
    }
    const suffixes = ['/api/mobile/v1', '/api/mobile', '/api'];
    final lower = value.toLowerCase();
    for (final suffix in suffixes) {
      if (lower.endsWith(suffix)) {
        value = value.substring(0, value.length - suffix.length);
        while (value.endsWith('/')) {
          value = value.substring(0, value.length - 1);
        }
        break;
      }
    }
    return value;
  }

  static String get hostBase => normalizeHostBase(apiBase);

  /// Turns `/storage/...` or a storage key into a loadable URL.
  static String? resolvePublicUrl(String? value) {
    final raw = value?.trim() ?? '';
    if (raw.isEmpty) {
      return null;
    }
    if (raw.startsWith('http://') || raw.startsWith('https://')) {
      return raw;
    }
    final path = raw.startsWith('/') ? raw : '/$raw';
    return '$hostBase$path';
  }

  /// Dio base must end with `/` so relative paths resolve under mobile v1.
  static String get mobileApiBase => '$hostBase/api/mobile/v1/';

  static const String appName = 'حاسم';
  static const ColorBrand brand = ColorBrand();

  /// Custom URL scheme used after Laravel finishes OAuth / Embedded Signup.
  static const String channelCallbackScheme = 'hasim';

  static bool isChannelOAuthCallback(Uri uri) {
    if (uri.scheme != channelCallbackScheme) {
      return false;
    }
    if (uri.host != 'channels') {
      return false;
    }
    final path = uri.path.isEmpty ? '/' : uri.path;
    return path == '/oauth' || path == 'oauth';
  }
}

class ColorBrand {
  const ColorBrand();
  int get primary => 0xFF00BFAE;
  int get primaryDark => 0xFF067E6B;
  int get surface => 0xFFFFFFFF;
}