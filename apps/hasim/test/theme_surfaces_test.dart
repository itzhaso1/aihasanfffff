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
import 'package:hasim/router/hasim_nav.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_list_row.dart';
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

class _ThemeSurfaceTestHost extends StatelessWidget {
  const _ThemeSurfaceTestHost();

  @override
  Widget build(BuildContext context) {
    final compact = MediaQuery.sizeOf(context).width < 700;
    if (compact) {
      return HasimMobileListShell(
        children: [
          HasimMobileListRow(
            icon: Icons.swap_horiz,
            title: 'تبديل مساحة العمل',
            subtitle: 'مساحة تجريبية',
            onTap: () {},
          ),
        ],
      );
    }

    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        for (var i = 0; i < 3; i++)
          Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: DecoratedBox(
              key: const Key('theme-surface-card'),
              decoration: BoxDecoration(
                color: HasimTokens.cardBg(context),
                borderRadius: BorderRadius.circular(16),
              ),
              child: const SizedBox(height: 72),
            ),
          ),
      ],
    );
  }
}

Future<void> _pumpThemeHost(
  WidgetTester tester, {
  required Size viewSize,
}) async {
  SharedPreferences.setMockInitialValues({});
  final prefs = await SharedPreferences.getInstance();
  tester.view.physicalSize = viewSize;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
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
          body: const _ThemeSurfaceTestHost(),
          bottomNavigationBar: HasimBottomNav(
            selectedIndex: HasimNav.conversationsIndex,
            onDestinationSelected: (_) {},
            items: hasimBottomNavItems(chatBadge: 0, emailBadge: 0, updatesBadge: 0),
          ),
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pumpAndSettle();
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

  testWidgets('mobile shell + nav stay light when platform brightness is dark', (tester) async {
    await _pumpThemeHost(tester, viewSize: const Size(390, 844));

    final hostContext = tester.element(find.byType(_ThemeSurfaceTestHost));
    expect(Theme.of(hostContext).brightness, Brightness.light);
    expect(HasimTokens.cardBg(hostContext), HasimTokens.card);
    expect(HasimTokens.inkColor(hostContext), HasimTokens.text);
    expect(HasimTokens.pageBg(hostContext), HasimTokens.background);

    expect(find.byType(HasimMobileListRow), findsOneWidget);
    expect(find.text('تبديل مساحة العمل'), findsOneWidget);

    final navBar = tester.widget<NavigationBar>(find.byType(NavigationBar));
    expect(navBar.backgroundColor, HasimTokens.card);
    expect(navBar.indicatorColor, HasimTokens.mintChip);
    expect(navBar.selectedIndex, HasimNav.conversationsIndex);
  });

  testWidgets('desktop surface cards stay light when platform brightness is dark', (tester) async {
    await _pumpThemeHost(tester, viewSize: const Size(1280, 900));

    final hostContext = tester.element(find.byType(_ThemeSurfaceTestHost));
    expect(Theme.of(hostContext).brightness, Brightness.light);

    final cards = tester.widgetList<DecoratedBox>(find.byKey(const Key('theme-surface-card')));
    expect(cards.length, 3);
    for (final card in cards) {
      expect((card.decoration as BoxDecoration).color, HasimTokens.card);
    }
  });
}
