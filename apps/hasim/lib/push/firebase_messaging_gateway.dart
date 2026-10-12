import 'dart:async';

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:hasim/push/firebase_push_config.dart';
import 'package:hasim/push/push_token_source.dart';

Map<String, dynamic> firebaseMessageData(RemoteMessage message) {
  return Map<String, dynamic>.from(message.data);
}

FirebaseMessagingGateway? _sharedFirebaseMessagingGateway;

FirebaseMessagingGateway sharedFirebaseMessagingGateway() {
  return _sharedFirebaseMessagingGateway ??= FirebaseMessagingGateway();
}

class FirebaseMessagingGateway implements NativeMessagingGateway {
  FirebaseMessagingGateway();

  final _opened = StreamController<Map<String, dynamic>>.broadcast();
  final _tokenRefreshes = StreamController<String>.broadcast();
  StreamSubscription<RemoteMessage>? _openedSub;
  StreamSubscription<String>? _tokenSub;
  bool _initialized = false;
  bool _launchConsumed = false;
  Map<String, dynamic>? _launch;

  @override
  bool get isAvailable {
    if (kIsWeb) return false;
    return defaultTargetPlatform == TargetPlatform.android ||
        defaultTargetPlatform == TargetPlatform.iOS;
  }

  @override
  Future<void> initialize() async {
    if (_initialized) return;
    if (!isAvailable) return;
    final options = FirebasePushConfig.optionsForPlatform();
    if (options == null) {
      throw StateError('Firebase push options are not configured.');
    }
    if (Firebase.apps.isEmpty) {
      await Firebase.initializeApp(options: options);
    }
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      await FirebaseMessaging.instance.setForegroundNotificationPresentationOptions(
        alert: true,
        badge: true,
        sound: true,
      );
    }
    _openedSub ??= FirebaseMessaging.onMessageOpenedApp.listen((message) {
      final data = firebaseMessageData(message);
      if (data.isNotEmpty) {
        _opened.add(data);
      }
    });
    _tokenSub ??= FirebaseMessaging.instance.onTokenRefresh.listen((token) {
      final value = token.trim();
      if (value.isNotEmpty) {
        _tokenRefreshes.add(value);
      }
    });
    if (!_launchConsumed) {
      final initial = await FirebaseMessaging.instance.getInitialMessage();
      if (initial != null) {
        _launch = firebaseMessageData(initial);
      }
    }
    _initialized = true;
  }

  @override
  Future<bool> requestPermission() async {
    final settings = await FirebaseMessaging.instance.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );
    return settings.authorizationStatus == AuthorizationStatus.authorized ||
        settings.authorizationStatus == AuthorizationStatus.provisional;
  }

  @override
  Future<String?> token() async {
    final value = (await FirebaseMessaging.instance.getToken())?.trim();
    if (value == null || value.isEmpty) return null;
    return value;
  }

  @override
  Stream<String> get tokenRefreshes => _tokenRefreshes.stream;

  @override
  Stream<Map<String, dynamic>> get openedNotifications => _opened.stream;

  @override
  Future<Map<String, dynamic>?> takeLaunchNotification() async {
    await initialize();
    if (_launchConsumed) return null;
    _launchConsumed = true;
    final pending = _launch;
    _launch = null;
    return pending != null && pending.isNotEmpty ? pending : null;
  }
}
