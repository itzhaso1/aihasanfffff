import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/widgets/hasim_adaptive_scaffold.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/presentation/conversations_screen.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/presentation/worker_chat_screen.dart';
import 'package:hasim/features/worker/presentation/worker_inbox_workspace_selector.dart';
import 'package:hasim/features/worker/presentation/worker_scaffold.dart';
import 'package:hasim/features/worker/providers/worker_inbox_workspace_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/router/auth_redirect.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => null;

  @override
  Future<void> clearToken() async {}
}

class _WorkerSecureStore extends SecureStore {
  @override
  Future<String?> readToken() async => 'worker-token';

  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<void> clearToken() async {}
}

class _IndependentWorkerAuthRepository extends AuthRepository {
  _IndependentWorkerAuthRepository(super.api);

  @override
  Future<
    ({
      UserModel user,
      WorkspaceModel? workspace,
      List<WorkspaceModel> workspaces,
      MobileParticipant participant,
    })
  >
  me() async {
    return (
      user: const UserModel(id: 7, name: 'Worker'),
      workspace: null,
      workspaces: const <WorkspaceModel>[],
      participant: const MobileParticipant(type: 'independent_worker'),
    );
  }
}

class _ScriptedChatRepository extends ConversationRepository {
  _ScriptedChatRepository(super.api, {required this.canReply});

  final bool canReply;

  @override
  Future<List<MessageModel>> cachedMessages(
    int workspaceId,
    int conversationId,
  ) async {
    return const [];
  }

  @override
  Future<ConversationModel> getById(int id, {int? workspaceId}) async {
    return ConversationModel(
      id: id,
      channel: 'whatsapp',
      status: 'open',
      canReply: canReply,
      customer: const CustomerModel(
        id: 1,
        name: 'عميل',
        phone: '050',
        email: 'a@b.c',
      ),
    );
  }

  @override
  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? workspaceId,
  }) async {
    return (items: const <MessageModel>[], nextCursor: null);
  }

  @override
  Future<void> markRead(
    int conversationId, {
    int? messageId,
    int? workspaceId,
  }) async {}
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('worker inbox workspace comes from the read engagement only', () {
    const engagements = [
      WorkerEngagement(
        id: 1,
        status: 'active',
        workspaceId: 8,
        workspaceName: 'Silent',
        capabilities: [],
      ),
      WorkerEngagement(
        id: 2,
        status: 'active',
        workspaceId: 41,
        workspaceName: 'Readable',
        capabilities: [
          WorkerCapability(key: 'inbox.messages.read', displayName: 'Read'),
        ],
      ),
    ];

    expect(workerInboxWorkspaceId(engagements), 41);
    expect(workerInboxWorkspaceId(const []), isNull);
  });

  test('worker Inbox choices are unique and require active read access', () {
    final engagements = [
      const WorkerEngagement(
        id: 1,
        status: 'active',
        workspaceId: 41,
        workspaceName: 'شركة أ',
        capabilities: [
          WorkerCapability(key: 'inbox.messages.read', displayName: 'Read'),
        ],
      ),
      const WorkerEngagement(
        id: 2,
        status: 'active',
        workspaceId: 41,
        workspaceName: 'شركة أ',
        capabilities: [
          WorkerCapability(key: 'inbox.messages.read', displayName: 'Read'),
        ],
      ),
      const WorkerEngagement(
        id: 3,
        status: 'paused',
        workspaceId: 42,
        workspaceName: 'شركة ب',
        capabilities: [
          WorkerCapability(key: 'inbox.messages.read', displayName: 'Read'),
        ],
      ),
      const WorkerEngagement(
        id: 4,
        status: 'active',
        workspaceId: 43,
        workspaceName: 'شركة ج',
        capabilities: [],
      ),
    ];

    final choices = workerInboxWorkspaces(engagements);

    expect(choices.map((workspace) => workspace.id), [41]);
    expect(choices.single.name, 'شركة أ');
  });

  testWidgets('sidebar workspace selector opens the company choices', (
    tester,
  ) async {
    final selected = <int>[];

    await tester.pumpWidget(
      MaterialApp(
        locale: const Locale('ar'),
        home: Center(
          child: Directionality(
            textDirection: TextDirection.rtl,
            child: WorkerInboxWorkspaceSelector(
              iconOnly: true,
              workspaces: const [
                WorkerInboxWorkspace(id: 41, name: 'شركة أ'),
                WorkerInboxWorkspace(id: 42, name: 'شركة ب'),
              ],
              loading: false,
              changing: false,
              error: null,
              selectedWorkspaceId: 41,
              newMessagesCount: 1,
              onSelected: (workspaceId) async => selected.add(workspaceId),
              onRetry: () async {},
            ),
          ),
        ),
      ),
    );

    expect(
      tester.getSize(find.byKey(const Key('worker-inbox-workspace-selector'))),
      const Size(60, 56),
    );

    await tester.tap(find.byKey(const Key('worker-inbox-workspace-dropdown')));
    await tester.pumpAndSettle();
    expect(find.text('شركة أ'), findsOneWidget);
    expect(find.text('شركة ب'), findsOneWidget);

    await tester.tap(find.text('شركة ب'));
    await tester.pumpAndSettle();
    expect(selected, [42]);
  });

  test(
    'company inbox redirect is unchanged and worker may open the thread',
    () {
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: false,
          hasWorkspace: true,
          location: '/conversations',
        ),
        isNull,
      );
      expect(
        resolveHasimRedirect(
          bootstrapping: false,
          isAuthenticated: true,
          isIndependentWorker: true,
          hasWorkspace: false,
          location: '/conversations/9',
        ),
        isNull,
      );
    },
  );

  test('conversation model keeps can_reply from the canonical payload', () {
    final conversation = ConversationModel.fromJson({
      'id': 3,
      'channel': 'whatsapp',
      'status': 'open',
      'can_reply': false,
    });

    expect(conversation.canReply, isFalse);
    expect(
      ConversationModel.fromJson({
        'id': 4,
        'channel': 'web',
        'status': 'open',
      }).canReply,
      isTrue,
    );
  });

  testWidgets(
    'worker chat opens the canonical inbox for the engagement workspace',
    (tester) async {
      tester.view.physicalSize = const Size(400, 800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      SharedPreferences.setMockInitialValues({});
      final preferences = await SharedPreferences.getInstance();
      final prefsStore = PrefsStore(preferences);
      await prefsStore.setWorkspaceId(41);
      final seen = <String>[];
      final dio = Dio(
        BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/'),
      );
      final api = ApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: prefsStore,
        dio: dio,
      );
      dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            seen.add('${options.path}|${options.headers['X-Workspace-Id']}');
            const data = <Map<String, dynamic>>[];
            handler.resolve(
              Response(
                requestOptions: options,
                statusCode: 200,
                data: {
                  'success': true,
                  'data': data,
                  'meta': {
                    'per_page': 20,
                    'next_cursor': null,
                    'prev_cursor': null,
                  },
                },
              ),
            );
          },
        ),
      );

      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            sharedPrefsProvider.overrideWithValue(preferences),
            secureStoreProvider.overrideWithValue(_MemorySecureStore()),
            apiClientProvider.overrideWithValue(api),
            realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
          ],
          child: MaterialApp.router(
            locale: const Locale('ar'),
            supportedLocales: AppLocalizations.supportedLocales,
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            routerConfig: GoRouter(
              initialLocation: '/worker/chat',
              redirect: (context, state) {
                return resolveHasimRedirect(
                  bootstrapping: false,
                  isAuthenticated: true,
                  isIndependentWorker: true,
                  hasWorkspace: false,
                  location: state.matchedLocation,
                );
              },
              routes: [
                GoRoute(
                  path: '/worker/chat',
                  builder: (context, state) => const WorkerChatScreen(),
                ),
                GoRoute(
                  path: '/conversations',
                  builder: (context, state) => HasimAdaptiveScaffold(
                    selectedIndex: 0,
                    onDestinationSelected: (_) {},
                    onSettingsTap: () {},
                    items: hasimBottomNavItems(
                      chatBadge: 0,
                      emailBadge: 0,
                      updatesBadge: 0,
                    ),
                    body: const ConversationsScreen(),
                  ),
                ),
              ],
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.byType(WorkerChatScreen), findsNothing);
      expect(find.byType(WorkerScaffold), findsNothing);
      expect(find.byType(HasimAdaptiveScaffold), findsOneWidget);
      expect(find.byType(ConversationsScreen), findsOneWidget);
      expect(find.text('لا توجد محادثات حاليًا'), findsOneWidget);
      expect(find.text('محادثات الارتباطات المسندة إليك'), findsNothing);
      expect(find.text('ابحث في المحادثات'), findsOneWidget);
      expect(find.text('البريد'), findsOneWidget);
      expect(find.text('التحديثات'), findsOneWidget);
      expect(find.text('الإعدادات'), findsOneWidget);
      expect(find.text('العروض'), findsNothing);
      expect(prefsStore.workspaceId, 41);
      expect(
        seen.any(
          (entry) =>
              entry.startsWith('conversations|41') ||
              entry.contains('/conversations|41'),
        ),
        isTrue,
      );
    },
  );

  testWidgets('worker can select a company and reload only its Inbox', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(400, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);

    SharedPreferences.setMockInitialValues({});
    final preferences = await SharedPreferences.getInstance();
    final secure = _WorkerSecureStore();
    final requestedWorkspaces = <String>[];
    final dio = Dio(BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/'));
    final api = ApiClient(
      secureStore: secure,
      prefsStore: PrefsStore(preferences),
      dio: dio,
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          final workspace = options.headers['X-Workspace-Id']?.toString();
          requestedWorkspaces.add('${options.uri.path}|$workspace');
          final path = options.uri.path;

          if (path.endsWith('/worker/engagements')) {
            handler.resolve(
              Response(
                requestOptions: options,
                statusCode: 200,
                data: {
                  'success': true,
                  'data': [
                    {
                      'id': 1,
                      'status': 'active',
                      'workspace': {'id': 41, 'name': 'شركة أ'},
                      'capabilities': [
                        {
                          'capability_key': 'inbox.messages.read',
                          'display_name': 'قراءة المحادثات',
                        },
                      ],
                    },
                    {
                      'id': 2,
                      'status': 'active',
                      'workspace': {'id': 42, 'name': 'شركة ب'},
                      'capabilities': [
                        {
                          'capability_key': 'inbox.messages.read',
                          'display_name': 'قراءة المحادثات',
                        },
                      ],
                    },
                  ],
                },
              ),
            );
            return;
          }

          final conversationName = workspace == '42' ? 'عميل ب' : 'عميل أ';
          final data = workspace == null
              ? const <Map<String, dynamic>>[]
              : [
                  {
                    'id': workspace == '42' ? 42 : 41,
                    'channel': 'whatsapp',
                    'status': 'open',
                    'customer': {'id': 1, 'name': conversationName},
                  },
                ];
          handler.resolve(
            Response(
              requestOptions: options,
              statusCode: 200,
              data: {
                'success': true,
                'data': data,
                'meta': {
                  'per_page': 20,
                  'next_cursor': null,
                  'prev_cursor': null,
                },
              },
            ),
          );
        },
      ),
    );
    final container = ProviderContainer(
      overrides: [
        sharedPrefsProvider.overrideWithValue(preferences),
        secureStoreProvider.overrideWithValue(secure),
        apiClientProvider.overrideWithValue(api),
        authRepositoryProvider.overrideWithValue(
          _IndependentWorkerAuthRepository(api),
        ),
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      ],
    );
    addTearDown(container.dispose);

    container.read(authControllerProvider);
    for (var i = 0; i < 40; i++) {
      if (!container.read(authControllerProvider).bootstrapping) break;
      await tester.pump(const Duration(milliseconds: 5));
    }

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: MaterialApp.router(
          locale: Locale('ar'),
          supportedLocales: AppLocalizations.supportedLocales,
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          routerConfig: GoRouter(
            initialLocation: '/conversations',
            routes: [
              GoRoute(
                path: '/conversations',
                builder: (context, state) => const ConversationsScreen(),
              ),
            ],
          ),
        ),
      ),
    );
    for (var i = 0; i < 20; i++) {
      await tester.pump(const Duration(milliseconds: 100));
      if (tester.any(find.text('عميل أ'))) {
        break;
      }
    }

    expect(
      find.byKey(const Key('worker-inbox-workspace-selector')),
      findsNothing,
    );
    expect(find.text('الشركة الحالية'), findsNothing);
    expect(find.text('عميل أ'), findsOneWidget);

    await container
        .read(workerInboxWorkspaceControllerProvider.notifier)
        .select(42);
    for (var i = 0; i < 20; i++) {
      await tester.pump(const Duration(milliseconds: 100));
      if (tester.any(find.text('عميل ب'))) {
        break;
      }
    }

    expect(PrefsStore(preferences).workspaceId, 42);
    expect(find.text('عميل ب'), findsOneWidget);
    expect(find.text('عميل أ'), findsNothing);
    expect(
      requestedWorkspaces.any(
        (request) =>
            request.contains('/conversations') && request.endsWith('|42'),
      ),
      isTrue,
    );
  });

  testWidgets('can_reply hides the existing composer', (tester) async {
    final preferences = await SharedPreferences.getInstance();
    final api = ApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(preferences),
      dio: Dio(BaseOptions(baseUrl: 'http://127.0.0.1:1/api/mobile/v1/')),
    );
    final denied = _ScriptedChatRepository(api, canReply: false);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(preferences),
          secureStoreProvider.overrideWithValue(_MemorySecureStore()),
          apiClientProvider.overrideWithValue(api),
          conversationRepositoryProvider.overrideWithValue(denied),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
        ],
        child: const MaterialApp(
          locale: Locale('ar'),
          supportedLocales: AppLocalizations.supportedLocales,
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          home: ChatScreen(conversationId: 3),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('عميل'), findsWidgets);
    expect(find.text('اكتب رسالة...'), findsNothing);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          sharedPrefsProvider.overrideWithValue(preferences),
          secureStoreProvider.overrideWithValue(_MemorySecureStore()),
          apiClientProvider.overrideWithValue(api),
          conversationRepositoryProvider.overrideWithValue(
            _ScriptedChatRepository(api, canReply: true),
          ),
          realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
        ],
        child: const MaterialApp(
          locale: Locale('ar'),
          supportedLocales: AppLocalizations.supportedLocales,
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          home: ChatScreen(conversationId: 4),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('الرسالة'), findsOneWidget);
  });
}
