import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:flutter/material.dart';

void main() {
  test('ThemeModeController.encode roundtrip labels', () {
    expect(ThemeModeController.encode(ThemeMode.light), 'light');
    expect(ThemeModeController.encode(ThemeMode.dark), 'dark');
    expect(ThemeModeController.encode(ThemeMode.system), 'system');
  });
}
