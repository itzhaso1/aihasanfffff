import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';

/// Client Firebase options from dart-define. Not a server secret.
class FirebasePushConfig {
  FirebasePushConfig._();

  static const androidApiKey = String.fromEnvironment('FIREBASE_ANDROID_API_KEY');
  static const androidAppId = String.fromEnvironment('FIREBASE_ANDROID_APP_ID');
  static const androidSenderId = String.fromEnvironment('FIREBASE_MESSAGING_SENDER_ID');
  static const androidProjectId = String.fromEnvironment('FIREBASE_PROJECT_ID');

  static const iosApiKey = String.fromEnvironment('FIREBASE_IOS_API_KEY');
  static const iosAppId = String.fromEnvironment('FIREBASE_IOS_APP_ID');
  static const iosSenderId = String.fromEnvironment('FIREBASE_IOS_MESSAGING_SENDER_ID');
  static const iosProjectId = String.fromEnvironment('FIREBASE_IOS_PROJECT_ID');
  static const iosBundleId = String.fromEnvironment(
    'FIREBASE_IOS_BUNDLE_ID',
    defaultValue: 'sa.hasim.hasim',
  );

  static FirebaseOptions? optionsForPlatform() {
    if (kIsWeb) return null;
    return switch (defaultTargetPlatform) {
      TargetPlatform.android => _android,
      TargetPlatform.iOS => _ios,
      _ => null,
    };
  }

  static bool get isConfigured => optionsForPlatform() != null;

  static FirebaseOptions? get _android {
    if (androidApiKey.isEmpty ||
        androidAppId.isEmpty ||
        androidSenderId.isEmpty ||
        androidProjectId.isEmpty) {
      return null;
    }
    return FirebaseOptions(
      apiKey: androidApiKey,
      appId: androidAppId,
      messagingSenderId: androidSenderId,
      projectId: androidProjectId,
    );
  }

  static FirebaseOptions? get _ios {
    final sender = iosSenderId.isNotEmpty ? iosSenderId : androidSenderId;
    final project = iosProjectId.isNotEmpty ? iosProjectId : androidProjectId;
    if (iosApiKey.isEmpty || iosAppId.isEmpty || sender.isEmpty || project.isEmpty) {
      return null;
    }
    return FirebaseOptions(
      apiKey: iosApiKey,
      appId: iosAppId,
      messagingSenderId: sender,
      projectId: project,
      iosBundleId: iosBundleId,
    );
  }
}
