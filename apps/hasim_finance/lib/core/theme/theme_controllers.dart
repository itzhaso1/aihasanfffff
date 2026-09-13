import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';

final localeProvider = NotifierProvider<LocaleController, String>(LocaleController.new);
final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(
  ThemeModeController.new,
);

class LocaleController extends Notifier<String> {
  @override
  String build() {
    return ref.read(prefsStoreProvider).localeCode;
  }

  Future<void> setLocale(String code) async {
    state = code;
    await ref.read(prefsStoreProvider).setLocaleCode(code);
  }
}

class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() {
    return switch (ref.read(prefsStoreProvider).themeMode) {
      'light' => ThemeMode.light,
      'dark' => ThemeMode.dark,
      _ => ThemeMode.system,
    };
  }

  Future<void> setMode(ThemeMode mode) async {
    state = mode;
    await ref.read(prefsStoreProvider).setThemeMode(switch (mode) {
      ThemeMode.light => 'light',
      ThemeMode.dark => 'dark',
      _ => 'system',
    });
  }
}
