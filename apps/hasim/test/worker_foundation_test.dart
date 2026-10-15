import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/conversations_screen.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_chat_screen.dart';
import 'package:hasim/features/worker/presentation/worker_profile_screen.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/providers/worker_account_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/router/auth_redirect.dart';
import 'package:hasim/router/worker_nav.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  _MemorySecureStore(this._token);
  String? _token;

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> clearToken() async => _token = null;
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

class _SeededAuthController extends AuthController {
  _SeededAuthController(super.ref, this.seed);

  final AuthState seed;

  @override
  Future<void> bootstrap() async {
    state = seed;
  }
}

class _NoopWorkerAccountController extends WorkerAccountController {
  _NoopWorkerAccountController(super.ref) {
    _settle();
  }

  @override
  Future<void> load({bool force = false}) async => _settle();

  @override
  Future<void> loadProfile({bool force = false}) async => _settle();

  @override
  Future<void> loadSkillCatalog({bool force = false}) async => _settle();

  @override
  Future<void> loadLanguageCatalog({bool force = false}) async => _settle();

  @override
  Future<void> loadIdentity({bool force = false}) async => _settle();

  @override
  Future<void> loadDocuments({bool force = false}) async => _settle();

  void _settle() {
    state = const WorkerAccountState(
      profileLoading: false,
      skillsLoading: false,
      languagesLoading: false,
      identityLoading: false,
      documentsLoading: false,
    );
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  setUpAll(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('participant type comes from the backend field', () {
    final worker = MobileParticipant.fromJson({
      'type': 'independent_worker',
      'worker_profile_id': 14,
    });
    final company = MobileParticipant.fromJson({
      'type': 'company_staff',
      'worker_profile_id': null,
    });

    expect(worker.isIndependentWorker, isTrue);
    expect(worker.workerProfileId, 14);
    expect(company.isIndependentWorker, isFalse);
    expect(MobileParticipant.fromJson(null).type, 'company_staff');
  });

  test(
    'worker login without a workspace resolves to the conversations landing page',
    () {
      final result = LoginResult.fromJson({
        'token': 'worker-token',
        'user': {'id': 3, 'name': 'Worker'},
        'participant': {'type': 'independent_worker', 'worker_profile_id': 9},
        'workspace': null,
        'workspaces': [],
      });
      final state = AuthState(
        bootstrapping: false,
        token: result.token,
        user: result.user,
        participant: result.participant,
      );

      expect(result.workspace, isNull);
      expect(state.isIndependentWorker, isTrue);
      expect(state.authenticatedHome, '/conversations');
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: '/login',
        ),
        '/conversations',
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: '/workspaces',
        ),
        '/conversations',
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: workerChatLocation,
        ),
        '/conversations',
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: workerOffersLocation,
        ),
        isNull,
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: '/conversations/4',
        ),
        isNull,
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: '/chat-themes',
        ),
        isNull,
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: '/settings',
        ),
        isNull,
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: '/privacy-lock',
        ),
        isNull,
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: '$workerEngagementsLocation/7',
        ),
        isNull,
      );
    },
  );

  test('company login routing is unchanged', () {
    expect(
      resolveHasimRedirect(
        bootstrapping: false,
        isAuthenticated: true,
        isIndependentWorker: false,
        hasWorkspace: true,
        location: '/login',
      ),
      '/conversations',
    );
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
        location: '/conversations',
      ),
      '/workspaces',
    );
    expect(
      resolveHasimRedirect(
        bootstrapping: false,
        isAuthenticated: false,
        isIndependentWorker: false,
        hasWorkspace: false,
        location: '/login',
      ),
      isNull,
    );
  });

  test(
    'logout clears the worker home and a company user cannot stay on the worker shell',
    () {
      final loggedOut = const AuthState(bootstrapping: false);
      expect(loggedOut.isAuthenticated, isFalse);
      expect(loggedOut.authenticatedHome, '/workspaces');
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: false,
          isIndependentWorker: false,
          hasWorkspace: false,
          location: workerAccountLocation,
        ),
        '/login',
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: false,
          hasWorkspace: true,
          location: workerChatLocation,
        ),
        '/conversations',
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: false,
          hasWorkspace: true,
          location: workerOffersLocation,
        ),
        '/conversations',
      );
    },
  );

  testWidgets(
    'worker shell stays on other tabs and the chat entry is not an inbox shell',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: WorkerScaffold(
            selectedIndex: WorkerNav.offersIndex,
            body: const SizedBox.shrink(),
          ),
        ),
      );
      expect(find.text('المحادثات'), findsOneWidget);
      expect(find.text('العروض'), findsOneWidget);
      expect(find.text('الأعمال'), findsOneWidget);
      expect(find.text('الحساب'), findsOneWidget);

      await tester.pumpWidget(const MaterialApp(home: WorkerChatScreen()));
      expect(find.byType(WorkerScaffold), findsNothing);
      expect(find.byType(ConversationsScreen), findsNothing);
      expect(find.text('البريد'), findsNothing);
      expect(find.text('التحديثات'), findsNothing);
      expect(find.text('المالية'), findsNothing);
      expect(find.text('الكاشير'), findsNothing);
      expect(find.text('نقاط البيع'), findsNothing);
    },
  );

  testWidgets('worker child shells can hide the bottom navigation', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: WorkerScaffold(
          selectedIndex: WorkerNav.accountIndex,
          body: const SizedBox.shrink(),
          showBottomNavigation: false,
        ),
      ),
    );

    expect(find.text('المحادثات'), findsNothing);
    expect(find.text('العروض'), findsNothing);
    expect(find.text('الأعمال'), findsNothing);
    expect(find.text('الحساب'), findsNothing);
  });

  testWidgets(
    'worker profile surface hides navigation and returns to settings',
    (tester) async {
      final router = GoRouter(
        initialLocation: '/settings',
        routes: [
          GoRoute(
            path: '/settings',
            builder: (context, state) => Scaffold(
              body: FilledButton(
                key: const Key('open-worker-profile'),
                onPressed: () =>
                    context.push('$workerAccountLocation?surface=profile'),
                child: const Text('فتح الملف الشخصي'),
              ),
            ),
          ),
          GoRoute(
            path: workerAccountLocation,
            builder: (context, state) => const WorkerProfileScreen(),
          ),
        ],
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            workerAccountControllerProvider.overrideWith(
              _NoopWorkerAccountController.new,
            ),
          ],
          child: MaterialApp.router(routerConfig: router),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const Key('open-worker-profile')));
      await tester.pumpAndSettle();

      expect(find.text('ملفي الشخصي'), findsWidgets);
      expect(find.byKey(const Key('page-back')), findsOneWidget);
      expect(find.text('المحادثات'), findsNothing);
      expect(find.text('العروض'), findsNothing);
      expect(find.text('الأعمال'), findsNothing);
      expect(find.text('الحساب'), findsNothing);

      await tester.tap(find.byKey(const Key('page-back')));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('open-worker-profile')), findsOneWidget);
    },
  );

  testWidgets('worker chat navigation returns to the Worker shell with back', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(400, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final prefs = PrefsStore(preferences);
    final secure = _MemorySecureStore('token');
    final api = ApiClient(
      secureStore: secure,
      prefsStore: prefs,
      dio: Dio(BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/')),
    );
    final router = GoRouter(
      initialLocation: workerEngagementsLocation,
      routes: [
        GoRoute(
          path: workerEngagementsLocation,
          builder: (context, state) => const WorkerScaffold(
            selectedIndex: WorkerNav.engagementsIndex,
            body: Text('worker-business'),
          ),
        ),
        GoRoute(
          path: '/conversations',
          builder: (context, state) => const ConversationsScreen(),
        ),
      ],
    );

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(preferences),
          secureStoreProvider.overrideWithValue(secure),
          prefsStoreProvider.overrideWithValue(prefs),
          apiClientProvider.overrideWithValue(api),
          workerRepositoryProvider.overrideWithValue(
            _EmptyWorkerRepository(api),
          ),
          conversationRepositoryProvider.overrideWithValue(
            _EmptyConversationRepository(api),
          ),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
          authControllerProvider.overrideWith(
            (ref) => _SeededAuthController(ref, _workerAuth),
          ),
        ],
        child: MaterialApp.router(
          locale: const Locale('ar'),
          supportedLocales: AppLocalizations.supportedLocales,
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          routerConfig: router,
        ),
      ),
    );
    await tester.tap(find.text('المحادثات'));
    await tester.pumpAndSettle();
    expect(find.text('لا توجد محادثات حاليًا'), findsOneWidget);
    expect(tester.takeException(), isNull);

    final handled = await tester.binding.handlePopRoute();
    expect(handled, isTrue);
    await tester.pumpAndSettle();
    expect(find.text('worker-business'), findsOneWidget);
    expect(find.byType(ConversationsScreen), findsNothing);
    expect(tester.takeException(), isNull);
  });

  test(
    'worker repository reads engagements and posts accept or reject',
    () async {
      final prefs = PrefsStore(await SharedPreferences.getInstance());
      final calls = <String>[];
      final dio = Dio(
        BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/'),
      );
      dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            calls.add('${options.method} ${options.path}');
            final data =
                options.path.endsWith('/accept') ||
                    options.path.endsWith('/reject') ||
                    options.path.endsWith('/7')
                ? {
                    'id': 7,
                    'status': options.path.endsWith('/reject')
                        ? 'rejected'
                        : 'accepted',
                    'workspace': {'id': 4, 'name': 'Company A'},
                    'capabilities': [
                      {
                        'capability_key': 'inbox.messages.read',
                        'display_name': 'Read inbox messages',
                      },
                    ],
                  }
                : [
                    {
                      'id': 7,
                      'status': 'offered',
                      'workspace': {'id': 4, 'name': 'Company A'},
                      'capabilities': [
                        {
                          'capability_key': 'inbox.messages.read',
                          'display_name': 'Read inbox messages',
                        },
                      ],
                    },
                  ];
            handler.resolve(
              Response(
                requestOptions: options,
                data: {'success': true, 'data': data},
              ),
            );
          },
        ),
      );
      final repo = WorkerRepository(
        ApiClient(
          secureStore: _MemorySecureStore('token'),
          prefsStore: prefs,
          dio: dio,
        ),
      );

      final list = await repo.engagements();
      final accepted = await repo.acceptOffer(7);
      final rejected = await repo.rejectOffer(7);

      expect(list.single.workspaceName, 'Company A');
      expect(
        list.single.capabilities.single.displayName,
        'Read inbox messages',
      );
      expect(accepted.status, 'accepted');
      expect(rejected.status, 'rejected');
      expect(calls, [
        'GET worker/engagements',
        'POST worker/engagements/7/accept',
        'POST worker/engagements/7/reject',
      ]);
    },
  );
}

class _EmptyConversationRepository extends ConversationRepository {
  _EmptyConversationRepository(super.api);

  @override
  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    String? handoff,
    String? cursor,
    int? workspaceId,
  }) async {
    return (items: const <ConversationModel>[], nextCursor: null);
  }
}

class _EmptyWorkerRepository extends WorkerRepository {
  _EmptyWorkerRepository(super.api);

  @override
  Future<List<WorkerEngagement>> engagements() async => const [];
}
