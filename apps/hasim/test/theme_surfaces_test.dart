import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/features/more/presentation/more_screen.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

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
  GoogleFonts.config.allowRuntimeFetching = false;

  test('light ColorScheme surfaces stay mint/white', () {
    final scheme = AppTheme.lightScheme();
    expect(scheme.brightness, Brightness.light);
    expect(scheme.surface, HasimTokens.background);
    expect(scheme.surfaceContainer, HasimTokens.card);
    expect(scheme.surfaceContainerLow, HasimTokens.card);
    expect(scheme.onSurface, HasimTokens.text);
    expect(scheme.surface.computeLuminance(), greaterThan(0.8));
    expect(scheme.surfaceContainer.computeLuminance(), greaterThan(0.8));
    expect(scheme.onSurface.computeLuminance(), lessThan(0.2));
  });

  test('dark ColorScheme keeps light text on dark cards', () {
    final scheme = AppTheme.darkScheme();
    expect(scheme.brightness, Brightness.dark);
    expect(scheme.surfaceContainer, HasimTokens.darkCard);
    expect(scheme.onSurface, Colors.white);
    expect(scheme.surfaceContainer.computeLuminance(), lessThan(0.2));
    expect(scheme.onSurface.computeLuminance(), greaterThan(0.8));
  });

  test('theme mode defaults to light instead of following the OS', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final container = ProviderContainer(
      overrides: [sharedPrefsProvider.overrideWithValue(prefs)],
    );
    addTearDown(container.dispose);
    expect(container.read(themeModeControllerProvider), ThemeMode.light);
  });

  testWidgets('More + nav stay light when the platform brightness is dark', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    tester.platformDispatcher.platformBrightnessTestValue = Brightness.dark;
    addTearDown(tester.platformDispatcher.clearPlatformBrightnessTestValue);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          secureStoreProvider.overrideWithValue(_MemorySecureStore()),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          darkTheme: AppTheme.dark(),
          themeMode: ThemeMode.light,
          locale: const Locale('ar'),
          builder: (context, child) => Directionality(
            textDirection: TextDirection.rtl,
            child: child ?? const SizedBox.shrink(),
          ),
          home: Scaffold(
            backgroundColor: HasimTokens.background,
            body: const MoreScreen(),
            bottomNavigationBar: HasimBottomNav(
              selectedIndex: 3,
              onDestinationSelected: (_) {},
              items: hasimBottomNavItems(chatBadge: 0, emailBadge: 0, updatesBadge: 0),
            ),
          ),
        ),
      ),
    );
    await tester.pump();
    await tester.pumpAndSettle();

    final moreContext = tester.element(find.byType(MoreScreen));
    expect(Theme.of(moreContext).brightness, Brightness.light);
    expect(HasimTokens.cardBg(moreContext), HasimTokens.card);
    expect(HasimTokens.inkColor(moreContext), HasimTokens.text);
    expect(HasimTokens.pageBg(moreContext), HasimTokens.background);

    final cards = tester.widgetList<DecoratedBox>(find.byKey(const Key('more-card')));
    expect(cards.length, greaterThanOrEqualTo(3));
    for (final card in cards) {
      expect((card.decoration as BoxDecoration).color, HasimTokens.card);
    }

    final navMaterial = tester.widget<Material>(
      find.descendant(of: find.byType(HasimBottomNav), matching: find.byType(Material)).first,
    );
    expect(navMaterial.color, HasimTokens.card);
    expect(navMaterial.surfaceTintColor, Colors.transparent);

    final navBar = tester.widget<NavigationBar>(find.byType(NavigationBar));
    expect(navBar.backgroundColor, HasimTokens.card);
    expect(navBar.indicatorColor, HasimTokens.mintChip);
  });
}
