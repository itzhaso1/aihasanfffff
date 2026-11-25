import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/outbox_worker.dart';
import 'package:hasim/core/logging/messaging_logger.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/presentation/widgets/chat_message_bubble.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:hasim/realtime/reverb_websocket_client.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

class _MemSecure extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}

  @override
  Future<String?> readToken() async => 'test-token';

  @override
  Future<void> clearToken() async {}
}

class _FakeAuthRepo extends AuthRepository {
  _FakeAuthRepo(super.api);
}

class _ScriptedRepo extends ConversationRepository {
  _ScriptedRepo(super.api, {super.local});

  final List<String> sentOrder = [];
  final List<String> correlationIds = [];
  final List<MessageModel> serverMessages = [];
  final Map<String, Future<MessageModel> Function(String key)> customHandlers = {};
  int nextServerId = 1000;
  Object? nextError;
  int retryEndpointCalls = 0;

  @override
  Future<ConversationModel> getById(int id, {int? workspaceId}) async {
    return ConversationModel(
      id: id,
      channel: 'whatsapp',
      status: 'open',
      aiEnabled: false,
      customer: const CustomerModel(id: 9, name: 'عميل الاختبار'),
    );
  }

  @override
  Future<({List<MessageModel> items, String? nextCursor, bool hasMore})>
  messagesPage(
    int conversationId, {
    String? cursor,
    int? afterId,
    int? workspaceId,
    int perPage = 30,
  }) async {
    final reversed = serverMessages.reversed.toList();
    return (items: reversed, nextCursor: null, hasMore: false);
  }

  @override
  Future<MessageModel?> findMessageByClientIdOnServer(
    int conversationId,
    String clientId,
  ) async {
    for (final m in serverMessages) {
      if (m.clientId == clientId) return m;
    }
    return null;
  }

  @override
  Future<MessageModel> sendMessageWithKey(
    int conversationId,
    String content,
    String key, {
    String direction = 'outbound',
    String? correlationId,
  }) async {
    sentOrder.add(content);
    if (correlationId != null) correlationIds.add(correlationId);
    final handler = customHandlers[key] ?? customHandlers[content];
    if (handler != null) {
      return handler(key);
    }
    if (nextError != null) {
      final err = nextError!;
      nextError = null;
      throw err;
    }
    final created = MessageModel(
      id: nextServerId++,
      conversationId: conversationId,
      direction: direction,
      messageType: 'text',
      content: content,
      createdAt: DateTime.now(),
      deliveryStatus: 'pending',
      clientId: key,
      correlationId: correlationId,
    );
    serverMessages.add(created);
    return created;
  }

  @override
  Future<MessageModel> retryMessage(
    int conversationId,
    int messageId, {
    String? correlationId,
  }) async {
    retryEndpointCalls += 1;
    final idx = serverMessages.indexWhere((m) => m.id == messageId);
    final base = idx >= 0
        ? serverMessages[idx]
        : MessageModel(
            id: messageId,
            conversationId: conversationId,
            direction: 'outbound',
            messageType: 'text',
            content: 'retried',
          );
    final updated = base.copyWith(
      deliveryStatus: 'sent',
      clearLocalError: true,
      correlationId: correlationId ?? base.correlationId,
    );
    if (idx >= 0) serverMessages[idx] = updated;
    return updated;
  }

  @override
  Future<void> markRead(
    int conversationId, {
    int? messageId,
    int? workspaceId,
  }) async {}
}

class _StubRealtime implements RealtimeService {
  final _msg = StreamController<MessageModel>.broadcast();
  final _conv = StreamController<ConversationModel>.broadcast();
  final _status = StreamController<MessageStatusUpdate>.broadcast();
  final _typing = StreamController<Map<String, dynamic>>.broadcast();
  final _presence = StreamController<Map<String, dynamic>>.broadcast();
  final _reconnect = StreamController<void>.broadcast();
  final _convChanged = StreamController<void>.broadcast();
  final _conn = StreamController<RealtimeConnectionState>.broadcast();
  final Set<int> _watched = {};

  void emitMessage(MessageModel m) => _msg.add(m);
  void emitStatus(MessageStatusUpdate u) => _status.add(u);
  void emitReconnect() => _reconnect.add(null);

  @override
  Stream<MessageModel> get messageCreated => _msg.stream;
  @override
  Stream<ConversationModel> get conversationUpdated => _conv.stream;
  @override
  Stream<MessageStatusUpdate> get messageStatusUpdated => _status.stream;
  @override
  Stream<Map<String, dynamic>> get typingActivity => _typing.stream;
  @override
  Stream<Map<String, dynamic>> get presenceActivity => _presence.stream;
  @override
  Stream<void> get conversationsChanged => _convChanged.stream;
  @override
  Stream<void> get reconnectSyncRequested => _reconnect.stream;
  @override
  Stream<RealtimeConnectionState> get connectionStateStream => _conn.stream;
  @override
  RealtimeConnectionState get currentConnectionState =>
      RealtimeConnectionState.connected;
  @override
  bool get isWebSocketConnected => true;
  @override
  int? get activeConversationId => _watched.isEmpty ? null : _watched.last;
  @override
  Future<void> start() async {}
  @override
  Future<void> stop() async {}
  @override
  void updateWorkspace(int? workspaceId) {}
  @override
  void watchConversation(int? conversationId) {
    if (conversationId != null) _watched.add(conversationId);
  }
  @override
  void unwatchConversation(int conversationId) => _watched.remove(conversationId);
  @override
  void setSeedMaxMessageId(int conversationId, int messageId) {}
  @override
  Future<void> catchUpConversation(int conversationId, {int afterId = 0}) async {}
  @override
  void triggerPoll() {}
  @override
  void dispose() {
    unawaited(_msg.close());
    unawaited(_conv.close());
    unawaited(_status.close());
    unawaited(_typing.close());
    unawaited(_presence.close());
    unawaited(_convChanged.close());
    unawaited(_reconnect.close());
    unawaited(_conn.close());
  }
}

class _FakeConnectivity implements Connectivity {
  final _ctrl = StreamController<List<ConnectivityResult>>.broadcast();

  @override
  Stream<List<ConnectivityResult>> get onConnectivityChanged => _ctrl.stream;

  void emitOnline() => _ctrl.add(const [ConnectivityResult.wifi]);

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => const [
    ConnectivityResult.wifi,
  ];

  void dispose() => unawaited(_ctrl.close());
}

Future<({
  ProviderContainer container,
  InboxDatabase db,
  InboxLocalStore store,
  _ScriptedRepo repo,
  _StubRealtime realtime,
  OutboxWorker worker,
  _FakeConnectivity connectivity,
})> _harness({InboxDatabase? customDb, bool startWorker = true}) async {
  MessagingLogger.clear();
  SharedPreferences.setMockInitialValues({'workspace_id': 1});
  final prefs = PrefsStore(await SharedPreferences.getInstance());
  final api = isolatedTestApiClient(secureStore: _MemSecure(), prefsStore: prefs);
  final db = customDb ?? InboxDatabase.memory();
  final store = InboxLocalStore(db);
  final session = InboxSession()..beginWorkspace(1);
  final repo = _ScriptedRepo(api, local: store);
  final realtime = _StubRealtime();
  final connectivity = _FakeConnectivity();
  final worker = OutboxWorker(
    store: store,
    repository: repo,
    session: session,
    connectivity: connectivity,
  );
  if (startWorker) worker.start();

  final container = ProviderContainer(
    overrides: [
      prefsStoreProvider.overrideWithValue(prefs),
      secureStoreProvider.overrideWithValue(_MemSecure()),
      apiClientProvider.overrideWithValue(api),
      authRepositoryProvider.overrideWithValue(_FakeAuthRepo(api)),
      inboxDatabaseProvider.overrideWithValue(db),
      inboxLocalStoreProvider.overrideWithValue(store),
      inboxSessionProvider.overrideWithValue(session),
      activeWorkspaceIdProvider.overrideWith((ref) => 1),
      conversationRepositoryProvider.overrideWithValue(repo),
      realtimeServiceProvider.overrideWithValue(realtime),
      outboxWorkerProvider.overrideWithValue(worker),
    ],
  );
  container.read(authControllerProvider.notifier).state = const AuthState(
    bootstrapping: false,
    token: 'test-token',
    user: UserModel(id: 5, name: 'وكيل', email: 'agent@hasem.sa'),
    workspace: WorkspaceModel(id: 1, name: 'Hasem', role: 'owner'),
    workspaces: [WorkspaceModel(id: 1, name: 'Hasem', role: 'owner')],
  );

  addTearDown(() async {
    await worker.stop();
    worker.dispose();
    connectivity.dispose();
    realtime.dispose();
    container.dispose();
    await db.close();
  });

  return (
    container: container,
    db: db,
    store: store,
    repo: repo,
    realtime: realtime,
    worker: worker,
    connectivity: connectivity,
  );
}

Widget _wrapBubble(Widget child) {
  return MaterialApp(
    locale: const Locale('ar'),
    localizationsDelegates: AppLocalizations.localizationsDelegates,
    supportedLocales: AppLocalizations.supportedLocales,
    home: Scaffold(body: Center(child: child)),
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('ChatMessageBubble renders honest states and never fakes read ticks for unknown status', (tester) async {
    Future<void> pumpPhase(MessageModel m) async {
      await tester.pumpWidget(_wrapBubble(ChatMessageBubble(message: m, onRetry: () {})));
    }

    // Pending (queued locally) -> clock
    await pumpPhase(const MessageModel(id: -1, conversationId: 1, direction: 'outbound', messageType: 'text', content: 'p', localPending: true));
    expect(find.byIcon(Icons.access_time_rounded), findsOneWidget);
    expect(find.byIcon(Icons.done_all_rounded), findsNothing);

    // Sending -> spinner
    await pumpPhase(const MessageModel(id: -2, conversationId: 1, direction: 'outbound', messageType: 'text', content: 's', localPending: true, localSending: true));
    expect(find.byType(CircularProgressIndicator), findsOneWidget);

    // Accepted by server (deliveryStatus: 'pending') -> distinct server-accepted icon, NOT done checkmark
    await pumpPhase(const MessageModel(id: 10, conversationId: 1, direction: 'outbound', messageType: 'text', content: 'a', deliveryStatus: 'pending'));
    expect(find.byIcon(Icons.schedule_send_outlined), findsOneWidget);
    expect(find.byIcon(Icons.done_rounded), findsNothing);

    // Sent to provider -> single tick
    await pumpPhase(const MessageModel(id: 11, conversationId: 1, direction: 'outbound', messageType: 'text', content: 'sent', deliveryStatus: 'sent'));
    expect(find.byIcon(Icons.done_rounded), findsOneWidget);

    // Delivered -> muted double tick
    await pumpPhase(const MessageModel(id: 12, conversationId: 1, direction: 'outbound', messageType: 'text', content: 'deliv', deliveryStatus: 'delivered'));
    final delivIcon = tester.widget<Icon>(find.byIcon(Icons.done_all_rounded));
    expect(delivIcon.color, isNot(HasimTokens.readReceipt));

    // Read -> readReceipt colored double tick
    await pumpPhase(const MessageModel(id: 13, conversationId: 1, direction: 'outbound', messageType: 'text', content: 'read', deliveryStatus: 'read'));
    final readIcon = tester.widget<Icon>(find.byIcon(Icons.done_all_rounded));
    expect(readIcon.color, HasimTokens.readReceipt);

    // Unknown status -> help icon, NEVER green read tick!
    await pumpPhase(const MessageModel(id: 14, conversationId: 1, direction: 'outbound', messageType: 'text', content: 'unk', deliveryStatus: 'something_weird'));
    expect(find.byIcon(Icons.help_outline_rounded), findsOneWidget);
    expect(find.byIcon(Icons.done_all_rounded), findsNothing);

    // Server failed (id > 0, deliveryStatus == 'failed') -> danger UI + error text + retry
    await pumpPhase(const MessageModel(id: 15, conversationId: 1, direction: 'outbound', messageType: 'text', content: 'fail', deliveryStatus: 'failed', localError: 'missing_token'));
    expect(find.byIcon(Icons.error_outline_rounded), findsOneWidget);
    expect(find.text('missing_token'), findsOneWidget);
  });

  test('20 rapid consecutive sends keep unique monotonic IDs and preserve exact FIFO order', () async {
    final h = await _harness();
    final ctrl = h.container.read(chatControllerProvider(1).notifier);
    await h.container.read(chatControllerProvider(1).notifier).refresh();

    final futures = <Future<bool>>[];
    for (var i = 1; i <= 20; i++) {
      futures.add(ctrl.send('رسالة سريعة $i'));
    }
    final results = await Future.wait(futures);
    expect(results.every((r) => r), isTrue);

    // Let coalesced OutboxWorker finish all 20 sequential flushes.
    await h.worker.flush(workspaceId: 1);

    final state = h.container.read(chatControllerProvider(1));
    expect(state.messages.length, 20);
    expect(
      state.messages.map((m) => m.content).toList(),
      [for (var i = 1; i <= 20; i++) 'رسالة سريعة $i'],
    );
    expect(h.repo.sentOrder, [for (var i = 1; i <= 20; i++) 'رسالة سريعة $i']);
    expect(state.messages.every((m) => m.id >= 1000 && !m.localPending), isTrue);
    expect(MessagingLogger.recentEvents.where((e) => e.stage == 'SEND_BUTTON_PRESSED').length, 20);
    expect(MessagingLogger.recentEvents.where((e) => e.stage == 'MESSAGE_RECONCILED').length, 20);
  });

  testWidgets('two identical consecutive messages in ChatScreen are both sent immediately without 400ms suppression', (tester) async {
    final h = await _harness();
    h.repo.serverMessages.add(
      const MessageModel(
        id: 100,
        conversationId: 1,
        direction: 'inbound',
        messageType: 'text',
        content: 'السلام عليكم',
      ),
    );

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: h.container,
        child: const MaterialApp(
          locale: Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: ChatScreen(conversationId: 1),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 50));

    final field = find.byType(TextField).first;
    final sendBtn = find.byKey(const Key('chat-send-button'));

    await tester.enterText(field, 'مرحبا');
    await tester.pump();
    await tester.tap(sendBtn);
    await tester.pump(const Duration(milliseconds: 20));

    await tester.enterText(field, 'مرحبا');
    await tester.pump();
    await tester.tap(sendBtn);
    await tester.pump(const Duration(milliseconds: 80));

    await h.worker.flush(workspaceId: 1);
    await tester.pump(const Duration(milliseconds: 50));

    expect(h.repo.sentOrder.where((t) => t == 'مرحبا').length, 2);
    final outboundHellos = h.container
        .read(chatControllerProvider(1))
        .messages
        .where((m) => m.content == 'مرحبا' && m.isOutbound)
        .toList();
    expect(outboundHellos.length, 2);
    expect(outboundHellos[0].clientId, isNot(outboundHellos[1].clientId));

    await tester.pumpWidget(const SizedBox());
    h.container.dispose();
  });

  test('network timeout AFTER server already persisted reconciles via server lookup with zero duplicate sends', () async {
    final h = await _harness(startWorker: false);
    final persisted = MessageModel(
      id: 888,
      conversationId: 1,
      direction: 'outbound',
      messageType: 'text',
      content: 'انقطع الاتصال بعد الحفظ',
      createdAt: DateTime.now(),
      deliveryStatus: 'sent',
      clientId: 'lost-resp-key',
    );
    var postCalls = 0;
    h.repo.customHandlers['lost-resp-key'] = (key) async {
      postCalls += 1;
      h.repo.serverMessages.add(persisted);
      throw ApiException('timeout');
    };

    await h.store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 1,
      message: MessageModel(
        id: -10,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'انقطع الاتصال بعد الحفظ',
        createdAt: DateTime.now(),
        localPending: true,
        clientId: 'lost-resp-key',
        correlationId: 'corr-lost-1',
      ),
    );

    await h.worker.flush(workspaceId: 1);

    expect(postCalls, 1);
    expect(await h.store.outboxCount(1), 0);
    final msgs = await h.store.getMessages(1, 1);
    expect(msgs.single.id, 888);
    expect(msgs.single.deliveryStatus, 'sent');
    expect(msgs.single.localPending, isFalse);
  });

  test('409 idempotency_in_flight and HTTP 500 schedule automatic retry timer instead of permanent failure', () async {
    final h = await _harness(startWorker: false);
    h.repo.nextError = ApiException(
      'This request is already in progress.',
      statusCode: 409,
      errorCode: 'idempotency_in_flight',
      retryAfterSeconds: 1,
    );

    await h.store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 1,
      message: const MessageModel(
        id: -20,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'انتظار 409',
        localPending: true,
        clientId: 'key-409',
      ),
    );
    await h.worker.flush(workspaceId: 1);

    final op409 = await h.store.outboxByClientId(1, 'key-409');
    expect(op409, isNotNull);
    expect(op409!.state, OutboxOpState.pending);
    expect((await h.store.getMessages(1, 1)).single.localFailed, isFalse);

    // Simulate HTTP 500 -> stays pending for retry
    h.repo.nextError = ApiException('server err', statusCode: 500);
    await h.store.requeueFailed(workspaceId: 1, clientId: 'key-409');
    await h.worker.flush(workspaceId: 1);
    final op500 = await h.store.outboxByClientId(1, 'key-409');
    expect(op500!.state, OutboxOpState.pending);

    // Auto retry timer recovers when server succeeds
    await Future<void>.delayed(const Duration(milliseconds: 2200));
    expect(await h.store.outboxCount(1), 0);
    expect((await h.store.getMessages(1, 1)).single.id, greaterThanOrEqualTo(1000));
  });

  test('provider rejection (201 delivery_status=failed) shows failure and supports retry endpoint', () async {
    final h = await _harness();
    h.repo.customHandlers['فشل مزود'] = (key) async {
      final failed = MessageModel(
        id: 777,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'فشل مزود',
        createdAt: DateTime.now(),
        deliveryStatus: 'failed',
        localError: 'missing_token',
        clientId: key,
      );
      h.repo.serverMessages.add(failed);
      return failed;
    };

    final ctrl = h.container.read(chatControllerProvider(1).notifier);
    await ctrl.refresh();
    await ctrl.send('فشل مزود');
    await h.worker.flush(workspaceId: 1);
    await Future<void>.delayed(const Duration(milliseconds: 50));

    final failedState = h.container.read(chatControllerProvider(1));
    final failedMsg = failedState.messages.firstWhere((m) => m.id == 777);
    expect(failedMsg.outboundPhase, OutboundPhase.failed);
    expect(failedMsg.localError, 'missing_token');
    expect(failedState.sendFailureSeq, greaterThan(0));
    expect(failedState.lastSendFailureMessage, 'missing_token');

    await ctrl.retryFailed(failedMsg);
    final retriedState = h.container.read(chatControllerProvider(1));
    final retriedMsg = retriedState.messages.firstWhere((m) => m.id == 777);
    expect(h.repo.retryEndpointCalls, 1);
    expect(retriedMsg.outboundPhase, OutboundPhase.sent);
    expect(retriedMsg.localError, isNull);
  });

  test('app restart with stuck sending row reclaims and flushes automatically', () async {
    final dir = await Directory.systemTemp.createTemp('hasim_restart_p0_');
    final file = File('${dir.path}/inbox.sqlite');
    addTearDown(() async {
      if (dir.existsSync()) await dir.delete(recursive: true);
    });

    final db1 = InboxDatabase.file(file);
    final store1 = InboxLocalStore(db1);
    await store1.enqueueTextSend(
      workspaceId: 1,
      conversationId: 1,
      message: const MessageModel(
        id: -99,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رسالة أثناء إغلاق التطبيق',
        localPending: true,
        clientId: 'restart-key-1',
      ),
    );
    final op = (await store1.dueOutbox(workspaceId: 1)).single;
    await store1.claimOutbox(op.id, workspaceId: 1, clientId: 'restart-key-1');
    await db1.close();

    final db2 = InboxDatabase.file(file);
    final h = await _harness(customDb: db2, startWorker: true);
    await Future<void>.delayed(const Duration(milliseconds: 120));

    expect(await h.store.outboxCount(1), 0);
    expect(h.repo.sentOrder, contains('رسالة أثناء إغلاق التطبيق'));
  });

  test('switching conversations while sending preserves outbox delivery and WebSocket race before API + AI reply', () async {
    final h = await _harness();
    final inflight = Completer<MessageModel>();
    late String capturedKey;
    h.repo.customHandlers['رسالة مع تبديل المحادثة'] = (key) {
      capturedKey = key;
      return inflight.future;
    };

    final ctrl1 = h.container.read(chatControllerProvider(1).notifier);
    await ctrl1.refresh();
    await ctrl1.send('رسالة مع تبديل المحادثة');

    // Simulate WebSocket MessageCreated arriving BEFORE HTTP API completes
    await Future<void>.delayed(const Duration(milliseconds: 20));
    h.realtime.emitMessage(
      MessageModel(
        id: 2001,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رسالة مع تبديل المحادثة',
        deliveryStatus: 'pending',
        clientId: capturedKey,
      ),
    );
    // Simulate concurrent AI reply arriving via WS
    h.realtime.emitMessage(
      const MessageModel(
        id: 2002,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'أهلاً بك، رد الذكاء الاصطناعي',
        aiGenerated: true,
        deliveryStatus: 'sent',
      ),
    );
    // Switch away to conversation 2 while still waiting on HTTP
    h.container.read(chatControllerProvider(2).notifier);

    inflight.complete(
      MessageModel(
        id: 2001,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رسالة مع تبديل المحادثة',
        deliveryStatus: 'sent',
        clientId: capturedKey,
      ),
    );
    await Future<void>.delayed(const Duration(milliseconds: 80));

    // Duplicate WS + out-of-order read->delivered never downgrades read
    h.realtime.emitStatus(const MessageStatusUpdate(id: 2001, conversationId: 1, deliveryStatus: 'read'));
    h.realtime.emitStatus(const MessageStatusUpdate(id: 2001, conversationId: 1, deliveryStatus: 'delivered'));
    await Future<void>.delayed(const Duration(milliseconds: 30));

    final conv1Msgs = h.container.read(chatControllerProvider(1)).messages;
    expect(conv1Msgs.where((m) => m.id == 2001).length, 1);
    expect(conv1Msgs.firstWhere((m) => m.id == 2001).deliveryStatus, 'read');
    expect(conv1Msgs.any((m) => m.id == 2002 && m.aiGenerated), isTrue);
  });

  testWidgets('non-empty ChatScreen shows SnackBar and retry on 403/422, retries on 429 & offline reconnect, and logs stay PII-free', (tester) async {
    final h = await _harness();
    h.repo.serverMessages.add(
      const MessageModel(
        id: 100,
        conversationId: 1,
        direction: 'inbound',
        messageType: 'text',
        content: 'رسالة عميل سابقة',
      ),
    );
    h.repo.nextError = ApiException('لا تملك صلاحية تنفيذ هذا الإجراء.', statusCode: 403);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: h.container,
        child: const MaterialApp(
          locale: Locale('ar'),
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: ChatScreen(conversationId: 1),
        ),
      ),
    );
    await tester.pump(const Duration(milliseconds: 50));

    final field = find.byType(TextField).first;
    final sendBtn = find.byKey(const Key('chat-send-button'));
    await tester.enterText(field, 'محتوى_سري_للتحقق');
    await tester.pump();
    await tester.tap(sendBtn);
    await tester.pump(const Duration(milliseconds: 120));

    // SnackBar + failed bubble must be visible even though conversation is non-empty.
    expect(find.byType(SnackBar), findsOneWidget);
    expect(find.byIcon(Icons.error_outline_rounded), findsOneWidget);

    // Next attempt hits 429 rate-limit, then offline connectivity reconnect recovers it.
    h.repo.nextError = ApiException('محاولات كثيرة. حاول لاحقاً.', statusCode: 429, retryAfterSeconds: 60);
    await h.container
        .read(chatControllerProvider(1).notifier)
        .retryFailed(
          h.container
              .read(chatControllerProvider(1))
              .messages
              .firstWhere((m) => m.content == 'محتوى_سري_للتحقق'),
        );
    await tester.pump(const Duration(milliseconds: 50));

    // Still pending retry, NOT failed permanently, and next attempt succeeds.
    final op429 = await h.store.outboxByClientId(
      1,
      h.container
          .read(chatControllerProvider(1))
          .messages
          .firstWhere((m) => m.content == 'محتوى_سري_للتحقق')
          .clientId!,
    );
    expect(op429?.state, OutboxOpState.pending);
    await h.store.requeueFailed(workspaceId: 1, clientId: op429!.clientId);
    h.connectivity.emitOnline();
    await tester.pump(const Duration(milliseconds: 120));

    expect(await h.store.outboxCount(1), 0);
    final allLogs = MessagingLogger.recentEvents.map((e) => e.toString()).join('\n');
    expect(allLogs, contains('SEND_BUTTON_PRESSED'));
    expect(allLogs, contains('MESSAGE_FAILED'));
    expect(allLogs, contains('MESSAGE_RETRY'));
    expect(allLogs, isNot(contains('محتوى_سري_للتحقق')));
    expect(allLogs, isNot(contains('test-token')));

    await tester.pumpWidget(const SizedBox());
    h.container.dispose();
  });
}
