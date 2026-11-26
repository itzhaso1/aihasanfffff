import 'dart:async';

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
import 'package:hasim/push/company_push_binder.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:window_manager/window_manager.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  FlutterError.onError = (details) {
    FlutterError.presentError(details);
  };
  PlatformDispatcher.instance.onError = (error, stack) {
    if (kDebugMode) {
      debugPrint('[Hasim] Uncaught async error: $error\n$stack');
    }
    return true;
  };
  // Prevent out-of-memory spikes from large chat media lists
  PaintingBinding.instance.imageCache.maximumSize = 250;
  PaintingBinding.instance.imageCache.maximumSizeBytes = 80 << 20; // 80 MB
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
  final prefs = await SharedPreferences.getInstance();
  InboxDatabase db;
  try {
    // LazyDatabase defers SQLite file opening until first query; avoid blocking frame 1.
    db = InboxDatabase.open();
  } catch (e, st) {
    if (kDebugMode) {
      debugPrint('[Hasim] Local inbox init failed: $e\n$st');
    }
    db = InboxDatabase.memory();
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

  // One-time legacy Hive cleanup/migration runs after first frame without delaying login/splash.
  if (prefs.getBool('hasim_hive_migrated_v1') != true && !db.isClosed) {
    unawaited(() async {
      try {
        await HiveInboxMigrator.migrateIfNeeded(
          InboxLocalStore(db),
        ).timeout(const Duration(seconds: 10));
        await prefs.setBool('hasim_hive_migrated_v1', true);
      } catch (e, st) {
        if (kDebugMode) {
          debugPrint('[Hasim] Background Hive migration skipped: $e\n$st');
        }
      }
    }());
  }
}
