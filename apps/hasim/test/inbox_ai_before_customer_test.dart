import 'package:fake_async/fake_async.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/realtime/realtime_service.dart';
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

class _ScriptedMessages extends ConversationRepository {
  _ScriptedMessages(super.api);

  List<List<MessageModel>> pages = const [];
  var calls = 0;

  @override
  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? workspaceId,
  }) async {
    final page = pages[calls < pages.length ? calls : pages.length - 1];
    calls++;
    return (items: page, nextCursor: null);
  }
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  const customer = MessageModel(
    id: 21,
    conversationId: 7,
    direction: 'inbound',
    messageType: 'text',
    content: 'مرحبا من العميل',
  );
  final reply = MessageModel(
    id: 22,
    conversationId: 7,
    direction: 'outbound',
    messageType: 'text',
    content: 'رد الذكاء',
    aiGenerated: true,
    createdAt: DateTime.utc(2026, 1, 2, 12),
  );

  test('shared client id does not replace the customer row with the AI reply', () async {
    final db = InboxDatabase.memory();
    final store = InboxLocalStore(db);
    addTearDown(db.close);

    await store.upsertMessages(1, 7, const [
      MessageModel(
        id: 21,
        conversationId: 7,
        direction: 'inbound',
        messageType: 'text',
        content: 'مرحبا من العميل',
        clientId: 'same-key',
      ),
    ]);
    await store.upsertMessages(1, 7, [
      reply.copyWith(clientId: 'same-key'),
    ]);

    final messages = await store.getMessages(1, 7);
    expect(messages.map((m) => m.id).toList(), [21, 22]);
    expect(messages.map((m) => m.content).toList(), [
      'مرحبا من العميل',
      'رد الذكاء',
    ]);
  });

  test('latest window keeps a customer row that has no timestamp', () async {
    final db = InboxDatabase.memory();
    final store = InboxLocalStore(db);
    addTearDown(db.close);

    await store.upsertMessages(1, 7, [reply]);
    await store.upsertMessages(1, 7, [customer]);

    final ids = (await store.getMessages(1, 7)).map((m) => m.id).toList();
    expect(ids, [21, 22]);
  });

  test('poll delivers the customer message after an AI-only snapshot', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final api = isolatedTestApiClient(
      secureStore: _MemorySecureStore(),
      prefsStore: PrefsStore(prefs),
    );
    final repo = _ScriptedMessages(api);
    repo.pages = [
      [reply],
      [reply, customer],
    ];

    fakeAsync((async) {
      final service = PollingRealtimeService(repo);
      final delivered = <int>[];
      service.messageCreated.listen((message) => delivered.add(message.id));

      service.watchConversation(7);
      async.flushMicrotasks();
      expect(delivered, [22]);

      async.elapse(const Duration(seconds: 4));
      async.flushMicrotasks();
      expect(delivered, [22, 21]);
      async.elapse(const Duration(seconds: 4));
      async.flushMicrotasks();
      expect(delivered, [22, 21]);
    });
  });
}
