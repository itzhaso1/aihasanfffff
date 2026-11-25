import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/outbox_worker.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/hybrid_realtime_service.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemSecureStore extends SecureStore {
  String? _token = 'worker-sanctum-token';

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> clearToken() async => _token = null;
}

class _WorkerFakeAuthRepository extends AuthRepository {
  _WorkerFakeAuthRepository(super.api, {this.userId = 21, this.userName = 'سلمان المستقل'});

  final int userId;
  final String userName;

  @override
  Future<({
    MobileParticipant participant,
    UserModel user,
    List<WorkspaceModel> workspaces,
    WorkspaceModel? workspace,
  })>
  me() async {
    return (
      participant: const MobileParticipant(
        type: 'independent_worker',
        workerProfileId: 77,
      ),
      user: UserModel(id: userId, name: userName, email: 'worker@hasem.sa'),
      workspaces: const <WorkspaceModel>[],
      workspace: null,
    );
  }

  @override
  Future<LoginResult> login({
    required String emailOrPhone,
    required String password,
    int? workspaceId,
    String deviceName = 'حاسم Flutter',
    String deviceType = 'mobile',
  }) async {
    return LoginResult(
      token: 'worker-sanctum-token',
      user: UserModel(id: userId, name: userName, email: 'worker@hasem.sa'),
      workspace: null,
      workspaces: const [],
      participant: const MobileParticipant(type: 'independent_worker'),
    );
  }

  @override
  Future<void> logout() async {}
}

class _FakeConnectivity implements Connectivity {
  final _ctrl = StreamController<List<ConnectivityResult>>.broadcast();

  @override
  Stream<List<ConnectivityResult>> get onConnectivityChanged => _ctrl.stream;

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => const [ConnectivityResult.wifi];

  void dispose() => _ctrl.close();
}

/// Real loopback HTTP + Pusher-v7 WebSocket server exercising worker handoff,
/// version conflict guards (409), and instant WebSocket responder updates.
class _WorkerRealtimeServer {
  late final HttpServer server;
  int get port => server.port;

  final List<WebSocket> sockets = <WebSocket>[];
  final Map<WebSocket, Set<String>> socketSubscriptions = <WebSocket, Set<String>>{};
  final List<Map<String, dynamic>> takeoverRequests = <Map<String, dynamic>>[];
  final List<Map<String, dynamic>> resumeAiRequests = <Map<String, dynamic>>[];

  bool simulateConflictOnTakeover = false;
  bool returnStaleConvOnRefresh = false;

  late Map<String, dynamic> conversation = {
    'id': 1,
    'workspace_id': 1,
    'channel': 'whatsapp',
    'status': 'open',
    'ai_enabled': true,
    'ai_replying': true,
    'handoff_status': 'none',
    'handoff_version': 1,
    'unread_count': 0,
    'can_reply': true,
    'assigned_user_id': null,
    'assigned_user_name': null,
    'assigned_worker_engagement_id': null,
    'responder': {
      'status': 'ai_active',
      'type': 'ai',
      'name': 'مشعل AI',
      'label': 'مشغل AI',
      'is_ai_generating': false,
    },
    'customer': {'id': 10, 'name': 'أحمد العميل', 'phone': '966500000001'},
    'last_message_at': '2026-11-25T10:00:00Z',
    'updated_at': '2026-11-25T10:00:00Z',
    'last_message': {
      'id': 101,
      'conversation_id': 1,
      'direction': 'inbound',
      'message_type': 'text',
      'content': 'أهلاً، أحتاج مساعدة',
      'created_at': '2026-11-25T10:00:00Z',
    },
  };

  final List<Map<String, dynamic>> messages = [
    {
      'id': 101,
      'conversation_id': 1,
      'direction': 'inbound',
      'message_type': 'text',
      'content': 'أهلاً، أحتاج مساعدة',
      'created_at': '2026-11-25T10:00:00Z',
    },
  ];

  Future<void> start() async {
    server = await HttpServer.bind(InternetAddress.loopbackIPv4, 0);
    server.listen(_handle);
  }

  Future<void> close() async {
    for (final s in [...sockets]) {
      try {
        await s.close();
      } catch (_) {}
    }
    sockets.clear();
    socketSubscriptions.clear();
    await server.close(force: true);
  }

  void broadcast(String channel, String event, Map<String, dynamic> data) {
    final payload = jsonEncode({
      'event': event,
      'channel': channel,
      'data': {
        ...data,
        'broadcast_at': DateTime.now().toUtc().toIso8601String(),
      },
    });
    for (final s in [...sockets]) {
      if (socketSubscriptions[s]?.contains(channel) == true) {
        s.add(payload);
      }
    }
  }

  void _onWsFrame(WebSocket ws, dynamic raw) {
    final map = jsonDecode(raw as String) as Map<String, dynamic>;
    final event = map['event']?.toString();
    final data = map['data'] is Map ? Map<String, dynamic>.from(map['data']) : <String, dynamic>{};
    if (event == 'pusher:ping') {
      ws.add(jsonEncode({'event': 'pusher:pong', 'data': {}}));
    } else if (event == 'pusher:subscribe') {
      final ch = data['channel']?.toString() ?? '';
      socketSubscriptions.putIfAbsent(ws, () => <String>{}).add(ch);
      ws.add(jsonEncode({'event': 'pusher_internal:subscription_succeeded', 'channel': ch, 'data': '{}'}));
    } else if (event == 'pusher:unsubscribe') {
      socketSubscriptions[ws]?.remove(data['channel']?.toString() ?? '');
    }
  }

  Future<void> _handle(HttpRequest req) async {
    final path = req.uri.path;
    if (path.startsWith('/app/')) {
      final ws = await WebSocketTransformer.upgrade(req);
      sockets.add(ws);
      socketSubscriptions[ws] = <String>{};
      ws.add(jsonEncode({
        'event': 'pusher:connection_established',
        'data': jsonEncode({'socket_id': '901.${sockets.length}', 'activity_timeout': 60}),
      }));
      ws.listen(
        (raw) => _onWsFrame(ws, raw),
        onDone: () {
          sockets.remove(ws);
          socketSubscriptions.remove(ws);
        },
        onError: (_) {
          sockets.remove(ws);
          socketSubscriptions.remove(ws);
        },
      );
      return;
    }

    final bodyStr = await utf8.decoder.bind(req).join();
    final bodyMap = bodyStr.isNotEmpty ? (jsonDecode(bodyStr) as Map<String, dynamic>) : <String, dynamic>{};

    if (path == '/api/mobile/v1/realtime/config') {
      return _json(req, 200, {
        'success': true,
        'data': {
          'enabled': true,
          'driver': 'reverb',
          'key': 'worker-test-key',
          'host': '127.0.0.1',
          'port': port,
          'scheme': 'http',
          'use_tls': false,
          'auth_endpoint': 'http://127.0.0.1:$port/api/mobile/v1/broadcasting/auth',
          'channel_prefix': 'private-workspace.',
          'ping_interval': 25,
        },
      });
    }

    if (path == '/api/mobile/v1/broadcasting/auth') {
      return _json(req, 200, {'auth': 'worker-test-key:sig'});
    }

    if (path == '/api/mobile/v1/conversations') {
      return _json(req, 200, {
        'success': true,
        'data': [conversation],
        'meta': {'next_cursor': null},
      });
    }

    if (path == '/api/mobile/v1/conversations/1') {
      if (returnStaleConvOnRefresh) {
        return _json(req, 200, {
          'success': true,
          'data': {
            ...conversation,
            'ai_enabled': true,
            'ai_replying': true,
            'handoff_status': 'none',
            'handoff_version': 1,
            'assigned_user_id': null,
            'assigned_user_name': null,
            'responder': {
              'status': 'ai_active',
              'type': 'ai',
              'name': 'مشعل AI',
              'label': 'مشغل AI',
              'is_ai_generating': false,
            },
          },
        });
      }
      return _json(req, 200, {'success': true, 'data': conversation});
    }

    if (path == '/api/mobile/v1/conversations/1/messages') {
      return _json(req, 200, {
        'success': true,
        'data': messages,
        'meta': {'next_cursor': null},
      });
    }

    if (path == '/api/mobile/v1/conversations/1/takeover' && req.method == 'POST') {
      takeoverRequests.add(bodyMap);
      if (simulateConflictOnTakeover) {
        return _json(req, 409, {
          'success': false,
          'message': 'تم استلام المحادثة للتو من موظف آخر.',
          'data': {'handoff_version': 99},
        });
      }
      final currentVer = (conversation['handoff_version'] as int?) ?? 1;
      final expected = bodyMap['expected_handoff_version'] as int?;
      if (expected != null && expected != currentVer) {
        return _json(req, 409, {
          'success': false,
          'message': 'تغيرت حالة المحادثة على جهاز آخر.',
          'data': {'handoff_version': currentVer},
        });
      }
      conversation = {
        ...conversation,
        'ai_enabled': false,
        'ai_replying': false,
        'handoff_status': 'active',
        'handoff_version': currentVer + 1,
        'assigned_user_id': 21,
        'assigned_user_name': 'سلمان المستقل',
        'assigned_worker_engagement_id': 501,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
        'responder': {
          'status': 'human_active',
          'type': 'human',
          'name': 'سلمان المستقل',
          'label': 'الرد بواسطة الموظف',
          'user_id': 21,
          'is_ai_generating': false,
        },
      };
      broadcast('private-workspace.1.conversation.1', 'conversation.updated', conversation);
      broadcast('private-workspace.1.conversations', 'conversation.updated', conversation);
      return _json(req, 200, {'success': true, 'data': conversation});
    }

    if (path == '/api/mobile/v1/conversations/1/resume-ai' && req.method == 'POST') {
      resumeAiRequests.add(bodyMap);
      final currentVer = (conversation['handoff_version'] as int?) ?? 1;
      final expected = bodyMap['expected_handoff_version'] as int?;
      if (expected != null && expected != currentVer) {
        return _json(req, 409, {
          'success': false,
          'message': 'تغيرت حالة المحادثة على جهاز آخر.',
          'data': {'handoff_version': currentVer},
        });
      }
      conversation = {
        ...conversation,
        'ai_enabled': true,
        'ai_replying': true,
        'handoff_status': 'resolved',
        'handoff_version': currentVer + 1,
        'assigned_user_id': null,
        'assigned_user_name': null,
        'assigned_worker_engagement_id': null,
        'updated_at': DateTime.now().toUtc().toIso8601String(),
        'responder': {
          'status': 'ai_active',
          'type': 'ai',
          'name': 'مشعل AI',
          'label': 'مشغل AI',
          'is_ai_generating': false,
        },
      };
      broadcast('private-workspace.1.conversation.1', 'conversation.updated', conversation);
      broadcast('private-workspace.1.conversations', 'conversation.updated', conversation);
      return _json(req, 200, {'success': true, 'data': conversation});
    }

    if (path.endsWith('/read') || path.contains('/presence/') || path.endsWith('/typing')) {
      return _json(req, 200, {'success': true, 'data': {'unread_count': 0}});
    }

    return _json(req, 404, {'success': false});
  }

  void _json(HttpRequest req, int status, Map<String, dynamic> payload) {
    req.response.statusCode = status;
    req.response.headers.contentType = ContentType.json;
    req.response.write(jsonEncode(payload));
    req.response.close();
  }
}

Future<
  ({
    ProviderContainer container,
    _WorkerRealtimeServer server,
    InboxLocalStore store,
    OutboxWorker outbox,
    _FakeConnectivity connectivity,
  })
>
_bootWorkerHarness({int userId = 21, String userName = 'سلمان المستقل'}) async {
  final server = _WorkerRealtimeServer();
  await server.start();

  SharedPreferences.setMockInitialValues({
    'workspace_id': 1,
    'api_base_override': 'http://127.0.0.1:${server.port}',
  });
  final rawPrefs = await SharedPreferences.getInstance();
  final prefs = PrefsStore(rawPrefs);
  final session = InboxSession()..beginWorkspace(1);
  final db = InboxDatabase.memory();
  await db.ensureReady();
  final store = InboxLocalStore(db);
  final connectivity = _FakeConnectivity();
  final secure = _MemSecureStore();
  final api = ApiClient(secureStore: secure, prefsStore: prefs);
  final repo = ConversationRepository(api, local: store);
  final outbox = OutboxWorker(
    store: store,
    repository: repo,
    session: session,
    connectivity: connectivity,
  );

  final container = ProviderContainer(
    overrides: [
      sharedPrefsProvider.overrideWithValue(rawPrefs),
      secureStoreProvider.overrideWithValue(secure),
      inboxSessionProvider.overrideWithValue(session),
      inboxDatabaseProvider.overrideWithValue(db),
      inboxLocalStoreProvider.overrideWithValue(store),
      apiClientProvider.overrideWithValue(api),
      authRepositoryProvider.overrideWithValue(
        _WorkerFakeAuthRepository(api, userId: userId, userName: userName),
      ),
      conversationRepositoryProvider.overrideWithValue(repo),
      outboxWorkerProvider.overrideWithValue(outbox),
      realtimeServiceProvider.overrideWith((ref) {
        final service = HybridRealtimeService(
          PollingRealtimeService(repo, workspaceIdOf: () => session.workspaceId),
          apiClient: api,
          workspaceIdOf: () => session.workspaceId,
          repository: repo,
          connectivity: connectivity,
          onLocalInboxChange: () => bumpInboxLocalRevision(ref),
        );
        ref.listen<int?>(activeWorkspaceIdProvider, (_, next) => service.updateWorkspace(next));
        ref.onDispose(service.dispose);
        return service;
      }),
    ],
  );

  final authCtrl = container.read(authControllerProvider.notifier);
  for (var i = 0; i < 40; i++) {
    if (!container.read(authControllerProvider).bootstrapping) break;
    await Future<void>.delayed(const Duration(milliseconds: 10));
  }
  if (!container.read(authControllerProvider).isAuthenticated) {
    await authCtrl.login('worker@hasem.sa', 'secret');
  }

  return (
    container: container,
    server: server,
    store: store,
    outbox: outbox,
    connectivity: connectivity,
  );
}

Future<void> _pumpChatScreen(
  WidgetTester tester,
  ProviderContainer container,
  ConversationModel initialConversation,
) async {
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(() {
    tester.view.resetPhysicalSize();
    tester.view.resetDevicePixelRatio();
  });

  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: container,
      child: MaterialApp(
        locale: const Locale('ar'),
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        supportedLocales: AppLocalizations.supportedLocales,
        home: Directionality(
          textDirection: TextDirection.rtl,
          child: ChatScreen(
            conversationId: initialConversation.id,
            initialConversation: initialConversation,
          ),
        ),
      ),
    ),
  );
  for (var i = 0; i < 20; i++) {
    await Future<void>.delayed(const Duration(milliseconds: 15));
    await tester.pump(const Duration(milliseconds: 16));
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  HttpOverrides? previousHttpOverrides;

  setUp(() {
    previousHttpOverrides = HttpOverrides.current;
    HttpOverrides.global = null;
  });

  tearDown(() {
    HttpOverrides.global = previousHttpOverrides;
  });

  testWidgets(
    'Authorized independent worker can take over from AI and transfer back to AI end-to-end',
    (tester) async {
      await tester.runAsync(() async {
        final h = await _bootWorkerHarness();
        addTearDown(() async {
          await tester.runAsync(() async {
            await h.container.read(realtimeServiceProvider).stop();
            await h.container.read(outboxWorkerProvider).stop();
            h.outbox.dispose();
            h.connectivity.dispose();
            h.container.dispose();
            await h.server.close();
          });
        });

        await _pumpChatScreen(
          tester,
          h.container,
          ConversationModel.fromJson(h.server.conversation),
        );

        expect(find.text('مشغل AI'), findsOneWidget);
        expect(find.byKey(const Key('responder-takeover-btn')), findsOneWidget);
        expect(find.byKey(const Key('responder-resume-ai-btn')), findsNothing);

        await tester.tap(find.byKey(const Key('responder-takeover-btn')));
        for (var i = 0; i < 20; i++) {
          await Future<void>.delayed(const Duration(milliseconds: 20));
          await tester.pump(const Duration(milliseconds: 16));
          if (find.byKey(const Key('responder-resume-ai-btn')).evaluate().isNotEmpty &&
              find.text('الموظف المسؤول: سلمان المستقل').evaluate().isNotEmpty) {
            break;
          }
        }

        expect(h.server.takeoverRequests.length, 1);
        expect(h.server.takeoverRequests.first['expected_handoff_version'], 1);
        expect(find.text('الموظف المسؤول: سلمان المستقل'), findsOneWidget);
        expect(find.text('الرد بواسطة الموظف'), findsOneWidget);
        expect(find.byKey(const Key('responder-takeover-btn')), findsNothing);
        expect(find.byKey(const Key('responder-resume-ai-btn')), findsOneWidget);

        await tester.tap(find.byKey(const Key('responder-resume-ai-btn')));
        for (var i = 0; i < 20; i++) {
          await Future<void>.delayed(const Duration(milliseconds: 20));
          await tester.pump(const Duration(milliseconds: 16));
          if (find.text('مشغل AI').evaluate().isNotEmpty &&
              find.byKey(const Key('responder-takeover-btn')).evaluate().isNotEmpty) {
            break;
          }
        }

        expect(h.server.resumeAiRequests.length, 1);
        expect(h.server.resumeAiRequests.first['expected_handoff_version'], 2);
        expect(find.text('مشغل AI'), findsOneWidget);
        expect(find.byKey(const Key('responder-takeover-btn')), findsOneWidget);
      });
    },
  );

  testWidgets(
    'Simultaneous 409 conflict rolls back optimistic worker takeover and displays conflict message',
    (tester) async {
      await tester.runAsync(() async {
        final h = await _bootWorkerHarness();
        h.server.simulateConflictOnTakeover = true;
        addTearDown(() async {
          await tester.runAsync(() async {
            await h.container.read(realtimeServiceProvider).stop();
            await h.container.read(outboxWorkerProvider).stop();
            h.outbox.dispose();
            h.connectivity.dispose();
            h.container.dispose();
            await h.server.close();
          });
        });

        await _pumpChatScreen(
          tester,
          h.container,
          ConversationModel.fromJson(h.server.conversation),
        );

        await tester.tap(find.byKey(const Key('responder-takeover-btn')));
        for (var i = 0; i < 25; i++) {
          await Future<void>.delayed(const Duration(milliseconds: 20));
          await tester.pump(const Duration(milliseconds: 16));
          if (find.text('مشغل AI').evaluate().isNotEmpty &&
              find.byType(SnackBar).evaluate().isNotEmpty) {
            break;
          }
        }

        final convState = h.container.read(chatControllerProvider(1)).conversation;
        expect(convState?.handoffVersion, 1);
        expect(convState?.aiEnabled, isTrue);
        expect(find.text('مشغل AI'), findsOneWidget);
        expect(find.byKey(const Key('responder-takeover-btn')), findsOneWidget);
        expect(find.byType(SnackBar), findsOneWidget);
      });
    },
  );

  testWidgets(
    'Read-only worker and worker viewing another agents conversation cannot trigger takeover or AI control',
    (tester) async {
      await tester.runAsync(() async {
        final h = await _bootWorkerHarness(userId: 21, userName: 'سلمان المستقل');
        h.server.conversation = {
          ...h.server.conversation,
          'can_reply': false,
        };
        addTearDown(() async {
          await tester.runAsync(() async {
            await h.container.read(realtimeServiceProvider).stop();
            await h.container.read(outboxWorkerProvider).stop();
            h.outbox.dispose();
            h.connectivity.dispose();
            h.container.dispose();
            await h.server.close();
          });
        });

        await _pumpChatScreen(
          tester,
          h.container,
          ConversationModel.fromJson(h.server.conversation),
        );

        expect(find.text('لا تملك صلاحية الرد على هذه المحادثة'), findsOneWidget);
        expect(find.byKey(const Key('responder-takeover-btn')), findsNothing);
        expect(find.byKey(const Key('responder-resume-ai-btn')), findsNothing);

        // Now assign active conversation to a different user (99) even if can_reply=true
        h.server.conversation = {
          ...h.server.conversation,
          'can_reply': true,
          'ai_enabled': false,
          'ai_replying': false,
          'handoff_status': 'active',
          'handoff_version': 4,
          'assigned_user_id': 99,
          'assigned_user_name': 'موظف آخر',
          'responder': {
            'status': 'human_active',
            'type': 'human',
            'name': 'موظف آخر',
            'label': 'الرد بواسطة الموظف',
            'user_id': 99,
            'is_ai_generating': false,
          },
        };
        await h.container.read(chatControllerProvider(1).notifier).refresh();
        for (var i = 0; i < 15; i++) {
          await Future<void>.delayed(const Duration(milliseconds: 15));
          await tester.pump(const Duration(milliseconds: 16));
        }

        expect(find.text('الموظف المسؤول: موظف آخر'), findsOneWidget);
        expect(find.byKey(const Key('responder-takeover-btn')), findsNothing);
        expect(find.byKey(const Key('responder-resume-ai-btn')), findsNothing);
      });
    },
  );

  testWidgets(
    'Live WS conversation.updated syncs responder immediately (<250ms) and rejects stale lower handoff_version API overwrites',
    (tester) async {
      await tester.runAsync(() async {
        final h = await _bootWorkerHarness();
        addTearDown(() async {
          await tester.runAsync(() async {
            await h.container.read(realtimeServiceProvider).stop();
            await h.container.read(outboxWorkerProvider).stop();
            h.outbox.dispose();
            h.connectivity.dispose();
            h.container.dispose();
            await h.server.close();
          });
        });

        await h.container.read(realtimeServiceProvider).start();
        await _pumpChatScreen(
          tester,
          h.container,
          ConversationModel.fromJson(h.server.conversation),
        );

        final subEnd = DateTime.now().add(const Duration(seconds: 5));
        while (DateTime.now().isBefore(subEnd)) {
          final subscribed = h.server.socketSubscriptions.values.any(
            (s) => s.contains('private-workspace.1.conversation.1'),
          );
          if (subscribed) break;
          await Future<void>.delayed(const Duration(milliseconds: 20));
        }

        final updatedPayload = {
          ...h.server.conversation,
          'ai_enabled': false,
          'ai_replying': false,
          'handoff_status': 'active',
          'handoff_version': 5,
          'assigned_user_id': 21,
          'assigned_user_name': 'سلمان المستقل',
          'updated_at': DateTime.now().toUtc().toIso8601String(),
          'responder': {
            'status': 'human_active',
            'type': 'human',
            'name': 'سلمان المستقل',
            'label': 'الرد بواسطة الموظف',
            'user_id': 21,
            'is_ai_generating': false,
          },
        };
        h.server.conversation = updatedPayload;

        final sw = Stopwatch()..start();
        // Broadcast duplicate event across both workspace + conversation channels
        h.server.broadcast('private-workspace.1.conversation.1', 'conversation.updated', updatedPayload);
        h.server.broadcast('private-workspace.1.conversations', 'conversation.updated', updatedPayload);

        while (sw.elapsedMilliseconds < 2500) {
          await Future<void>.delayed(const Duration(milliseconds: 10));
          await tester.pump(const Duration(milliseconds: 16));
          if (find.text('الموظف المسؤول: سلمان المستقل').evaluate().isNotEmpty) {
            break;
          }
        }
        sw.stop();

        expect(find.text('الموظف المسؤول: سلمان المستقل'), findsOneWidget);
        expect(find.byKey(const Key('responder-resume-ai-btn')), findsOneWidget);
        expect(sw.elapsedMilliseconds, lessThan(1000));

        // Now simulate a delayed stale HTTP conversation fetch returning version 1 (ai_enabled: true).
        // Monotonic handoff_version convergence must protect the newer WS version 5 state!
        h.server.returnStaleConvOnRefresh = true;
        await h.container.read(conversationRepositoryProvider).getById(1, workspaceId: 1);
        final stored = await h.store.getConversation(1, 1);
        expect(stored?.handoffVersion, 5);
        expect(stored?.aiEnabled, isFalse);
        expect(stored?.assignedUserId, 21);

        // ignore: avoid_print
        print('[BENCHMARK_WS_HANDOFF_SYNC] ws_handoff_to_ui_ms=${sw.elapsedMilliseconds}');
      });
    },
  );
}
