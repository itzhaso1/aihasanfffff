/// حاسم — إعدادات التطبيق
class AppConfig {
  AppConfig._();

  static const String apiBase = String.fromEnvironment(
    'API_BASE',
    defaultValue: 'http://127.0.0.1:8000',
  );

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

  /// Dio base must end with `/` so relative paths resolve under mobile v1.
  static String get mobileApiBase => '$hostBase/api/mobile/v1/';

  static const String appName = 'حاسم';
  static const ColorBrand brand = ColorBrand();
}

class ColorBrand {
  const ColorBrand();
  int get primary => 0xFF06C2A4;
  int get primaryDark => 0xFF067E6B;
  int get surface => 0xFFF3FCFA;
}
