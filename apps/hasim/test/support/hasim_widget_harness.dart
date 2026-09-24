import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

SharedPreferences? _hasimTestPrefs;

/// Call once from `main()` before widget tests that use [hasimTestApp].
Future<void> initHasimTestPrefs() async {
  SharedPreferences.setMockInitialValues({});
  _hasimTestPrefs = await SharedPreferences.getInstance();
}

/// MaterialApp + Riverpod prefs for widget tests.
Widget hasimTestApp(Widget child, {ThemeData? theme}) {
  final prefs = _hasimTestPrefs;
  assert(
    prefs != null,
    'Call await initHasimTestPrefs() in test main() before pumpWidget.',
  );

  return UncontrolledProviderScope(
    container: ProviderContainer(
      overrides: [sharedPrefsProvider.overrideWithValue(prefs!)],
    ),
    child: MaterialApp(
      theme: theme ?? AppTheme.light(),
      locale: const Locale('ar'),
      localizationsDelegates: AppLocalizations.localizationsDelegates,
      supportedLocales: AppLocalizations.supportedLocales,
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
      home: Scaffold(body: child),
    ),
  );
}
