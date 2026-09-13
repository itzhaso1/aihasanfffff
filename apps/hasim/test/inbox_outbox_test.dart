import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/outbox_worker.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _MemorySecureStore extends SecureStore {
  @override
  Future<void> saveToken(String token) async {}
  @override
  Future<String?> readToken() async => null;
  @override
  Future<void> clearToken() async {}
}

class _SlowSendRepository extends ConversationRepository {
  _SlowSendRepository(super.api);

  final started = Completer<void>();
  final release = Completer<void>();
  int calls = 0;

  @override
  Future<MessageModel> sendMessageWithKey(int conversationId, String content, String key) async {
    calls++;
    if (!started.isCompleted) started.complete();
    await release.future;
    return MessageModel(
      id: 700,
      conversationId: conversationId,
      direction: 'outbound',
      messageType: 'text',
      content: content,
      createdAt: DateTime.now(),
      clientId: key,
    );
  }
}

class _RecordingSendRepository extends ConversationRepository {
  _RecordingSendRepository(super.api);

  final keys = <String>[];
  Object? error;
  int calls = 0;

  @override
  Future<MessageModel> sendMessageWithKey(int conversationId, String content, String key) async {
    calls++;
    keys.add(key);
    if (error != null) throw error!;
    return MessageModel(
      id: 500,
      conversationId: conversationId,
      direction: 'outbound',
      messageType: 'text',
      content: content,
      createdAt: DateTime.now(),
      clientId: key,
    );
  }
}

MessageModel _optimistic(String clientId) {
  return MessageModel(
    id: -1,
    conversationId: 7,
    direction: 'outbound',
    messageType: 'text',
    content: 'مرحبا',
    createdAt: DateTime.now(),
    localPending: true,
    clientId: clientId,
  );
}

Future<ApiClient> _api() async {
  SharedPreferences.setMockInitialValues({});
  final prefs = PrefsStore(await SharedPreferences.getInstance());
  return ApiClient(secureStore: _MemorySecureStore(), prefsStore: prefs);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late InboxDatabase db;
  late InboxLocalStore store;

  setUp(() {
    db = InboxDatabase.memory();
    store = InboxLocalStore(db);
  });

  tearDown(() async {
    await db.close();
  });

  test('offline send creates message and outbox atomically', () async {
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-1'),
    );

    expect(await store.messageCount(1), 1);
    expect(await store.outboxCount(1), 1);
    expect((await store.getMessages(1, 7)).single.localPending, isTrue);
    expect((await store.dueOutbox(workspaceId: 1)).single.clientId, 'client-1');
  });

  test('duplicate enqueue reuses the same clientId and does not create a second op', () async {
    final message = _optimistic('client-dup');
    await store.enqueueTextSend(workspaceId: 1, conversationId: 7, message: message);
    await store.enqueueTextSend(workspaceId: 1, conversationId: 7, message: message);

    expect(await store.outboxCount(1), 1);
    expect(await store.messageCount(1), 1);
  });

  test('pending send survives process restart', () async {
    final file = File(
      '${Directory.systemTemp.path}/hasim_outbox_${DateTime.now().microsecondsSinceEpoch}.sqlite',
    );
    addTearDown(() {
      if (file.existsSync()) file.deleteSync();
    });

    final first = InboxDatabase.file(file);
    final firstStore = InboxLocalStore(first);
    await firstStore.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-restart'),
    );
    await first.close();

    final second = InboxDatabase.file(file);
    addTearDown(second.close);
    final secondStore = InboxLocalStore(second);
    final due = await secondStore.dueOutbox(workspaceId: 1);
    expect(due.single.clientId, 'client-restart');
    expect((await secondStore.getMessages(1, 7)).single.localPending, isTrue);
  });

  test('success reconciles server_id, keeps clientId, and removes outbox', () async {
    final api = await _api();
    final repo = _RecordingSendRepository(api);
    final session = InboxSession()..beginWorkspace(1);
    final worker = OutboxWorker(store: store, repository: repo, session: session);

    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-ok'),
    );
    await worker.flush(workspaceId: 1);

    expect(repo.calls, 1);
    expect(repo.keys, ['client-ok']);
    expect(await store.outboxCount(1), 0);
    final message = (await store.getMessages(1, 7)).single;
    expect(message.id, 500);
    expect(message.clientId, 'client-ok');
    expect(message.localPending, isFalse);
    expect(message.localFailed, isFalse);
  });

  test('retry reuses the same clientId', () async {
    final api = await _api();
    final repo = _RecordingSendRepository(api);
    repo.error = ApiException('timeout');
    final session = InboxSession()..beginWorkspace(1);
    final worker = OutboxWorker(store: store, repository: repo, session: session);

    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-retry'),
    );
    await worker.flush(workspaceId: 1);
    expect(await store.outboxCount(1), 1);

    repo.error = null;
    await store.requeueFailed(workspaceId: 1, clientId: 'client-retry');
    await worker.flush(workspaceId: 1);

    expect(repo.keys, ['client-retry', 'client-retry']);
    expect(await store.outboxCount(1), 0);
  });

  test('validation failure stays durable and failed', () async {
    final api = await _api();
    final repo = _RecordingSendRepository(api);
    repo.error = ApiException('invalid', statusCode: 422);
    final session = InboxSession()..beginWorkspace(1);
    final worker = OutboxWorker(store: store, repository: repo, session: session);

    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-fail'),
    );
    await worker.flush(workspaceId: 1);

    expect((await store.getMessages(1, 7)).single.localFailed, isTrue);
    expect((await store.outboxByClientId(1, 'client-fail'))?.state, OutboxOpState.failed);
  });

  test('claiming an outbox row prevents duplicate processing', () async {
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-claim'),
    );
    final op = (await store.dueOutbox(workspaceId: 1)).single;
    expect(await store.claimOutbox(op.id), isTrue);
    expect(await store.claimOutbox(op.id), isFalse);
  });

  test('duplicate server reconcile does not create a second message', () async {
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-merge'),
    );
    await store.upsertMessages(1, 7, [
      const MessageModel(
        id: 500,
        conversationId: 7,
        direction: 'outbound',
        messageType: 'text',
        content: 'مرحبا',
      ),
    ]);

    await store.completeOutbox(
      workspaceId: 1,
      clientId: 'client-merge',
      server: const MessageModel(
        id: 500,
        conversationId: 7,
        direction: 'outbound',
        messageType: 'text',
        content: 'مرحبا',
        clientId: 'client-merge',
      ),
    );

    final messages = await store.getMessages(1, 7);
    expect(messages, hasLength(1));
    expect(messages.single.id, 500);
    expect(messages.single.clientId, 'client-merge');
  });

  test('lost HTTP response then retry keeps one local row and the same clientId', () async {
    final api = await _api();
    final repo = _RecordingSendRepository(api);
    repo.error = ApiException('تعذر الاتصال بالخادم.');
    final session = InboxSession()..beginWorkspace(1);
    final worker = OutboxWorker(store: store, repository: repo, session: session);

    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('lost-key'),
    );
    await worker.flush(workspaceId: 1);

    await store.upsertMessages(1, 7, [
      const MessageModel(
        id: 900,
        conversationId: 7,
        direction: 'outbound',
        messageType: 'text',
        content: 'مرحبا',
        clientId: 'lost-key',
      ),
    ]);
    expect(await store.getMessages(1, 7), hasLength(1));
    expect((await store.getMessages(1, 7)).single.id, 900);
    expect((await store.getMessages(1, 7)).single.clientId, 'lost-key');

    repo.error = null;
    await store.requeueFailed(workspaceId: 1, clientId: 'lost-key');
    await worker.flush(workspaceId: 1);

    final messages = await store.getMessages(1, 7);
    expect(messages, hasLength(1));
    expect(messages.single.id, 500);
    expect(messages.single.clientId, 'lost-key');
    expect(messages.single.localPending, isFalse);
    expect(await store.outboxCount(1), 0);
    expect(repo.keys, ['lost-key', 'lost-key']);
  });

  test('concurrent flush does not send the same outbox row twice', () async {
    final api = await _api();
    final repo = _SlowSendRepository(api);
    final session = InboxSession()..beginWorkspace(1);
    final worker = OutboxWorker(store: store, repository: repo, session: session);

    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-once'),
    );

    final first = worker.flush(workspaceId: 1);
    await repo.started.future;
    final second = worker.flush(workspaceId: 1);
    repo.release.complete();
    await Future.wait([first, second]);

    expect(repo.calls, 1);
    expect(await store.outboxCount(1), 0);
    expect((await store.getMessages(1, 7)).single.clientId, 'client-once');
  });

  test('startup reclaim moves sending rows back to pending', () async {
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-stuck'),
    );
    final op = (await store.dueOutbox(workspaceId: 1)).single;
    expect(await store.claimOutbox(op.id), isTrue);
    expect(await store.dueOutbox(workspaceId: 1), isEmpty);

    await store.reclaimSendingOutbox();
    expect((await store.dueOutbox(workspaceId: 1)).single.clientId, 'client-stuck');
  });

  test('temporary 503 stays pending while 403 is permanent', () async {
    final api = await _api();
    final repo = _RecordingSendRepository(api);
    final session = InboxSession()..beginWorkspace(1);
    final worker = OutboxWorker(store: store, repository: repo, session: session);

    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('client-503'),
    );
    repo.error = ApiException('server', statusCode: 503);
    await worker.flush(workspaceId: 1);
    expect((await store.outboxByClientId(1, 'client-503'))?.state, OutboxOpState.pending);
    expect((await store.getMessages(1, 7)).single.localPending, isTrue);

    await store.requeueFailed(workspaceId: 1, clientId: 'client-503');
    repo.error = ApiException('forbidden', statusCode: 403);
    await worker.flush(workspaceId: 1);
    expect((await store.outboxByClientId(1, 'client-503'))?.state, OutboxOpState.failed);
    expect((await store.getMessages(1, 7)).single.localFailed, isTrue);
  });

  test('outbox rows stay workspace isolated', () async {
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _optimistic('a'),
    );
    await store.enqueueTextSend(
      workspaceId: 2,
      conversationId: 7,
      message: _optimistic('b'),
    );

    expect((await store.dueOutbox(workspaceId: 1)).single.clientId, 'a');
    expect((await store.dueOutbox(workspaceId: 2)).single.clientId, 'b');
  });
}
