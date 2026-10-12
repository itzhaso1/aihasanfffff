import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/app.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/auth/presentation/splash_screen.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/router/app_router.dart';
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

class _DelayedSecureStore extends SecureStore {
  final token = Completer<String?>();

  @override
  Future<String?> readToken() => token.future;

  @override
  Future<void> clearToken() async {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('splash shows the white HASIM poster without a mint overlay', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

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

    final image = tester.widget<Image>(
      find.byKey(const Key('hasim-splash-art')),
    );
    expect(image.image, isA<AssetImage>());
    expect((image.image as AssetImage).assetName, hasimSplashAsset);

    final scaffold = tester.widget<Scaffold>(find.byType(Scaffold).first);
    expect(scaffold.backgroundColor, Colors.white);
    expect(AppTheme.brand, const Color(0xFF00BFAE));
    expect(
      find.byWidgetPredicate(
        (widget) =>
            widget is DecoratedBox &&
            widget.decoration is BoxDecoration &&
            (widget.decoration as BoxDecoration).gradient is RadialGradient,
      ),
      findsNothing,
    );

    await tester.pump(const Duration(milliseconds: 2800));
    await tester.pumpAndSettle();
  });

  testWidgets('auth bootstrap keeps the same router and splash instance', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final secure = _DelayedSecureStore();
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

    final splashFinder = find.byType(SplashScreen);
    final container = ProviderScope.containerOf(tester.element(splashFinder));
    expect(container.read(authControllerProvider).bootstrapping, isTrue);
    final router = container.read(appRouterProvider);
    final splashState = tester.state(splashFinder);

    secure.token.complete(null);
    await tester.pump();
    await tester.pump();
    expect(container.read(authControllerProvider).bootstrapping, isFalse);
    expect(container.read(appRouterProvider), same(router));
    expect(tester.state(splashFinder), same(splashState));

    await tester.pump(const Duration(milliseconds: 2800));
    await tester.pumpAndSettle();
  });
}
