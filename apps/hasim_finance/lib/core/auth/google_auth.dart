import 'package:hasim_finance/core/network/api_exception.dart';

class GoogleSignInCancelled implements Exception {
  @override
  String toString() => 'cancelled';
}

abstract class GoogleAccessTokenSource {
  Future<String> obtainAccessToken();
}

bool isGoogleCancellation(Object error) {
  if (error is GoogleSignInCancelled) return true;
  if (error is ApiException) {
    final msg = error.message.toLowerCase();
    return msg.contains('إلغاء') || msg.contains('cancelled') || msg.contains('canceled');
  }
  return false;
}
