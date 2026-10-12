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
import 'package:hasim/core/widgets/hasim_shell_header.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/router/app_shell.dart';
import 'package:hasim/router/auth_redirect.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => null;

  @override
  Future<void> clearToken() async {}
}

class _SeededAuthController extends AuthController {
  _SeededAuthController(super.ref, this.seed);

  final AuthState seed;

  @override
  Future<void> bootstrap() async {
    state = seed;
  }
}

class _EmptyConversations extends ConversationRepository {
  _EmptyConversations(super.api);

  @override
  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    String? cursor,
    int? workspaceId,
  }) async {
    return (items: const <ConversationModel>[], nextCursor: null);
  }
}

class _EmptyEngagements extends WorkerRepository {
  _EmptyEngagements(super.api);

  @override
  Future<List<WorkerEngagement>> engagements() async => const [];
}

const _worker = AuthState(
  bootstrapping: false,
  token: 'worker-token',
  user: UserModel(id: 3, name: 'عامل'),
  participant: MobileParticipant(type: 'independent_worker'),
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  test('phone conversation thread hides the bottom navigation', () {
    expect(hidesPhoneBottomNav('/conversations'), isFalse);
    expect(hidesPhoneBottomNav('/conversations/7'), isTrue);
    expect(hidesPhoneBottomNav('/customers/12'), isTrue);
    expect(hidesPhoneBottomNav('/search'), isTrue);
    expect(hidesPhoneBottomNav('/email'), isFalse);
    expect(hidesPhoneBottomNav('/settings'), isFalse);
  });

  test('worker stays on the shared inbox for company-only routes', () {
    const worker = (
      bootstrapping: false,
      isAuthenticated: true,
      isIndependentWorker: true,
      hasWorkspace: false,
    );
    for (final location in [
      '/channels',
      '/profile',
      '/activity',
      '/contacts',
      '/plans',
      '/search',
    ]) {
      expect(
        resolveHasimRedirect(
          bootstrapping: worker.bootstrapping,
          isAuthenticated: worker.isAuthenticated,
          isIndependentWorker: worker.isIndependentWorker,
          hasWorkspace: worker.hasWorkspace,
          location: location,
        ),
        '/conversations',
        reason: location,
      );
    }
    expect(
      resolveHasimRedirect(
        bootstrapping: worker.bootstrapping,
        isAuthenticated: worker.isAuthenticated,
        isIndependentWorker: worker.isIndependentWorker,
        hasWorkspace: worker.hasWorkspace,
        location: '/email',
      ),
      isNull,
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: worker.bootstrapping,
        isAuthenticated: worker.isAuthenticated,
        isIndependentWorker: worker.isIndependentWorker,
        hasWorkspace: worker.hasWorkspace,
        location: '/updates',
      ),
      isNull,
    );
  });

  testWidgets('worker company selector lives in the sidebar on wide screens', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1200, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final secure = _MemorySecureStore();
    final api = isolatedTestApiClient(
      secureStore: secure,
      prefsStore: PrefsStore(prefs),
    );

    final router = GoRouter(
      initialLocation: '/conversations',
      redirect: (context, state) => resolveHasimRedirect(
        bootstrapping: false,
        isAuthenticated: true,
        isIndependentWorker: true,
        hasWorkspace: false,
        location: state.matchedLocation,
      ),
      routes: [
        ShellRoute(
          builder: (context, state, child) => AppRootShell(child: child),
          routes: [
            GoRoute(
              path: '/conversations',
              builder: (_, _) => const Text('inbox-body'),
            ),
            GoRoute(
              path: '/email',
              builder: (_, _) => const Text('email-body'),
            ),
            GoRoute(
              path: '/updates',
              builder: (_, _) => const Text('updates-body'),
            ),
            GoRoute(
              path: '/settings',
              builder: (_, _) => const Text('settings-body'),
            ),
          ],
        ),
      ],
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          secureStoreProvider.overrideWithValue(secure),
          apiClientProvider.overrideWithValue(api),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
          conversationRepositoryProvider.overrideWithValue(
            _EmptyConversations(api),
          ),
          workerRepositoryProvider.overrideWithValue(_EmptyEngagements(api)),
          authControllerProvider.overrideWith(
            (ref) => _SeededAuthController(ref, _worker),
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
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('inbox-body'), findsOneWidget);
    expect(
      find.byKey(const Key('worker-inbox-workspace-selector')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const Key('nav-rail-email')));
    await tester.pumpAndSettle();
    expect(find.text('email-body'), findsOneWidget);
    expect(find.text('inbox-body'), findsNothing);

    await tester.tap(find.byKey(const Key('nav-rail-updates')));
    await tester.pumpAndSettle();
    expect(find.text('updates-body'), findsOneWidget);
    expect(find.text('inbox-body'), findsNothing);
  });

  testWidgets('worker more menu hides company-only destinations', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final secure = _MemorySecureStore();
    final api = isolatedTestApiClient(
      secureStore: secure,
      prefsStore: PrefsStore(prefs),
    );
    final router = GoRouter(
      routes: [
        GoRoute(
          path: '/',
          builder: (_, _) =>
              const Scaffold(appBar: HasimShellHeader(), body: Text('inbox')),
        ),
      ],
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(prefs),
          secureStoreProvider.overrideWithValue(secure),
          apiClientProvider.overrideWithValue(api),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
          authControllerProvider.overrideWith(
            (ref) => _SeededAuthController(ref, _worker),
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
      ),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const Key('hasim-more-btn')));
    await tester.pumpAndSettle();

    expect(find.text('الإعدادات'), findsOneWidget);
    expect(find.text('الأمان والجلسات'), findsOneWidget);
    expect(find.text('تسجيل الخروج'), findsOneWidget);
    expect(find.text('جهات الاتصال'), findsNothing);
    expect(find.text('القنوات'), findsNothing);
    expect(find.text('الباقة والاستخدام'), findsNothing);
    expect(find.text('تبديل مساحة العمل'), findsNothing);
    expect(find.text('الإشعارات'), findsNothing);
  });
}
