import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/core/widgets/hasim_nav_rail.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/features/settings/presentation/settings_screen.dart';
import 'package:hasim/features/stories/data/story_repository.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _FakeStoryRepository extends StoryRepository {
  _FakeStoryRepository(super.api);

  @override
  Future<List<StoryModel>> list() async => const [];
}

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

  late SharedPreferences prefs;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
  });

  Widget app() {
    final router = GoRouter(
      initialLocation: '/settings',
      routes: [
        GoRoute(path: '/settings', builder: (_, _) => const SettingsScreen()),
        GoRoute(
          path: '/profile',
          builder: (_, _) => const Scaffold(body: Text('شاشة الحساب')),
        ),
        GoRoute(
          path: '/more/security',
          builder: (_, _) => const Scaffold(body: Text('شاشة الأمان')),
        ),
        GoRoute(
          path: '/notification-preferences',
          builder: (_, _) => const Scaffold(body: Text('شاشة التفضيلات')),
        ),
        GoRoute(
          path: '/catalog',
          builder: (_, _) => const Scaffold(body: Text('شاشة المنتجات والمخزون')),
        ),
        GoRoute(
          path: '/chat-themes',
          builder: (_, _) => const Scaffold(body: Text('شاشة المظهر والسمات')),
        ),
        GoRoute(
          path: '/ai-settings',
          builder: (_, _) => const Scaffold(body: Text('شاشة الذكاء')),
        ),
        GoRoute(
          path: '/conversations',
          builder: (_, _) => const Scaffold(body: Text('المحادثات')),
        ),
        GoRoute(
          path: '/email',
          builder: (_, _) => const Scaffold(body: Text('البريد')),
        ),
        GoRoute(
          path: '/updates',
          builder: (_, _) => const Scaffold(body: Text('التحديثات')),
        ),
        GoRoute(
          path: '/more',
          builder: (_, _) => const Scaffold(body: Text('المزيد')),
        ),
        GoRoute(
          path: '/login',
          builder: (_, _) => const Scaffold(body: Text('تسجيل الدخول')),
        ),
      ],
    );

    final secure = _MemorySecureStore();
    final prefsStore = PrefsStore(prefs);
    final api = isolatedTestApiClient(
      secureStore: secure,
      prefsStore: prefsStore,
    );
    return ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(secure),
        apiClientProvider.overrideWithValue(api),
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
        storyRepositoryProvider.overrideWithValue(_FakeStoryRepository(api)),
      ],
      child: MaterialApp.router(
        theme: AppTheme.light(),
        locale: const Locale('ar'),
        builder: (context, child) => Directionality(
          textDirection: TextDirection.rtl,
          child: child ?? const SizedBox.shrink(),
        ),
        routerConfig: router,
      ),
    );
  }

  Finder settingsItem(String label) => find.text(label);

  testWidgets('mobile settings keep the stacked list without a nav rail', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(app());
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.byType(HasimBottomNav), findsNothing);
    expect(find.byType(HasimNavRail), findsNothing);
    expect(find.text('ابحث في الإعدادات'), findsNothing);
    expect(find.byIcon(Icons.search_rounded), findsOneWidget);
    expect(find.byKey(const Key('settings-back-button')), findsOneWidget);
    final title = tester.getCenter(find.text('الإعدادات'));
    final search = tester.getCenter(
      find.byKey(const Key('settings-search-button')),
    );
    expect(search.dx, lessThan(title.dx - 40));
    await tester.tap(settingsItem('حسابي'));
    await tester.pumpAndSettle();
    expect(find.text('شاشة الحساب'), findsOneWidget);
    GoRouter.of(tester.element(find.text('شاشة الحساب'))).go('/settings');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('settings-search-button')));
    await tester.pump();
    expect(find.text('الإعدادات'), findsNothing);
    expect(find.byKey(const Key('settings-back-button')), findsNothing);
    expect(find.byKey(const Key('settings-search-button')), findsNothing);
    expect(find.byIcon(Icons.search_rounded), findsNothing);
    expect(find.text('ابحث في الإعدادات'), findsOneWidget);
    expect(find.byIcon(Icons.chevron_left_rounded), findsNothing);
    expect(settingsItem('حسابي'), findsOneWidget);
    expect(settingsItem('الأمان والجلسات'), findsOneWidget);
    expect(settingsItem('تفضيلات الإشعارات'), findsOneWidget);
    expect(find.text('إعدادات المنتجات'), findsNothing);
    expect(find.text('حالة الكتالوج'), findsNothing);
    final settingsList = find.descendant(
      of: find.byType(ListView),
      matching: find.byType(Scrollable),
    );
    await tester.scrollUntilVisible(
      find.text('المنتجات والمخزون'),
      120,
      scrollable: settingsList,
    );
    expect(settingsItem('المنتجات والمخزون'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('المظهر والسمات'),
      120,
      scrollable: settingsList,
    );
    expect(settingsItem('المظهر والسمات'), findsOneWidget);
    expect(find.text('عنوان API'), findsNothing);
    expect(find.text('الإشعارات الفورية (Push)'), findsNothing);
    await tester.scrollUntilVisible(
      find.text('تسجيل الخروج'),
      120,
      scrollable: settingsList,
    );
    expect(settingsItem('تسجيل الخروج'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('المظهر والسمات'),
      -120,
      scrollable: settingsList,
    );
    expect(find.byType(SegmentedButton<ThemeMode>), findsNothing);
    await tester.tap(settingsItem('المظهر والسمات'));
    await tester.pumpAndSettle();
    expect(find.text('شاشة المظهر والسمات'), findsOneWidget);
    expect(find.text('المساعدة'), findsNothing);
    expect(find.text('حول التطبيق'), findsNothing);
    expect(find.text('البيانات والتخزين'), findsNothing);

    GoRouter.of(
      tester.element(find.text('شاشة المظهر والسمات')),
    ).go('/settings');
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('settings-search-close')));
    await tester.pumpAndSettle();
    expect(find.text('الإعدادات'), findsOneWidget);
    expect(find.text('إعدادات المنتجات'), findsNothing);
  });

  testWidgets(
    'desktop settings use a rail, searchable panel, and detail pane',
    (tester) async {
      tester.view.physicalSize = const Size(1280, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(app());
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));
      await tester.pumpAndSettle();

      expect(find.byType(HasimNavRail), findsOneWidget);
      expect(find.byType(HasimBottomNav), findsNothing);
      expect(find.byKey(const Key('hasim-settings-panel')), findsOneWidget);
      expect(find.byKey(const Key('hasim-settings-detail')), findsOneWidget);
      expect(find.text('ابحث في الإعدادات'), findsOneWidget);
      expect(
        find.descendant(
          of: find.byKey(const Key('hasim-settings-detail')),
          matching: find.byType(TextField),
        ),
        findsNWidgets(6),
      );
      expect(
        find.text('اختر أحد الإعدادات من القائمة لعرض التفاصيل هنا.'),
        findsNothing,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('hasim-settings-panel')),
          matching: find.text('حسابي'),
        ),
        findsOneWidget,
      );
      expect(settingsItem('الأمان والجلسات'), findsOneWidget);
      expect(settingsItem('تفضيلات الإشعارات'), findsOneWidget);
      expect(settingsItem('المظهر والسمات'), findsOneWidget);
      await tester.tap(
        find.descendant(
          of: find.byKey(const Key('hasim-settings-panel')),
          matching: find.text('حسابي'),
        ),
      );
      await tester.pump();
      expect(
        find.text('اختر أحد الإعدادات من القائمة لعرض التفاصيل هنا.'),
        findsNothing,
      );
      await tester.drag(find.text('المظهر والسمات'), const Offset(0, -180));
      await tester.pumpAndSettle();
      expect(find.text('عنوان API'), findsNothing);
      expect(settingsItem('المنتجات والمخزون'), findsOneWidget);
      expect(settingsItem('إعدادات المنتجات'), findsNothing);
      await tester.drag(find.text('المظهر والسمات'), const Offset(0, -280));
      await tester.pumpAndSettle();
      expect(find.text('الإشعارات الفورية (Push)'), findsNothing);
      expect(settingsItem('تسجيل الخروج'), findsOneWidget);
      expect(find.text('المساعدة'), findsNothing);
      expect(find.text('حول التطبيق'), findsNothing);
      expect(find.text('البيانات والتخزين'), findsNothing);

      final rail = tester.getRect(find.byType(HasimNavRail));
      final panel = tester.getRect(
        find.byKey(const Key('hasim-settings-panel')),
      );
      final detail = tester.getRect(
        find.byKey(const Key('hasim-settings-detail')),
      );
      expect(rail.center.dx, greaterThan(panel.center.dx));
      expect(panel.center.dx, greaterThan(detail.center.dx));
      expect(detail.width, greaterThan(panel.width));

      expect(
        find.descendant(
          of: find.byKey(const Key('hasim-settings-detail')),
          matching: find.byType(TextField),
        ),
        findsNWidgets(6),
      );
      expect(find.text('شاشة الحساب'), findsNothing);

      await tester.enterText(
        find.widgetWithText(TextField, 'ابحث في الإعدادات'),
        'ذكاء',
      );
      await tester.pump();
      expect(
        find.descendant(
          of: find.byKey(const Key('hasim-settings-panel')),
          matching: find.text('حسابي'),
        ),
        findsNothing,
      );
      expect(
        find.descendant(
          of: find.byKey(const Key('hasim-settings-panel')),
          matching: find.text('إعدادات الذكاء الاصطناعي'),
        ),
        findsOneWidget,
      );
      expect(find.text('شاشة الحساب'), findsNothing);
    },
  );
}
