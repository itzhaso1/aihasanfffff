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
import 'package:shared_preferences/shared_preferences.dart';
import 'package:window_manager/window_manager.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
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
  final db = InboxDatabase.open();
  await HiveInboxMigrator.migrateIfNeeded(InboxLocalStore(db));
  final prefs = await SharedPreferences.getInstance();

  runApp(
    ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        inboxDatabaseProvider.overrideWith((ref) {
          ref.onDispose(db.close);
          return db;
        }),
      ],
      child: const HasimApp(),
    ),
  );
}
