import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/app.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/presentation/splash_screen.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/privacy/data/biometric_authenticator.dart';
import 'package:hasim/features/privacy/data/chat_lock_store.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';
import 'package:hasim/push/push_service.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/router/app_router.dart';
import 'package:hasim/router/auth_redirect.dart';
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

class _HungMeAuthRepository extends AuthRepository {
  _HungMeAuthRepository(super.api);

  final completer = Completer<
      ({
        UserModel user,
        WorkspaceModel? workspace,
        List<WorkspaceModel> workspaces,
        MobileParticipant participant,
      })>();

  @override
  Future<
    ({
      UserModel user,
      WorkspaceModel? workspace,
      List<WorkspaceModel> workspaces,
      MobileParticipant participant,
    })
  >
  me() => completer.future;
}

class _HangingClearInboxLocalStore extends InboxLocalStore {
  _HangingClearInboxLocalStore() : super(InboxDatabase.memory());

  final clearStarted = Completer<void>();

  @override
  Future<void> clearAll() {
    if (!clearStarted.isCompleted) {
      clearStarted.complete();
    }
    return Completer<void>().future;
  }
}

class _DelayedChatLockStore implements ChatLockStore {
  final completer = Completer<ChatLockRecord>();

  @override
  Future<ChatLockRecord> read() => completer.future;

  @override
  Future<void> write(ChatLockRecord record) async {}
}

class _NoopPushService implements PushService {
  @override
  Future<void> initializeAndRegister() async {}

  @override
  Future<void> revokeCurrent() async {}

  @override
  String get statusLabel => '';
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

  testWidgets('splash schedules a visual update for post-intro navigation', (
    tester,
  ) async {
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

    expect(SchedulerBinding.instance.hasScheduledFrame, isTrue);

    await tester.pump(const Duration(milliseconds: 2800));
    await tester.pumpAndSettle();
  });

  testWidgets(
    'splash completes full intro animation and routes to conversations when bootstrap hangs with token',
    (tester) async {
      SharedPreferences.setMockInitialValues({'workspace_id': 7});
      final prefs = await SharedPreferences.getInstance();
      final secure = _MemorySecureStore();
      await secure.saveToken('tok-123');
      final api = isolatedTestApiClient(
        secureStore: secure,
        prefsStore: PrefsStore(prefs),
      );
      final hungAuthRepo = _HungMeAuthRepository(api);

      late ProviderContainer container;
      final router = GoRouter(
        initialLocation: '/splash',
        redirect: (context, state) {
          final auth = container.read(authControllerProvider);
          return resolveHasimRedirect(
            bootstrapping: auth.bootstrapping,
            isAuthenticated: auth.isAuthenticated,
            isIndependentWorker: auth.isIndependentWorker,
            hasWorkspace: auth.workspace != null,
            isOwner: auth.workspace?.isOwner == true,
            location: state.matchedLocation,
          );
        },
        routes: [
          GoRoute(
            path: '/splash',
            builder: (_, _) => const SplashScreen(skipIntroAnimation: false),
          ),
          GoRoute(
            path: '/login',
            builder: (_, _) => const Scaffold(body: Text('login-page')),
          ),
          GoRoute(
            path: '/workspaces',
            builder: (_, _) => const Scaffold(body: Text('workspaces-page')),
          ),
          GoRoute(
            path: '/conversations',
            builder: (_, _) => const Scaffold(body: Text('conversations-page')),
          ),
        ],
      );
      addTearDown(router.dispose);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            secureStoreProvider.overrideWithValue(secure),
            sharedPrefsProvider.overrideWithValue(prefs),
            apiClientProvider.overrideWithValue(api),
            authRepositoryProvider.overrideWithValue(hungAuthRepo),
            realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
            pushServiceProvider.overrideWithValue(_NoopPushService()),
          ],
          child: Consumer(
            builder: (context, ref, _) {
              container = ProviderScope.containerOf(context);
              ref.watch(authControllerProvider);
              return MaterialApp.router(routerConfig: router);
            },
          ),
        ),
      );

      expect(find.byType(SplashScreen), findsOneWidget);

      // Advance past the 2600ms animation + 2800ms bootstrap guard.
      for (var i = 0; i < 30; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      await tester.pumpAndSettle();

      expect(find.text('conversations-page'), findsOneWidget);
      expect(find.byType(SplashScreen), findsNothing);
      expect(find.text('login-page'), findsNothing);
      expect(find.text('workspaces-page'), findsNothing);

      // Flush the 10s timeout timer created inside AuthController._refreshAccount.
      await tester.pump(const Duration(seconds: 10));
    },
  );

  test(
    'abortBootstrapAsGuest finishes bootstrapping immediately when clearAll hangs',
    () async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final secure = _MemorySecureStore();
      final hangingStore = _HangingClearInboxLocalStore();
      final container = ProviderContainer(
        overrides: [
          secureStoreProvider.overrideWithValue(secure),
          sharedPrefsProvider.overrideWithValue(prefs),
          inboxLocalStoreProvider.overrideWithValue(hangingStore),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
          pushServiceProvider.overrideWithValue(_NoopPushService()),
          apiClientProvider.overrideWithValue(
            isolatedTestApiClient(
              secureStore: secure,
              prefsStore: PrefsStore(prefs),
            ),
          ),
        ],
      );
      addTearDown(container.dispose);

      final notifier = container.read(authControllerProvider.notifier);
      await notifier.abortBootstrapAsGuest().timeout(
        const Duration(milliseconds: 500),
      );
      expect(container.read(authControllerProvider).bootstrapping, isFalse);
      expect(container.read(authControllerProvider).isAuthenticated, isFalse);
    },
  );

  testWidgets(
    'AppLockHost preserves SplashScreen instance while lock store finishes loading',
    (tester) async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final delayedSecure = _DelayedSecureStore();
      final delayedLockStore = _DelayedChatLockStore();
      final api = isolatedTestApiClient(
        secureStore: delayedSecure,
        prefsStore: PrefsStore(prefs),
      );
      final hungAuthRepo = _HungMeAuthRepository(api);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            secureStoreProvider.overrideWithValue(delayedSecure),
            sharedPrefsProvider.overrideWithValue(prefs),
            chatLockStoreProvider.overrideWithValue(delayedLockStore),
            biometricAuthenticatorProvider.overrideWithValue(
              FakeBiometricAuthenticator(),
            ),
            realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
            pushServiceProvider.overrideWithValue(_NoopPushService()),
            authRepositoryProvider.overrideWithValue(hungAuthRepo),
            apiClientProvider.overrideWithValue(api),
          ],
          child: const HasimApp(),
        ),
      );

      final splashFinder = find.byType(SplashScreen);
      expect(splashFinder, findsOneWidget);
      final splashState = tester.state(splashFinder);

      // Token resolves while ChatLockStore is still loading: SplashScreen must stay mounted.
      delayedSecure.token.complete('tok-abc');
      await tester.pump();
      expect(splashFinder, findsOneWidget);
      expect(tester.state(splashFinder), same(splashState));

      delayedLockStore.completer.complete(ChatLockRecord.empty);
      await tester.pump();
      expect(splashFinder, findsOneWidget);
      expect(tester.state(splashFinder), same(splashState));

      await tester.pump(const Duration(milliseconds: 3200));
      await tester.pumpAndSettle();
      // Flush the 10s timeout timer created inside AuthController._refreshAccount.
      await tester.pump(const Duration(seconds: 10));
    },
  );

  testWidgets(
    'splash completes full intro animation and routes guest to login when bootstrap finishes early',
    (tester) async {
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final secure = _MemorySecureStore();
      final api = isolatedTestApiClient(
        secureStore: secure,
        prefsStore: PrefsStore(prefs),
      );

      late ProviderContainer container;
      final router = GoRouter(
        initialLocation: '/splash',
        redirect: (context, state) {
          final auth = container.read(authControllerProvider);
          return resolveHasimRedirect(
            bootstrapping: auth.bootstrapping,
            isAuthenticated: auth.isAuthenticated,
            isIndependentWorker: auth.isIndependentWorker,
            hasWorkspace: auth.workspace != null,
            isOwner: auth.workspace?.isOwner == true,
            location: state.matchedLocation,
          );
        },
        routes: [
          GoRoute(
            path: '/splash',
            builder: (_, _) => const SplashScreen(skipIntroAnimation: false),
          ),
          GoRoute(
            path: '/login',
            builder: (_, _) => const Scaffold(body: Text('login-page')),
          ),
        ],
      );
      addTearDown(router.dispose);

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            secureStoreProvider.overrideWithValue(secure),
            sharedPrefsProvider.overrideWithValue(prefs),
            apiClientProvider.overrideWithValue(api),
            realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
            pushServiceProvider.overrideWithValue(_NoopPushService()),
          ],
          child: Consumer(
            builder: (context, ref, _) {
              container = ProviderScope.containerOf(context);
              ref.watch(authControllerProvider);
              return MaterialApp.router(routerConfig: router);
            },
          ),
        ),
      );

      expect(find.byType(SplashScreen), findsOneWidget);

      // Advance past the 2600ms intro + 100ms settle delay.
      for (var i = 0; i < 28; i++) {
        await tester.pump(const Duration(milliseconds: 100));
      }
      await tester.pumpAndSettle();

      expect(find.text('login-page'), findsOneWidget);
      expect(find.byType(SplashScreen), findsNothing);
    },
  );
}
