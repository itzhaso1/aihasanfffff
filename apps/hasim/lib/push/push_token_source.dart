import 'package:flutter/foundation.dart';

/// Supplies a real device token. A null token must never be sent to Laravel.
abstract class PushTokenSource {
  bool get supported;

  Future<String?> currentToken();

  Stream<String> get refreshes;

  Stream<Map<String, dynamic>> get openedNotifications;

  Future<Map<String, dynamic>?> takeLaunchNotification();
}

/// FCM registration tokens for Android and iOS. Raw APNs tokens are not used.
String? pushProviderForPlatform() {
  if (kIsWeb) return null;
  return switch (defaultTargetPlatform) {
    TargetPlatform.android => 'fcm',
    TargetPlatform.iOS => 'fcm',
    _ => null,
  };
}

String pushPlatformName() {
  if (kIsWeb) return 'web';
  return defaultTargetPlatform.name;
}

bool pushPlatformSupported() => pushProviderForPlatform() != null;

/// Mobile platforms can register, but this build has no FCM/APNs SDK token.
class UnconfiguredNativePushTokenSource implements PushTokenSource {
  const UnconfiguredNativePushTokenSource();

  @override
  bool get supported => pushPlatformSupported();

  @override
  Future<String?> currentToken() async => null;

  @override
  Stream<String> get refreshes => const Stream<String>.empty();

  @override
  Stream<Map<String, dynamic>> get openedNotifications =>
      const Stream<Map<String, dynamic>>.empty();

  @override
  Future<Map<String, dynamic>?> takeLaunchNotification() async => null;
}

/// Token source backed by a native messaging gateway (FCM in production).
class NativeDevicePushTokenSource implements PushTokenSource {
  NativeDevicePushTokenSource(this._gateway);

  final NativeMessagingGateway _gateway;

  @override
  bool get supported => _gateway.isAvailable && pushPlatformSupported();

  @override
  Future<String?> currentToken() async {
    if (!supported) return null;
    try {
      await _gateway.initialize();
      final allowed = await _gateway.requestPermission();
      if (!allowed) return null;
      final token = (await _gateway.token())?.trim();
      if (token == null || token.isEmpty) return null;
      return token;
    } catch (_) {
      return null;
    }
  }

  @override
  Stream<String> get refreshes => _gateway.tokenRefreshes;

  @override
  Stream<Map<String, dynamic>> get openedNotifications =>
      _gateway.openedNotifications;

  @override
  Future<Map<String, dynamic>?> takeLaunchNotification() async {
    try {
      return await _gateway.takeLaunchNotification();
    } catch (_) {
      return null;
    }
  }
}

/// Native FCM/APNs facade. Tests inject a fake; production uses Firebase.
abstract class NativeMessagingGateway {
  bool get isAvailable;

  Future<void> initialize();

  Future<bool> requestPermission();

  Future<String?> token();

  Stream<String> get tokenRefreshes;

  Stream<Map<String, dynamic>> get openedNotifications;

  Future<Map<String, dynamic>?> takeLaunchNotification();
}
