import '../api/cashier_api.dart';

class GoogleDesktopOauth {
  const GoogleDesktopOauth._();

  static Future<String> signIn() {
    throw ApiException('يحتاج إعداد Google');
  }
}
