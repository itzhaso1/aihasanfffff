import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/outbox_worker.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/hybrid_realtime_service.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemSecureStore extends SecureStore {
  String? _token = 'test-sanctum-token';

  @override
  Future<void> saveToken(String token) async => _token = token;

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> clearToken() async => _token = null;
}

class _FakeAuthRepository extends AuthRepository {
  _FakeAuthRepository(super.api);

  @override
  Future<({
    MobileParticipant participant,
    UserModel user,
    List<WorkspaceModel> workspaces,
    WorkspaceModel? workspace,
  })>
  me() async {
    const ws = WorkspaceModel(id: 1, name: 'Hasem', role: 'owner');
    return (
      participant: const MobileParticipant(type: 'company_staff'),
      user: const UserModel(id: 5, name: 'وكيل', email: 'agent@hasem.sa'),
      workspaces: const [ws],
      workspace: ws,
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
    const ws = WorkspaceModel(id: 1, name: 'Hasem', role: 'owner');
    return LoginResult(
      token: 'test-sanctum-token',
      user: const UserModel(id: 5, name: 'وكيل', email: 'agent@hasem.sa'),
      workspace: ws,
      workspaces: const [ws],
      participant: const MobileParticipant(type: 'company_staff'),
    );
  }

  @override
  Future<void> logout() async {}
}

class _FakeConnectivity implements Connectivity {
  final _ctrl = StreamController<List<ConnectivityResult>>.broadcast();

  @override
  Stream<List<ConnectivityResult>> get onConnectivityChanged => _ctrl.stream;

  void emit(List<ConnectivityResult> next) => _ctrl.add(next);

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => const [
    ConnectivityResult.wifi,
  ];

  void dispose() => unawaited(_ctrl.close());
}

/// Real loopback HTTP + Pusher-v7 WebSocket harness simulating Laravel + Reverb.
class _LoopbackInboxServer {
  late final HttpServer server;
  int get port => server.port;

  final List<WebSocket> sockets = <WebSocket>[];
  final List<String> httpLog = <String>[];
  final Set<String> subscribedChannels = <String>{};

  bool failConfigOnce = false;
  bool failAuthOnce = false;
  bool failSend = false;
  int _nextMsgId = 500;

  final Map<int, Map<String, dynamic>> conversations = <int, Map<String, dynamic>>{};
  final Map<int, List<Map<String, dynamic>>> messagesByConv = <int, List<Map<String, dynamic>>>{};

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
    await server.close(force: true);
  }

  Future<void> _handle(HttpRequest req) async {
    final path = req.uri.path;
    httpLog.add('${req.method} $path${req.uri.hasQuery ? '?${req.uri.query}' : ''}');

    if (path.startsWith('/app/')) {
      final ws = await WebSocketTransformer.upgrade(req);
      sockets.add(ws);
      ws.add(
        jsonEncode({
          'event': 'pusher:connection_established',
          'data': jsonEncode({'socket_id': '777.${sockets.length}', 'activity_timeout': 60}),
        }),
      );
      ws.listen(
        (raw) => _onWsFrame(ws, raw),
        onDone: () => sockets.remove(ws),
        onError: (_) => sockets.remove(ws),
      );
      return;
    }

    final bodyStr = await utf8.decoder.bind(req).join();
    final bodyMap = bodyStr.isNotEmpty
        ? (jsonDecode(bodyStr) as Map<String, dynamic>)
        : <String, dynamic>{};

    if (path == '/api/mobile/v1/realtime/config') {
      if (failConfigOnce) {
        failConfigOnce = false;
        return _json(req, 503, {'success': false, 'message': 'unavailable'});
      }
      return _json(req, 200, {
        'success': true,
        'data': {
          'enabled': true,
          'driver': 'reverb',
          'key': 'test-key',
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
      if (failAuthOnce) {
        failAuthOnce = false;
        return _json(req, 500, {'message': 'transient auth failure'});
      }
      return _json(req, 200, {'auth': 'test-key:sig'});
    }

    if (path == '/api/mobile/v1/home') {
      final unread = conversations.values.where((c) => ((c['unread_count'] as int?) ?? 0) > 0).length;
      return _json(req, 200, {
        'success': true,
        'data': {
          'unread_conversations': unread,
          'unread_email': 0,
          'unread_notifications': 0,
        },
      });
    }

    if (path == '/api/mobile/v1/conversations') {
      final updatedAfter = req.uri.queryParameters['updated_after'];
      var list = conversations.values.toList();
      if (updatedAfter != null && updatedAfter.isNotEmpty) {
        final since = DateTime.tryParse(updatedAfter);
        if (since != null) {
          list = list.where((c) {
            final t = DateTime.tryParse(c['last_message_at']?.toString() ?? '');
            return t != null && !t.isBefore(since);
          }).toList();
        }
      }
      return _json(req, 200, {
        'success': true,
        'data': list,
        'meta': {'next_cursor': null},
      });
    }

    final singleConv = RegExp(r'^/api/mobile/v1/conversations/(\d+)$').firstMatch(path);
    if (singleConv != null) {
      final id = int.parse(singleConv.group(1)!);
      final conv = conversations[id];
      if (conv == null) return _json(req, 404, {'success': false});
      return _json(req, 200, {'success': true, 'data': conv});
    }

    final msgGetMatch = RegExp(r'^/api/mobile/v1/conversations/(\d+)/messages$').firstMatch(path);
    if (msgGetMatch != null && req.method == 'GET') {
      final cid = int.parse(msgGetMatch.group(1)!);
      final afterId = int.tryParse(req.uri.queryParameters['after_id'] ?? '') ?? 0;
      final all = messagesByConv[cid] ?? <Map<String, dynamic>>[];
      if (afterId > 0) {
        final newer = all.where((m) => (m['id'] as int) > afterId).toList();
        return _json(req, 200, {
          'success': true,
          'data': newer,
          'meta': {'count': newer.length, 'has_more': false},
        });
      }
      return _json(req, 200, {
        'success': true,
        'data': all.reversed.toList(),
        'meta': {'next_cursor': null},
      });
    }

    if (msgGetMatch != null && req.method == 'POST') {
      final cid = int.parse(msgGetMatch.group(1)!);
      if (failSend) {
        return _json(req, 422, {'success': false, 'message': 'rejected'});
      }
      final key = bodyMap['idempotency_key']?.toString();
      final list = messagesByConv.putIfAbsent(cid, () => []);
      final existing = list.where((m) => m['client_id'] == key).toList();
      if (existing.isNotEmpty) {
        return _json(req, 201, {'success': true, 'data': existing.first});
      }
      final created = {
        'id': ++_nextMsgId,
        'conversation_id': cid,
        'direction': bodyMap['direction'] ?? 'outbound',
        'message_type': bodyMap['message_type'] ?? 'text',
        'content': bodyMap['content'] ?? '',
        'ai_generated': false,
        'delivery_status': 'pending',
        'client_id': key,
        'idempotency_key': key,
        'created_at': DateTime.now().toUtc().toIso8601String(),
      };
      list.add(created);
      if (conversations[cid] != null) {
        conversations[cid]!['last_message'] = created;
        conversations[cid]!['last_message_at'] = created['created_at'];
      }
      broadcast('private-workspace.1.conversation.$cid', 'message.created', created);
      return _json(req, 201, {'success': true, 'data': created});
    }

    if (path.endsWith('/read') || path.contains('/presence/') || path.endsWith('/typing')) {
      return _json(req, 200, {'success': true, 'data': {'unread_count': 0}});
    }

    return _json(req, 404, {'success': false});
  }

  void _onWsFrame(WebSocket ws, dynamic raw) {
    final map = jsonDecode(raw as String) as Map<String, dynamic>;
    final event = map['event']?.toString();
    final data = map['data'] is Map ? Map<String, dynamic>.from(map['data']) : <String, dynamic>{};
    if (event == 'pusher:ping') {
      ws.add(jsonEncode({'event': 'pusher:pong', 'data': {}}));
    } else if (event == 'pusher:subscribe') {
      final ch = data['channel']?.toString() ?? '';
      subscribedChannels.add(ch);
      ws.add(
        jsonEncode({
          'event': 'pusher_internal:subscription_succeeded',
          'channel': ch,
          'data': '{}',
        }),
      );
    } else if (event == 'pusher:unsubscribe') {
      subscribedChannels.remove(data['channel']?.toString() ?? '');
    }
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
      s.add(payload);
    }
  }

  void _json(HttpRequest req, int status, Map<String, dynamic> payload) {
    req.response.statusCode = status;
    req.response.headers.contentType = ContentType.json;
    req.response.write(jsonEncode(payload));
    req.response.close();
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  HttpOverrides? previousHttpOverrides;

  setUp(() {
    previousHttpOverrides = HttpOverrides.current;
    HttpOverrides.global = null;
  });

  tearDown(() {
    HttpOverrides.global = previousHttpOverrides;
  });

  Future<
    ({
      ProviderContainer container,
      _LoopbackInboxServer server,
      InboxLocalStore store,
      _FakeConnectivity connectivity,
    })
  >
  bootHarness({bool failConfigOnce = false, bool failAuthOnce = false}) async {
    final server = _LoopbackInboxServer()
      ..failConfigOnce = failConfigOnce
      ..failAuthOnce = failAuthOnce;
    await server.start();

    server.conversations[1] = {
      'id': 1,
      'channel': 'whatsapp',
      'status': 'open',
      'ai_enabled': true,
      'unread_count': 0,
      'last_message_at': '2026-11-25T00:00:00Z',
      'customer': {'id': 10, 'name': 'أحمد العميل', 'phone': '966500000001'},
      'last_message': {
        'id': 101,
        'conversation_id': 1,
        'direction': 'inbound',
        'message_type': 'text',
        'content': 'أول رسالة',
        'created_at': '2026-11-25T00:00:00Z',
      },
    };
    server.conversations[2] = {
      'id': 2,
      'channel': 'whatsapp',
      'status': 'open',
      'ai_enabled': true,
      'unread_count': 0,
      'last_message_at': '2026-11-24T23:00:00Z',
      'customer': {'id': 11, 'name': 'سارة', 'phone': '966500000002'},
      'last_message': {
        'id': 201,
        'conversation_id': 2,
        'direction': 'inbound',
        'message_type': 'text',
        'content': 'قديمة',
        'created_at': '2026-11-24T23:00:00Z',
      },
    };
    server.messagesByConv[1] = [
      server.conversations[1]!['last_message'] as Map<String, dynamic>,
    ];

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
    final api = ApiClient(
      secureStore: secure,
      prefsStore: prefs,
    );
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
        authRepositoryProvider.overrideWithValue(_FakeAuthRepository(api)),
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
      await authCtrl.login('agent@hasem.sa', 'secret');
    }

    return (
      container: container,
      server: server,
      store: store,
      connectivity: connectivity,
    );
  }

  Future<void> waitUntil(
    bool Function() predicate, {
    Duration timeout = const Duration(seconds: 6),
  }) async {
    final end = DateTime.now().add(timeout);
    while (DateTime.now().isBefore(end)) {
      if (predicate()) return;
      await Future<void>.delayed(const Duration(milliseconds: 25));
    }
    fail('Condition not met within ${timeout.inMilliseconds}ms');
  }

  test('WS config retry, auth retry, optimistic send (<100ms), AI/out-of-order, status ticks, inbox delta, reconnect catch-up, and new conversation', () async {
    final h = await bootHarness(failConfigOnce: true, failAuthOnce: true);
    addTearDown(() async {
      await h.container.read(realtimeServiceProvider).stop();
      h.container.read(realtimeServiceProvider).dispose();
      await h.container.read(outboxWorkerProvider).stop();
      h.container.read(outboxWorkerProvider).dispose();
      h.connectivity.dispose();
      await h.container.read(inboxDatabaseProvider).close();
      h.container.dispose();
      await h.server.close();
    });

    final realtime = h.container.read(realtimeServiceProvider);
    final listCtrl = h.container.read(conversationsControllerProvider.notifier);
    final homeCtrl = h.container.read(homeControllerProvider.notifier);
    await listCtrl.refresh();
    await homeCtrl.refresh();

    // Start realtime while first /realtime/config returns 503; retry recovers and connects WS!
    await realtime.start();
    await waitUntil(() => realtime.isWebSocketConnected, timeout: const Duration(seconds: 6));

    // Open conversation 1 and wait for private channel confirmation (even after 1st 500 auth error).
    final chatCtrl = h.container.read(chatControllerProvider(1).notifier);
    await waitUntil(() => h.server.subscribedChannels.contains('private-workspace.1.conversation.1'));

    // 1. Optimistic send speed & reconciliation + live status ticks (pending -> sent -> delivered -> read)
    final sendSw = Stopwatch()..start();
    final sendFuture = chatCtrl.send('رسالة موظف فورية');
    final optMessages = h.container.read(chatControllerProvider(1)).messages;
    sendSw.stop();
    expect(sendSw.elapsedMilliseconds, lessThan(100));
    expect(optMessages.any((m) => m.content == 'رسالة موظف فورية' && m.localPending), isTrue);

    final accepted = await sendFuture;
    expect(accepted, isTrue);

    await waitUntil(() {
      final msgs = h.container.read(chatControllerProvider(1)).messages;
      return msgs.any((m) => m.id == 501 && !m.localPending);
    });
    expect(
      h.container.read(chatControllerProvider(1)).messages.where((m) => m.content == 'رسالة موظف فورية'),
      hasLength(1),
    );

    // Status transitions via WS
    for (final st in ['sent', 'delivered', 'read']) {
      h.server.broadcast('private-workspace.1.conversation.1', 'message.status_updated', {
        'id': 501,
        'conversation_id': 1,
        'workspace_id': 1,
        'delivery_status': st,
        if (st == 'delivered') 'delivered_at': DateTime.now().toUtc().toIso8601String(),
        if (st == 'read') 'read_at': DateTime.now().toUtc().toIso8601String(),
      });
      await waitUntil(
        () => h.container.read(chatControllerProvider(1)).messages.firstWhere((m) => m.id == 501).deliveryStatus == st,
      );
    }

    // 2. Out-of-order + AI reply + Duplicate + Attachment enrichment update + measured WS latency
    final deliverSw = Stopwatch()..start();
    h.server.broadcast('private-workspace.1.conversation.1', 'message.created', {
      'id': 602,
      'conversation_id': 1,
      'direction': 'outbound',
      'message_type': 'text',
      'content': 'رد الذكاء الاصطناعي مشعل',
      'ai_generated': true,
      'delivery_status': 'sent',
      // Skewed earlier timestamp must NOT place 602 before 601!
      'created_at': '2026-11-24T01:00:00Z',
    });
    h.server.broadcast('private-workspace.1.conversation.1', 'message.created', {
      'id': 601,
      'conversation_id': 1,
      'direction': 'inbound',
      'message_type': 'text',
      'content': 'استفسار عميل لحظي',
      'created_at': '2026-11-25T12:00:00Z',
    });
    // Exact duplicate of 601 should not duplicate!
    h.server.broadcast('private-workspace.1.conversation.1', 'message.created', {
      'id': 601,
      'conversation_id': 1,
      'direction': 'inbound',
      'message_type': 'text',
      'content': 'استفسار عميل لحظي',
      'created_at': '2026-11-25T12:00:00Z',
    });
    await waitUntil(() {
      final ids = h.container.read(chatControllerProvider(1)).messages.map((m) => m.id).toList();
      return ids.contains(601) && ids.contains(602);
    });
    deliverSw.stop();
    expect(deliverSw.elapsedMilliseconds, lessThan(500));

    final orderedIds = h.container.read(chatControllerProvider(1)).messages.map((m) => m.id).toList();
    expect(orderedIds, [101, 501, 601, 602]);

    // Attachment update for already-received message id 601 MUST pass through dedupe!
    h.server.broadcast('private-workspace.1.conversation.1', 'message.created', {
      'id': 601,
      'conversation_id': 1,
      'direction': 'inbound',
      'message_type': 'image',
      'content': 'استفسار عميل لحظي',
      'attachments': [
        {
          'id': 90,
          'kind': 'image',
          'original_name': 'doc.jpg',
          'status': 'stored',
          'download_url': 'http://127.0.0.1:${h.server.port}/att/90',
        },
      ],
      'created_at': '2026-11-25T12:00:00Z',
    });
    await waitUntil(
      () => h.container.read(chatControllerProvider(1)).messages.firstWhere((m) => m.id == 601).attachments.length == 1,
    );

    // 3. Closed Conversation 2 receives inbound message -> bumps to top + updates preview & unreadCount WITHOUT full GET /conversations reload!
    final convListRequestsBefore = h.server.httpLog.where((e) => e == 'GET /api/mobile/v1/conversations?filter=all&per_page=20').length;
    h.server.broadcast('private-workspace.1.conversations', 'message.created', {
      'id': 701,
      'conversation_id': 2,
      'direction': 'inbound',
      'message_type': 'text',
      'content': 'رسالة عميل غير مفتوحة',
      'created_at': DateTime.now().toUtc().toIso8601String(),
    });
    await waitUntil(() {
      final items = h.container.read(conversationsControllerProvider).items;
      return items.isNotEmpty &&
          items.first.id == 2 &&
          items.first.unreadCount == 1 &&
          items.first.preview == 'رسالة عميل غير مفتوحة';
    });
    final convListRequestsAfter = h.server.httpLog.where((e) => e == 'GET /api/mobile/v1/conversations?filter=all&per_page=20').length;
    expect(convListRequestsAfter, convListRequestsBefore);

    // 4. Open Conversation 1 responder updates live via conversation.updated!
    h.server.broadcast('private-workspace.1.conversation.1', 'conversation.updated', {
      'id': 1,
      'workspace_id': 1,
      'status': 'open',
      'channel': 'whatsapp',
      'ai_enabled': false,
      'handoff_status': 'active',
      'assigned_user_id': 5,
      'assigned_user_name': 'وكيل',
      'responder': {
        'status': 'human_active',
        'type': 'human',
        'name': 'وكيل',
        'label': 'الموظف يرد الآن',
        'user_id': 5,
      },
    });
    await waitUntil(
      () => h.container.read(chatControllerProvider(1)).conversation?.handoffStatus == 'active',
    );

    // 5. Brand-new conversation 99 appears automatically via getById
    h.server.conversations[99] = {
      'id': 99,
      'channel': 'telegram',
      'status': 'open',
      'ai_enabled': true,
      'unread_count': 1,
      'last_message_at': DateTime.now().add(const Duration(minutes: 5)).toUtc().toIso8601String(),
      'customer': {'id': 990, 'name': 'عميل جديد كلياً'},
      'last_message': {
        'id': 991,
        'conversation_id': 99,
        'direction': 'inbound',
        'message_type': 'text',
        'content': 'أول رسالة تليجرام',
        'created_at': DateTime.now().add(const Duration(minutes: 5)).toUtc().toIso8601String(),
      },
    };
    h.server.broadcast('private-workspace.1.conversations', 'message.created', {
      'id': 991,
      'conversation_id': 99,
      'direction': 'inbound',
      'message_type': 'text',
      'content': 'أول رسالة تليجرام',
      'created_at': DateTime.now().add(const Duration(minutes: 5)).toUtc().toIso8601String(),
    });
    await waitUntil(
      () => h.container.read(conversationsControllerProvider).items.any((c) => c.id == 99),
    );

    // 6. Drop WebSocket, queue missed message 650 on server, reconnect, and verify incremental after_id=602 recovery!
    for (final s in [...h.server.sockets]) {
      await s.close();
    }
    h.server.messagesByConv[1]!.add({
      'id': 650,
      'conversation_id': 1,
      'direction': 'inbound',
      'message_type': 'text',
      'content': 'رسالة فاتت وقت الانقطاع',
      'created_at': DateTime.now().toUtc().toIso8601String(),
    });
    await waitUntil(
      () => h.container.read(chatControllerProvider(1)).messages.any((m) => m.id == 650),
    );
    expect(
      h.server.httpLog.any((e) => e.contains('/api/mobile/v1/conversations/1/messages?after_id=602')),
      isTrue,
    );

    // 7. Lifecycle background->foreground & Wi-Fi->Mobile connectivity switch recovery + multi-device message sync
    final hybrid = realtime as HybridRealtimeService;
    h.server.messagesByConv[1]!.add({
      'id': 651,
      'conversation_id': 1,
      'direction': 'outbound',
      'message_type': 'text',
      'content': 'رسالة من جهاز آخر أثناء الخلفية',
      'user_id': 8,
      'user_name': 'زميل آخر',
      'delivery_status': 'sent',
      'created_at': DateTime.now().toUtc().toIso8601String(),
    });
    hybrid.didChangeAppLifecycleState(AppLifecycleState.paused);
    hybrid.didChangeAppLifecycleState(AppLifecycleState.resumed);
    await waitUntil(
      () => h.container.read(chatControllerProvider(1)).messages.any((m) => m.id == 651),
    );

    // Simulate network interface switch Wi-Fi -> Mobile Data
    final prevSockets = h.server.sockets.length;
    h.connectivity.emit([ConnectivityResult.wifi]);
    h.connectivity.emit([ConnectivityResult.mobile]);
    await waitUntil(() => realtime.isWebSocketConnected);
    expect(prevSockets, greaterThan(0));

    // Print measured real-time latencies to test output for verification
    // ignore: avoid_print
    print(
      '[BENCHMARK_FLUTTER] optimistic_ui_ms=${sendSw.elapsedMilliseconds} '
      'ws_burst_ai_inbound_ms=${deliverSw.elapsedMilliseconds}',
    );

    // 8. Failed send + retry reconciliation
    h.server.failSend = true;
    await chatCtrl.send('محاولة فاشلة');
    await waitUntil(
      () => h.container.read(chatControllerProvider(1)).messages.any((m) => m.content == 'محاولة فاشلة' && m.localFailed),
    );
    h.server.failSend = false;
    final failedMsg = h.container.read(chatControllerProvider(1)).messages.firstWhere((m) => m.content == 'محاولة فاشلة');
    await chatCtrl.retryFailed(failedMsg);
    await waitUntil(
      () => h.container.read(chatControllerProvider(1)).messages.any((m) => m.content == 'محاولة فاشلة' && m.id > 0 && !m.localFailed),
    );
  });

  testWidgets('ChatScreen preserves scroll offset, shows status ticks, and does not interrupt reading on new message', (tester) async {
    final h = await bootHarness();
    addTearDown(() async {
      await h.container.read(realtimeServiceProvider).stop();
      h.container.read(realtimeServiceProvider).dispose();
      await h.container.read(outboxWorkerProvider).stop();
      h.container.read(outboxWorkerProvider).dispose();
      h.connectivity.dispose();
      await h.container.read(inboxDatabaseProvider).close();
      h.container.dispose();
      await h.server.close();
    });

    // Seed 35 messages so ListView is comfortably scrollable
    h.server.messagesByConv[1] = [
      for (var i = 1; i <= 35; i++)
        {
          'id': i,
          'conversation_id': 1,
          'direction': i.isEven ? 'outbound' : 'inbound',
          'message_type': 'text',
          'content': 'سطر اختبار رقم $i',
          'delivery_status': i == 34 ? 'delivered' : (i == 32 ? 'sent' : 'read'),
          'created_at': DateTime.utc(2026, 11, 25, 0, i).toIso8601String(),
        },
    ];

    await h.container.read(realtimeServiceProvider).start();

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: h.container,
        child: MaterialApp(
          locale: const Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: Directionality(
            textDirection: TextDirection.rtl,
            child: ChatScreen(
              conversationId: 1,
              initialConversation: ConversationModel.fromJson(h.server.conversations[1]!),
            ),
          ),
        ),
      ),
    );

    await tester.pumpAndSettle();
    expect(find.text('سطر اختبار رقم 35'), findsOneWidget);

    // Scroll up into history so user is reading older messages (_stickToBottom becomes false)
    final scrollable = find.byType(Scrollable).first;
    await tester.drag(scrollable, const Offset(0, 600));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('chat-scroll-to-bottom')), findsOneWidget);

    // Receive new customer message while scrolled up: must NOT jump away, and must show unseen badge '1'
    h.server.broadcast('private-workspace.1.conversation.1', 'message.created', {
      'id': 36,
      'conversation_id': 1,
      'direction': 'inbound',
      'message_type': 'text',
      'content': 'رسالة جديدة أثناء التمرير للأعلى',
      'created_at': DateTime.utc(2026, 11, 25, 1, 0).toIso8601String(),
    });
    await tester.pump(const Duration(milliseconds: 120));
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('chat-scroll-to-bottom')), findsOneWidget);
    expect(find.text('1'), findsOneWidget);

    // Tap scroll-to-bottom jumps cleanly to newest message and clears badge
    await tester.tap(find.byKey(const Key('chat-scroll-to-bottom')));
    await tester.pumpAndSettle();
    expect(find.text('رسالة جديدة أثناء التمرير للأعلى'), findsOneWidget);

    // Stable key present without array index suffix
    expect(find.byKey(const ValueKey('chat-msg-s-36')), findsOneWidget);
    expect(find.byType(ChatMessageBubble), findsWidgets);
  });
}
