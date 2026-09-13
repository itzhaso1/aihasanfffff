class AppConfig {
  static const appName = 'كاشير حاسم';
  static const apiVersion = 'cashier/v1';

  /// SQLite-first POS: checkout never waits on Laravel.
  /// First-connect may still call cashier auth (password or Google),
  /// workspace bind, device register, catalog snapshot, and `/sync/push`.
  static const bool offlineOnly = true;

  /// Override via --dart-define=CASHIER_API_BASE=https://example.com
  static const apiBase = String.fromEnvironment(
    'CASHIER_API_BASE',
    defaultValue: 'http://127.0.0.1:8000',
  );

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

  static String get apiRoot => '$apiBase/api/$apiVersion';

  static const connectTimeout = Duration(seconds: 20);
  static const receiveTimeout = Duration(seconds: 30);

  /// Local refresh intervals (SQLite / UI). Not network polling to a server.
  static const menuPollSeconds = 5;
  static const tablesPollSeconds = 5;
  static const kitchenPollSeconds = 8;

  /// Background push+pull interval while the app is foregrounded and the
  /// workspace is cloud-linked. Polling only — not WebSocket.
  static const autoSyncSeconds = 5;

  /// Widget tests can disable the periodic timer so [pumpAndSettle] does not hang.
  static bool autoSyncEnabled = true;
}
