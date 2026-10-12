import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/hive_inbox_migrator.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/storage/inbox_session.dart';

ConversationModel _conv(int id, String name) {
  return ConversationModel(
    id: id,
    channel: 'whatsapp',
    status: 'open',
    externalId: name,
  );
}

MessageModel _msg(int id, int conversationId, String content) {
  return MessageModel(
    id: id,
    conversationId: conversationId,
    direction: 'inbound',
    messageType: 'text',
    content: content,
  );
}

void main() {
  late InboxDatabase db;
  late InboxLocalStore store;

  setUp(() {
    db = InboxDatabase.memory();
    store = InboxLocalStore(db);
  });

  tearDown(() async {
    await db.close();
  });

  test('database opens and stores a conversation and message', () async {
    await store.upsertConversations(1, [_conv(10, 'Tenant A')]);
    await store.upsertMessages(1, 10, [_msg(1, 10, 'hello')]);

    expect((await store.getConversations(1)).single.externalId, 'Tenant A');
    expect((await store.getMessages(1, 10)).single.content, 'hello');
    expect(await store.getConversation(1, 10), isNotNull);
  });

  test('workspace conversation caches stay isolated', () async {
    await store.upsertConversations(1, [_conv(10, 'Tenant A')]);
    await store.upsertConversations(2, [_conv(20, 'Tenant B')]);

    expect((await store.getConversations(1)).single.externalId, 'Tenant A');
    expect((await store.getConversations(2)).single.externalId, 'Tenant B');
  });

  test('messages are isolated by workspace and conversation id', () async {
    await store.upsertMessages(1, 123, [_msg(1, 123, 'A-123')]);
    await store.upsertMessages(2, 123, [_msg(2, 123, 'B-123')]);

    expect((await store.getMessages(1, 123)).single.content, 'A-123');
    expect((await store.getMessages(2, 123)).single.content, 'B-123');
    expect(await store.getMessages(1, 999), isEmpty);
  });

  test('clearWorkspace removes only that workspace', () async {
    await store.upsertConversations(1, [_conv(10, 'A')]);
    await store.upsertConversations(2, [_conv(20, 'B')]);
    await store.upsertMessages(1, 123, [_msg(1, 123, 'A')]);
    await store.upsertMessages(2, 123, [_msg(2, 123, 'B')]);

    await store.clearWorkspace(1);

    expect(await store.getConversations(1), isEmpty);
    expect(await store.getMessages(1, 123), isEmpty);
    expect((await store.getConversations(2)).single.externalId, 'B');
    expect((await store.getMessages(2, 123)).single.content, 'B');
  });

  test('clearAll removes every inbox row', () async {
    await store.upsertConversations(1, [_conv(10, 'A')]);
    await store.upsertMessages(2, 123, [_msg(2, 123, 'B')]);
    await store.setSyncCursor(1, 'conversations', 'c1');

    await store.clearAll();

    expect(await store.conversationCount(), 0);
    expect(await store.messageCount(), 0);
    expect(await store.outboxCount(), 0);
    expect(await store.getSyncCursor(1, 'conversations'), isNull);
  });

  test('unsafe unscoped Hive keys are not imported', () async {
    final report = await HiveInboxMigrator.importMaps(
      store: store,
      conversations: {
        'list': '[{"id":99,"channel":"web","status":"open","external_id":"LEGACY LIST"}]',
        'workspace:1:conversations':
            '[{"id":10,"channel":"whatsapp","status":"open","external_id":"Scoped A"}]',
      },
      messages: {
        'c_123': '[{"id":99,"conversation_id":123,"direction":"inbound","message_type":"text","content":"LEGACY MSG"}]',
        'workspace:1:conversation:5:messages':
            '[{"id":1,"conversation_id":5,"direction":"inbound","message_type":"text","content":"scoped"}]',
      },
    );

    expect(report.discardedKeys, 2);
    expect((await store.getConversations(1)).single.externalId, 'Scoped A');
    expect(await store.getMessages(1, 123), isEmpty);
    expect((await store.getMessages(1, 5)).single.content, 'scoped');
  });

  test('assigned conversation and its message stay visible until logout clears the store', () async {
    const assigned = ConversationModel(
      id: 41,
      channel: 'whatsapp',
      status: 'open',
      externalId: 'assigned-chat',
      assignedUserId: 8,
      assignedWorkerEngagementId: 6,
    );
    await store.upsertConversations(2, [assigned]);
    await store.upsertMessages(2, 41, [_msg(9, 41, 'سؤال العميل')]);

    final rows = await store.getConversations(2);
    expect(rows.single.assignedUserId, 8);
    expect(rows.single.assignedWorkerEngagementId, 6);
    expect((await store.getMessages(2, 41)).single.content, 'سؤال العميل');
    expect(await store.getConversations(1), isEmpty);

    await store.clearAll();

    expect(await store.getConversations(2), isEmpty);
    expect(await store.getMessages(2, 41), isEmpty);
  });

  test('InboxSession still rejects stale workspace generations', () {
    final session = InboxSession();
    session.beginWorkspace(1);
    final scopeA = InboxScope(generation: session.generation, workspaceId: 1);

    expect(session.isCurrent(generation: scopeA.generation, workspaceId: 1), isTrue);
    session.beginWorkspace(2);
    expect(session.isCurrent(generation: scopeA.generation, workspaceId: 1), isFalse);
  });
}
