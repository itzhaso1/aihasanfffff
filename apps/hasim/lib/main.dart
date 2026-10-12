import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/app.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/hive_inbox_migrator.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/widgets/desktop_window_title_bar.dart';
import 'package:hasim/push/firebase_messaging_gateway.dart';
import 'package:hasim/push/firebase_push_config.dart';
import 'package:hasim/push/company_push_binder.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:window_manager/window_manager.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  FlutterError.onError = (details) {
    FlutterError.presentError(details);
  };
  if (hasimUsesDesktopTitleBar) {
    await windowManager.ensureInitialized();
    const options = WindowOptions(
      title: 'حاسم',
      titleBarStyle: TitleBarStyle.hidden,
      windowButtonVisibility: false,
    );
    await windowManager.waitUntilReadyToShow(options, () async {
      await windowManager.show();
      await windowManager.focus();
    });
  }
  if (kReleaseMode) {
    AppConfig.apiBase;
  }
  InboxDatabase db;
  try {
    db = InboxDatabase.open();
    await HiveInboxMigrator.migrateIfNeeded(InboxLocalStore(db)).timeout(const Duration(seconds: 30));
  } catch (e, st) {
    if (kDebugMode) {
      debugPrint('[Hasim] Local inbox init failed: $e\n$st');
    }
    db = InboxDatabase.memory();
  }
  final prefs = await SharedPreferences.getInstance();
  try {
    if (FirebasePushConfig.isConfigured) {
      await sharedFirebaseMessagingGateway().initialize();
    }
  } catch (_) {
    // Token registration retries after login; missing Firebase config is not a crash.
  }

  runApp(
    ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        inboxDatabaseProvider.overrideWith((ref) {
          ref.onDispose(db.close);
          return db;
        }),
      ],
      child: const CompanyPushBinder(child: HasimApp()),
    ),
  );
}
