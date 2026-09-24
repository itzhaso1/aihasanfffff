import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
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

  testWidgets('MoreScreen keeps HASIM tools in grouped cards without chevrons', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();

    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final router = GoRouter(
      routes: [
        GoRoute(path: '/', builder: (context, state) => const MoreScreen()),
        GoRoute(path: '/profile', builder: (_, _) => const Scaffold(body: Text('شاشة الحساب'))),
        GoRoute(path: '/workspaces', builder: (_, _) => const Scaffold(body: Text('شاشة المساحات'))),
        GoRoute(path: '/more/security', builder: (_, _) => const Scaffold(body: Text('شاشة الأمان'))),
        GoRoute(path: '/contacts', builder: (_, _) => const Scaffold(body: Text('شاشة جهات الاتصال'))),
        GoRoute(path: '/contact-groups', builder: (_, _) => const Scaffold(body: Text('شاشة المجموعات'))),
        GoRoute(path: '/channels', builder: (_, _) => const Scaffold(body: Text('شاشة القنوات'))),
        GoRoute(path: '/catalog', builder: (_, _) => const Scaffold(body: Text('شاشة الكتالوج'))),
        GoRoute(path: '/chat-catalog-settings', builder: (_, _) => const Scaffold(body: Text('شاشة إعدادات الكتالوج'))),
        GoRoute(path: '/ai-settings', builder: (_, _) => const Scaffold(body: Text('شاشة الذكاء'))),
        GoRoute(path: '/plans', builder: (_, _) => const Scaffold(body: Text('شاشة الباقة'))),
        GoRoute(path: '/activity', builder: (_, _) => const Scaffold(body: Text('شاشة النشاط'))),
        GoRoute(path: '/notifications', builder: (_, _) => const Scaffold(body: Text('شاشة الإشعارات'))),
        GoRoute(path: '/notification-preferences', builder: (_, _) => const Scaffold(body: Text('شاشة التفضيلات'))),
        GoRoute(path: '/settings', builder: (_, _) => const Scaffold(body: Text('شاشة الإعدادات'))),
      ],
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          secureStoreProvider.overrideWithValue(_MemorySecureStore()),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
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
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('المزيد'), findsNothing);
    expect(find.text('تبديل مساحة العمل'), findsOneWidget);
    expect(find.text('الحساب'), findsOneWidget);
    expect(find.text('حسابي'), findsOneWidget);
    expect(find.text('الأمان والجلسات'), findsOneWidget);
    expect(find.text('إدارة النشاط'), findsOneWidget);
    expect(find.text('جهات الاتصال'), findsOneWidget);
    expect(find.text('مجموعات جهات الاتصال'), findsOneWidget);
    expect(find.text('القنوات'), findsOneWidget);
    expect(find.text('كتالوج الشات'), findsOneWidget);
    await tester.scrollUntilVisible(
      find.text('الاشتراك والاستخدام'),
      120,
      scrollable: find.byType(Scrollable).first,
    );
    expect(find.text('الاشتراك والاستخدام', skipOffstage: false), findsOneWidget);
    expect(find.text('نشاط اليوم'), findsOneWidget);
    expect(find.text('إحصاءات مختصرة'), findsOneWidget);
    expect(find.text('الإعدادات', skipOffstage: false), findsNWidgets(2)); // section + row
    expect(find.text('الإشعارات', skipOffstage: false), findsOneWidget);
    expect(find.text('تفضيلات الإشعارات', skipOffstage: false), findsOneWidget);
    expect(find.text('المظهر والسمات', skipOffstage: false), findsOneWidget);
    expect(find.text('السمات', skipOffstage: false), findsNothing);
    expect(find.text('تسجيل الخروج', skipOffstage: false), findsOneWidget);

    expect(find.byKey(const Key('more-card')), findsNothing);
    expect(Theme.of(tester.element(find.byType(MoreScreen))).brightness, Brightness.light);
    expect(Theme.of(tester.element(find.byType(MoreScreen))).colorScheme.surfaceContainer, Colors.white);

    expect(find.byIcon(Icons.chevron_left), findsNothing);
    expect(find.byIcon(Icons.chevron_right), findsNothing);

    await tester.scrollUntilVisible(
      find.text('حسابي'),
      -120,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.tap(find.text('حسابي'));
    await tester.pumpAndSettle();
    expect(find.text('شاشة الحساب'), findsOneWidget);
  });
}
