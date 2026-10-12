import 'dart:convert';
import 'dart:math';

import 'package:crypto/crypto.dart';

/// رمز PIN من 4 أرقام، يُحفظ كبصمة لا كنص.
abstract final class PinCodec {
  static const length = 4;

  static bool isValid(String pin) => RegExp(r'^\d{4}$').hasMatch(pin);

  static String salt() {
    final bytes = List<int>.generate(16, (_) => Random.secure().nextInt(256));
    return base64UrlEncode(bytes);
  }

  static String hash(String pin, String salt) {
    return sha256.convert(utf8.encode('$salt:$pin')).toString();
  }

  static bool matches({required String pin, required String salt, required String hash}) {
    return hash == PinCodec.hash(pin, salt);
  }
}
