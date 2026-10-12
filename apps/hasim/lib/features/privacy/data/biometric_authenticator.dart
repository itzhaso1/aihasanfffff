import 'package:local_auth/local_auth.dart';

abstract class BiometricAuthenticator {
  Future<bool> get isAvailable;
  Future<bool> authenticate(String reason);
}

class LocalBiometricAuthenticator implements BiometricAuthenticator {
  LocalBiometricAuthenticator({LocalAuthentication? auth}) : _auth = auth ?? LocalAuthentication();

  final LocalAuthentication _auth;

  @override
  Future<bool> get isAvailable async {
    try {
      return await _auth.canCheckBiometrics;
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> authenticate(String reason) async {
    try {
      return await _auth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(
          biometricOnly: true,
          stickyAuth: true,
          useErrorDialogs: true,
        ),
      );
    } catch (_) {
      return false;
    }
  }
}

class FakeBiometricAuthenticator implements BiometricAuthenticator {
  FakeBiometricAuthenticator({this.available = false, this.accepts = true});

  final bool available;
  final bool accepts;

  @override
  Future<bool> get isAvailable async => available;

  @override
  Future<bool> authenticate(String reason) async => accepts;
}
