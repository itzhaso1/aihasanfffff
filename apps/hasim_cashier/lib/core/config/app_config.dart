import 'package:flutter/foundation.dart';

class AppConfig {
  AppConfig._();

  static const appName = 'كاشير حاسم';
  static const apiVersion = 'cashier/v1';

  static const String apiBaseDefineName = 'CASHIER_API_BASE';

  /// SQLite-first POS: checkout never waits on Laravel.
  /// First-connect may still call cashier auth (password or Google),
  /// workspace bind, device register, catalog snapshot, and `/sync/push`.
  static const bool offlineOnly = true;

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
      '/api/cashier/v1',
      '/api/finance/v1',
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

  static String get apiRoot => '${apiBase}/api/$apiVersion';

  /// Public Google OAuth client id for cashier sign-in.
  /// Override via --dart-define=GOOGLE_OAUTH_CLIENT_ID=...
  static const googleOAuthClientId = String.fromEnvironment(
    'GOOGLE_OAUTH_CLIENT_ID',
    defaultValue: '',
  );

  /// Optional. Only for a Google *web* OAuth client on desktop loopback.
  static const googleOAuthClientSecret = String.fromEnvironment(
    'GOOGLE_OAUTH_CLIENT_SECRET',
    defaultValue: '',
  );

  /// Optional Android/iOS server client id (same Google Cloud project as Laravel).
  static const googleOAuthServerClientId = String.fromEnvironment(
    'GOOGLE_OAUTH_SERVER_CLIENT_ID',
    defaultValue: '',
  );

  static const connectTimeout = Duration(seconds: 20);
  static const receiveTimeout = Duration(seconds: 30);

  /// Local refresh intervals (SQLite / UI). Not network polling to a server.
  static const menuPollSeconds = 5;
  static const menuPollIdleSeconds = 30;
  static const tablesPollSeconds = 5;
  static const tablesPollIdleSeconds = 30;
  static const kitchenPollSeconds = 8;

  /// Background push+pull interval while the app is foregrounded and the
  /// workspace is cloud-linked. Polling only — not WebSocket.
  static const autoSyncSeconds = 15;

  /// Widget tests can disable the periodic timer so [pumpAndSettle] does not hang.
  static bool autoSyncEnabled = true;
}
