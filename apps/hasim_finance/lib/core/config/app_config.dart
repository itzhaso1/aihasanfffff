/// حاسم للمالية — إعدادات التطبيق
class AppConfig {
  AppConfig._();

  static const String apiBase = String.fromEnvironment(
    'API_BASE',
    defaultValue: 'http://127.0.0.1:8000',
  );

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
