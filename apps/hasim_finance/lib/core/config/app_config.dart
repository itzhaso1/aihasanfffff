import 'package:flutter/foundation.dart';

/// حاسم للمالية — إعدادات التطبيق
class AppConfig {
  AppConfig._();

  static const String apiBaseDefineName = 'API_BASE';

  static const String _apiBaseFromEnvironment = String.fromEnvironment(
    apiBaseDefineName,
    defaultValue: '',
  );

  static const String debugFallbackApiBase = 'http://127.0.0.1:8000';

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

    if (kReleaseMode) {
      return assertProductionApiHost(
        normalizeHostBase(rawFromEnvironment),
        defineName: apiBaseDefineName,
      );
    }

    final raw = rawFromEnvironment.trim().isNotEmpty
        ? rawFromEnvironment
        : debugFallbackApiBase;

    return normalizeHostBase(raw);
  }

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

  static String normalizeHostBase(String raw) {
    var value = raw.trim();
    while (value.endsWith('/')) {
      value = value.substring(0, value.length - 1);
    }
    const suffixes = [
      '/api/finance/v1',
      '/api/cashier/v1',
      '/api/mobile/v1',
      '/api/mobile',
      '/api',
    ];
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

  static String financeApiBase(String host) =>
      '${normalizeHostBase(host)}/api/finance/v1/';

  static const String appName = 'حاسم للمالية';
  static const int brandPrimary = 0xFF06C2A4;
  static const int brandDark = 0xFF067E6B;

  /// Public OAuth client id. Never a client secret.
  static const String googleOAuthClientId = String.fromEnvironment(
    'GOOGLE_OAUTH_CLIENT_ID',
    defaultValue: '',
  );

  /// Android/iOS server (web) client id for the same Google Cloud project as Laravel.
  static const String googleOAuthServerClientId = String.fromEnvironment(
    'GOOGLE_OAUTH_SERVER_CLIENT_ID',
    defaultValue: '',
  );
}
