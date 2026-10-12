import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/stories/data/story_repository.dart';
import 'package:hasim/features/stories/presentation/updates_screen.dart';
import 'package:hasim/features/stories/presentation/widgets/updates_empty_illustration.dart';
import 'package:hasim/realtime/realtime_service.dart';
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

class _FakeStoryRepository extends StoryRepository {
  _FakeStoryRepository(super.api);

  @override
  Future<List<StoryModel>> list() async => const [];
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  late SharedPreferences prefs;
  late ApiClient api;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    prefs = await SharedPreferences.getInstance();
    api = isolatedTestApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(prefs),
    );
  });

  Widget app({required _FakeStoryRepository repo}) {
    final router = GoRouter(
      routes: [
        GoRoute(path: '/', builder: (context, state) => const UpdatesScreen()),
        GoRoute(path: '/stories/create', builder: (context, state) => const Scaffold(body: Text('شاشة إنشاء التحديث'))),
        GoRoute(path: '/profile', builder: (context, state) => const Scaffold(body: Text('الملف'))),
      ],
    );

    return ProviderScope(
      overrides: [
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(api),
        storyRepositoryProvider.overrideWithValue(repo),
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
    );
  }

  testWidgets('updates empty state matches modern chrome and CTA', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeStoryRepository(api);
    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    expect(find.text('حاسم'), findsOneWidget);
    expect(find.text('التحديثات'), findsOneWidget);
    expect(find.text('تابع كل جديد في مكان واحد'), findsOneWidget);
    expect(find.text('ابحث في التحديثات...'), findsOneWidget);
    expect(find.text('حالتي'), findsNothing);
    expect(find.byKey(const Key('updates-profile-bubble')), findsOneWidget);
    expect(find.byKey(const Key('hasim-more-btn')), findsOneWidget);
    expect(find.byType(UpdatesEmptyIllustration), findsOneWidget);
    expect(find.text('لا توجد تحديثات حالياً'), findsOneWidget);
    expect(
      find.text('ستظهر هنا آخر التحديثات والمستجدات من حسابك أو من المصادر التي تتابعها.'),
      findsOneWidget,
    );
    expect(find.text('+ اضغط + لإنشاء تحديثك'), findsOneWidget);
    expect(find.text('شارك أفكارك'), findsOneWidget);
    expect(find.text('ابقَ على اطلاع'), findsOneWidget);
    expect(find.text('نصائح ومحتوى'), findsOneWidget);

    final bubble = tester.getCenter(find.byKey(const Key('updates-profile-bubble')));
    final more = tester.getCenter(find.byKey(const Key('hasim-more-btn')));
    expect(bubble.dx, greaterThan(more.dx));

    await tester.tap(find.byKey(const Key('updates-profile-add')));
    await tester.pumpAndSettle();
    expect(find.text('شاشة إنشاء التحديث'), findsOneWidget);
  });

  testWidgets('updates empty CTA still opens create story', (tester) async {
    tester.view.physicalSize = const Size(360, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    final repo = _FakeStoryRepository(api);
    await tester.pumpWidget(app(repo: repo));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    await tester.pumpAndSettle();

    await tester.tap(find.text('+ اضغط + لإنشاء تحديثك'));
    await tester.pumpAndSettle();
    expect(find.text('شاشة إنشاء التحديث'), findsOneWidget);
  });
}
