import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter/material.dart';
import 'package:hasim/app.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/utils/greeting.dart';
import 'package:hasim/core/utils/relative_time.dart';
import 'package:hasim/features/auth/presentation/login_screen.dart';
import 'package:hasim/features/auth/presentation/splash_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  String? _token;

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> clearToken() async => _token = null;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('greetingFor', () {
    test('morning', () {
      expect(greetingFor(DateTime(2026, 1, 1, 8)), 'صباح الخير');
    });
    test('evening', () {
      expect(greetingFor(DateTime(2026, 1, 1, 15)), 'مساء الخير');
    });
    test('hello late', () {
      expect(greetingFor(DateTime(2026, 1, 1, 22)), 'مرحباً');
    });
  });

  group('relativeTimeAr', () {
    test('now', () {
      final now = DateTime(2026, 1, 1, 12);
      expect(relativeTimeAr(now, now: now), 'الآن');
    });
    test('same day clock', () {
      final now = DateTime(2026, 2, 2, 14, 22);
      expect(inboxListTimeAr(now, now: now), '14:22');
    });
  });

  group('ThemeModeController', () {
    test('persists light mode', () async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final container = ProviderContainer(
        overrides: [sharedPrefsProvider.overrideWithValue(prefs)],
      );
      addTearDown(container.dispose);

      expect(container.read(themeModeControllerProvider), ThemeMode.light);
      await container
          .read(themeModeControllerProvider.notifier)
          .setMode(ThemeMode.light);
      expect(container.read(themeModeControllerProvider), ThemeMode.light);
      expect(prefs.getString('theme_mode'), 'light');
    });
  });

  testWidgets('SplashScreen shows HASIM splash art then login', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();

    final secure = _MemorySecureStore();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          secureStoreProvider.overrideWithValue(secure),
          sharedPrefsProvider.overrideWithValue(prefs),
          apiClientProvider.overrideWithValue(
            isolatedTestApiClient(
              secureStore: secure,
              prefsStore: PrefsStore(prefs),
            ),
          ),
        ],
        child: const HasimApp(),
      ),
    );

    await tester.pump();
    expect(find.byType(SplashScreen), findsOneWidget);
    expect(find.byKey(const Key('hasim-splash-art')), findsOneWidget);
    await tester.pump(const Duration(milliseconds: 2800));
    await tester.pumpAndSettle();
    expect(find.byType(LoginScreen), findsOneWidget);
    expect(find.byKey(const Key('hasim-login-art')), findsOneWidget);
    expect(find.text('دخول'), findsOneWidget);
    expect(find.textContaining('نسيت كلمة المرور'), findsOneWidget);
    expect(find.textContaining('Google'), findsOneWidget);
  });
}
