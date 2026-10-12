import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/presentation/chat_themes_screen.dart';
import 'package:hasim/features/settings/presentation/settings_screen.dart';
import 'package:hasim/features/settings/presentation/widgets/display_appearance_settings.dart';
import 'package:hasim/features/stories/data/story_repository.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/router/auth_redirect.dart';
import 'package:hasim/router/worker_nav.dart';
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

class _SeededAuthController extends AuthController {
  _SeededAuthController(super.ref, this.seed);

  final AuthState seed;

  @override
  Future<void> bootstrap() async {
    state = seed;
  }
}

const _workerAuth = AuthState(
  bootstrapping: false,
  token: 'worker-token',
  user: UserModel(id: 3, name: 'عامل', email: 'worker@example.com'),
  participant: MobileParticipant(
    type: 'independent_worker',
    workerProfileId: 9,
  ),
);

const _companyAuth = AuthState(
  bootstrapping: false,
  token: 'company-token',
  user: UserModel(id: 1, name: 'شركة', email: 'company@example.com'),
  workspace: WorkspaceModel(id: 4, name: 'مساحة'),
  participant: MobileParticipant(type: 'company_staff'),
);

const _ownerAuth = AuthState(
  bootstrapping: false,
  token: 'owner-token',
  user: UserModel(id: 1, name: 'مالك', email: 'owner@example.com'),
  workspace: WorkspaceModel(id: 4, name: 'مساحة', isOwner: true),
  participant: MobileParticipant(type: 'company_staff'),
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  test('independent worker may stay on personal settings routes', () {
    const worker = (
      bootstrapping: false,
      isAuthenticated: true,
      isIndependentWorker: true,
      hasWorkspace: false,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: worker.bootstrapping,
        isAuthenticated: worker.isAuthenticated,
        isIndependentWorker: worker.isIndependentWorker,
        hasWorkspace: worker.hasWorkspace,
        location: '/settings',
      ),
      isNull,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: worker.bootstrapping,
        isAuthenticated: worker.isAuthenticated,
        isIndependentWorker: worker.isIndependentWorker,
        hasWorkspace: worker.hasWorkspace,
        location: '/privacy-lock',
      ),
      isNull,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: worker.bootstrapping,
        isAuthenticated: worker.isAuthenticated,
        isIndependentWorker: worker.isIndependentWorker,
        hasWorkspace: worker.hasWorkspace,
        location: workerSalesReferenceLocation,
      ),
      isNull,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: worker.bootstrapping,
        isAuthenticated: worker.isAuthenticated,
        isIndependentWorker: worker.isIndependentWorker,
        hasWorkspace: worker.hasWorkspace,
        location: '/chat-themes',
      ),
      isNull,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: worker.bootstrapping,
        isAuthenticated: worker.isAuthenticated,
        isIndependentWorker: worker.isIndependentWorker,
        hasWorkspace: worker.hasWorkspace,
        location: workerAccountLocation,
      ),
      isNull,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: worker.bootstrapping,
        isAuthenticated: worker.isAuthenticated,
        isIndependentWorker: worker.isIndependentWorker,
        hasWorkspace: worker.hasWorkspace,
        location: '/profile',
      ),
      '/conversations',
    );
  });

  test('company settings routing is unchanged', () {
    expect(
      resolveHasimRedirect(
        bootstrapping: false,
        isAuthenticated: true,
        isIndependentWorker: false,
        hasWorkspace: true,
        location: '/settings',
      ),
      isNull,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: false,
        isAuthenticated: true,
        isIndependentWorker: false,
        hasWorkspace: false,
        location: '/settings',
      ),
      '/workspaces',
    );
    for (final location in ['/archive', '/locked']) {
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: false,
          hasWorkspace: true,
          isOwner: false,
          location: location,
        ),
        '/conversations',
        reason: location,
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: false,
          hasWorkspace: true,
          isOwner: true,
          location: location,
        ),
        isNull,
        reason: location,
      );
    }
  });

  test('independent worker special inbox redirect stays unchanged', () {
    for (final location in ['/archive', '/locked']) {
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          isOwner: false,
          location: location,
        ),
        isNull,
        reason: location,
      );
    }
  });

  testWidgets(
    'independent worker SettingsScreen keeps worker shortcuts as a separate group',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(await _app(auth: _workerAuth));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));
      await tester.pumpAndSettle();

      expect(find.byType(SettingsScreen), findsOneWidget);
      expect(find.text('ملفي الشخصي'), findsOneWidget);
      expect(find.text('المظهر والسمات'), findsOneWidget);
      expect(find.textContaining('خلفية المحادثة'), findsOneWidget);
      expect(find.text('القفل والخصوصية'), findsOneWidget);
      expect(find.text('الأمان والجلسات'), findsOneWidget);
      expect(find.text('تسجيل الخروج'), findsOneWidget);
      expect(find.text('المحادثات'), findsNothing);
      expect(find.text('مرجع المبيعات'), findsOneWidget);
      expect(find.text('العروض'), findsOneWidget);
      expect(find.text('الأعمال'), findsOneWidget);
      expect(find.text('المعلومات والتوثيق'), findsOneWidget);

      expect(find.text('حسابي'), findsNothing);
      expect(find.text('القنوات'), findsNothing);
      expect(find.text('إعدادات المنتجات'), findsNothing);
      expect(find.text('إعدادات الذكاء الاصطناعي'), findsNothing);
      expect(find.text('تفضيلات الإشعارات'), findsNothing);
    },
  );

  testWidgets('worker Settings shortcuts open existing destinations', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    Future<void> openShortcut(String label, String destination) async {
      await tester.pumpWidget(await _app(auth: _workerAuth));
      await tester.pumpAndSettle();
      await tester.tap(find.text(label));
      await tester.pumpAndSettle();
      expect(find.text(destination), findsOneWidget);
    }

    await openShortcut('العروض', 'وجهة العروض');
    await openShortcut('الأعمال', 'وجهة الأعمال');
    await openShortcut('المعلومات والتوثيق', 'المعلومات والتوثيق');
    await openShortcut('مرجع المبيعات', 'مرجع المبيعات');
  });

  testWidgets('desktop Worker shortcuts open inside the settings side pane', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1440, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(await _app(auth: _workerAuth));
    await tester.pumpAndSettle();
    await tester.tap(find.text('العروض'));
    await tester.pumpAndSettle();

    expect(find.byType(SettingsScreen), findsOneWidget);
    expect(find.byType(WorkerScaffold), findsNothing);
    expect(find.text('عروض العمل المقدمة إليك'), findsOneWidget);
  });

  testWidgets(
    'worker profile opens the dedicated profile instead of information screen',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(await _app(auth: _workerAuth));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ملفي الشخصي'));
      await tester.pumpAndSettle();

      expect(find.text('ملف العامل المستقل'), findsOneWidget);
      expect(find.text('شاشة الحساب'), findsNothing);
      expect(find.byType(SettingsScreen), findsNothing);
    },
  );

  testWidgets(
    'worker can open the existing appearance screen and theme modes',
    (tester) async {
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(await _app(auth: _workerAuth));
      await tester.pumpAndSettle();
      await tester.tap(find.text('المظهر والسمات'));
      await tester.pumpAndSettle();

      expect(find.byType(ChatThemesScreen), findsOneWidget);
      expect(find.byType(DisplayAppearanceSettings), findsOneWidget);
      expect(find.text('فاتح'), findsOneWidget);
      expect(find.text('داكن'), findsOneWidget);
      expect(find.text('حسب النظام'), findsOneWidget);
    },
  );

  testWidgets('company settings list is unchanged', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(await _app(auth: _companyAuth));
    await tester.pumpAndSettle();

    expect(find.byType(SettingsScreen), findsOneWidget);
    expect(find.text('حسابي'), findsOneWidget);
    expect(find.text('ملفي الشخصي'), findsNothing);
    expect(find.text('القنوات'), findsOneWidget);
    expect(find.text('إعدادات المنتجات'), findsOneWidget);
    expect(find.text('إعدادات الذكاء الاصطناعي'), findsOneWidget);
    expect(find.text('تفضيلات الإشعارات'), findsOneWidget);
    await tester.tap(find.text('حسابي'));
    await tester.pumpAndSettle();
    expect(find.text('شاشة الحساب'), findsOneWidget);
    expect(find.text('حساب العامل'), findsNothing);
  });

  testWidgets('company employee rail hides archived and locked destinations', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(await _app(auth: _companyAuth));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('nav-rail-archived')), findsNothing);
    expect(find.byKey(const Key('nav-rail-locked')), findsNothing);
    expect(find.text('المؤرشفة'), findsNothing);
    expect(find.text('الدردشات المقفلة'), findsNothing);
  });

  testWidgets('workspace owner rail shows archived and locked destinations', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1280, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(await _app(auth: _ownerAuth));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('nav-rail-archived')), findsOneWidget);
    expect(find.byKey(const Key('nav-rail-locked')), findsOneWidget);
  });

  testWidgets(
    'independent worker rail hides archived and locked destinations',
    (tester) async {
      tester.view.physicalSize = const Size(1280, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);

      await tester.pumpWidget(await _app(auth: _workerAuth));
      await tester.pumpAndSettle();

      expect(find.byKey(const Key('nav-rail-archived')), findsNothing);
      expect(find.byKey(const Key('nav-rail-locked')), findsNothing);
    },
  );

  testWidgets('settings search has a Material ancestor without navigation', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1440, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    await tester.pumpWidget(
      await _app(auth: _companyAuth, withNavigation: false),
    );
    await tester.pumpAndSettle();

    expect(
      find.byWidgetPredicate(
        (widget) =>
            widget is TextField &&
            widget.decoration?.hintText == 'ابحث في الإعدادات',
      ),
      findsOneWidget,
    );
    expect(tester.takeException(), isNull);
  });
}

Future<Widget> _app({
  required AuthState auth,
  bool withNavigation = true,
}) async {
  SharedPreferences.setMockInitialValues({});
  final prefs = await SharedPreferences.getInstance();
  final secure = _MemorySecureStore();
  final prefsStore = PrefsStore(prefs);
  final api = isolatedTestApiClient(
    secureStore: secure,
    prefsStore: prefsStore,
  );
  final router = GoRouter(
    initialLocation: '/settings',
    redirect: (context, state) {
      return resolveHasimRedirect(
        bootstrapping: false,
        isAuthenticated: true,
        isIndependentWorker: auth.isIndependentWorker,
        hasWorkspace: auth.workspace != null,
        isOwner: auth.workspace?.isOwner == true,
        location: state.matchedLocation,
      );
    },
    routes: [
      GoRoute(
        path: '/settings',
        builder: (_, _) => SettingsScreen(withNavigation: withNavigation),
      ),
      GoRoute(
        path: '/conversations',
        builder: (_, _) => const Scaffold(body: Text('المحادثات')),
      ),
      GoRoute(
        path: '/profile',
        builder: (_, _) => const Scaffold(body: Text('شاشة الحساب')),
      ),
      GoRoute(
        path: workerAccountLocation,
        builder: (context, state) => Scaffold(
          body: Text(
            state.uri.queryParameters['surface'] == 'profile'
                ? 'ملف العامل المستقل'
                : 'المعلومات والتوثيق',
          ),
        ),
      ),
      GoRoute(
        path: workerSalesReferenceLocation,
        builder: (_, _) => const Scaffold(body: Text('مرجع المبيعات')),
      ),
      GoRoute(
        path: workerOffersLocation,
        builder: (_, _) => const Scaffold(body: Text('وجهة العروض')),
      ),
      GoRoute(
        path: workerEngagementsLocation,
        builder: (_, _) => const Scaffold(body: Text('وجهة الأعمال')),
      ),
      GoRoute(
        path: '/chat-themes',
        builder: (_, _) => const ChatThemesScreen(),
      ),
      GoRoute(
        path: '/login',
        builder: (_, _) => const Scaffold(body: Text('تسجيل الدخول')),
      ),
    ],
  );

  return ProviderScope(
    overrides: [
      sharedPrefsProvider.overrideWithValue(prefs),
      secureStoreProvider.overrideWithValue(secure),
      apiClientProvider.overrideWithValue(api),
      realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      storyRepositoryProvider.overrideWithValue(_FakeStoryRepository(api)),
      authControllerProvider.overrideWith(
        (ref) => _SeededAuthController(ref, auth),
      ),
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
