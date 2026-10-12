import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/conversation_reconciler.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/message_reconciler.dart';
import 'package:hasim/core/models/models.dart';

ConversationModel _conv(
  int id, {
  required String name,
  int unread = 0,
  DateTime? lastMessageAt,
}) {
  return ConversationModel(
    id: id,
    channel: 'whatsapp',
    status: 'open',
    externalId: name,
    unreadCount: unread,
    lastMessageAt: lastMessageAt,
  );
}

MessageModel _msg(
  int id,
  int conversationId,
  String content, {
  String? clientId,
  bool pending = false,
  bool failed = false,
  DateTime? createdAt,
}) {
  return MessageModel(
    id: id,
    conversationId: conversationId,
    direction: id < 0 ? 'outbound' : 'inbound',
    messageType: 'text',
    content: content,
    createdAt: createdAt,
    localPending: pending,
    localFailed: failed,
    clientId: clientId,
  );
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

  test('MessageModel reads client_id from the existing API fields', () {
    final fromClient = MessageModel.fromJson({
      'id': 8,
      'conversation_id': 3,
      'direction': 'outbound',
      'message_type': 'text',
      'content': 'hi',
      'client_id': 'abc',
    });
    final fromIdempotency = MessageModel.fromJson({
      'id': 8,
      'conversation_id': 3,
      'direction': 'outbound',
      'message_type': 'text',
      'content': 'hi',
      'idempotency_key': 'abc',
    });
    expect(fromClient.clientId, 'abc');
    expect(fromIdempotency.clientId, 'abc');
  });

  test('optimistic row becomes the canonical server row by clientId', () async {
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _msg(-1, 7, 'مرحبا', clientId: 'abc', pending: true),
    );
    await store.upsertMessages(1, 7, [
      _msg(40, 7, 'مرحبا', clientId: 'abc'),
    ]);

    final messages = await store.getMessages(1, 7);
    expect(messages, hasLength(1));
    expect(messages.single.id, 40);
    expect(messages.single.clientId, 'abc');
    expect(messages.single.localPending, isFalse);
  });

  test('server duplicate and send-response duplicate do not append a second row', () async {
    const server = MessageModel(
      id: 11,
      conversationId: 4,
      direction: 'inbound',
      messageType: 'text',
      content: 'once',
    );
    await store.upsertMessages(1, 4, [server]);
    await store.upsertMessages(1, 4, [server, server]);
    expect(await store.getMessages(1, 4), hasLength(1));
  });

  test('polling without the optimistic message keeps pending and failed rows', () async {
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _msg(-2, 7, 'pending', clientId: 'keep-me', pending: true),
    );
    await store.upsertMessages(1, 7, [
      _msg(-3, 7, 'failed', clientId: 'fail-me', failed: true),
    ]);
    await store.upsertMessages(1, 7, [
      _msg(20, 7, 'older inbound'),
    ]);

    final messages = await store.getMessages(1, 7);
    expect(messages.where((m) => m.clientId == 'keep-me').single.localPending, isTrue);
    expect(messages.where((m) => m.clientId == 'fail-me').single.localFailed, isTrue);
    expect(messages.where((m) => m.id == 20), hasLength(1));
    expect(messages, hasLength(3));
  });

  test('polling then reconciling the same clientId updates the pending row', () async {
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 7,
      message: _msg(-4, 7, 'later', clientId: 'later', pending: true),
    );
    await store.upsertMessages(1, 7, [_msg(21, 7, 'older inbound')]);
    expect((await store.getMessages(1, 7)).where((m) => m.clientId == 'later').single.localPending, isTrue);

    await store.upsertMessages(1, 7, [
      _msg(55, 7, 'later', clientId: 'later'),
    ]);
    final reconciled = (await store.getMessages(1, 7)).where((m) => m.clientId == 'later').single;
    expect(reconciled.id, 55);
    expect(reconciled.localPending, isFalse);
    expect(await store.getMessages(1, 7), hasLength(2));
  });

  test('pagination plus polling keeps loaded rows and does not duplicate', () async {
    final t1 = DateTime.utc(2026, 1, 1, 12);
    final t2 = DateTime.utc(2026, 1, 1, 11);
    final t3 = DateTime.utc(2026, 1, 1, 10);
    await store.upsertConversations(1, [
      _conv(1, name: 'one', lastMessageAt: t1),
      _conv(2, name: 'two', lastMessageAt: t2),
    ]);
    await store.upsertConversations(1, [
      _conv(3, name: 'three', lastMessageAt: t3),
    ]);
    await store.upsertConversations(1, [
      _conv(1, name: 'one-updated', lastMessageAt: t1.add(const Duration(minutes: 1))),
    ]);

    final items = await store.getConversations(1);
    expect(items.map((c) => c.id).toSet(), {1, 2, 3});
    expect(items.where((c) => c.id == 1).single.externalId, 'one-updated');
    expect(items.first.id, 1);
    expect(
      ConversationReconciler.unionById(items, [_conv(2, name: 'two')]).map((c) => c.id).toSet(),
      {1, 2, 3},
    );
  });

  test('sync cursors stay workspace and resource scoped', () async {
    await store.setSyncCursor(1, ConversationReconciler.syncResource(filter: 'all'), 'cursor-a');
    await store.setSyncCursor(1, ConversationReconciler.messagesResource(9), 'msg-a');
    await store.setSyncCursor(2, ConversationReconciler.syncResource(filter: 'all'), 'cursor-b');

    expect(await store.getSyncCursor(1, ConversationReconciler.syncResource(filter: 'all')), 'cursor-a');
    expect(await store.getSyncCursor(2, ConversationReconciler.syncResource(filter: 'all')), 'cursor-b');
    expect(await store.getSyncCursor(1, ConversationReconciler.messagesResource(9)), 'msg-a');
    expect(await store.getSyncCursor(1, ConversationReconciler.syncResource(filter: 'unread')), isNull);
  });

  test('MessageReconciler does not clear pending without a server id', () {
    expect(
      MessageReconciler.localStateFor(
        existingState: MessageLocalState.pending,
        incoming: _msg(-1, 1, 'x', clientId: 'c'),
      ),
      MessageLocalState.pending,
    );
    expect(
      MessageReconciler.localStateFor(
        existingState: MessageLocalState.failed,
        incoming: _msg(9, 1, 'x', clientId: 'c'),
      ),
      MessageLocalState.sent,
    );
  });
}
