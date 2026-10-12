import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/domain_local_store.dart';
import 'package:hasim/core/local_db/feed_local_store.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/local_db/outbox_worker.dart';
import 'package:hasim/core/local_db/settings_local_store.dart';
import 'package:hasim/core/local_db/worker_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/email/data/email_repository.dart';
import 'package:hasim/features/email/providers/email_controller.dart';
import 'package:hasim/features/stories/data/story_repository.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('company rows from one workspace are not readable as another', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final inbox = InboxLocalStore(db);
    final domain = DomainLocalStore(db);
    final feed = FeedLocalStore(db);
    final settings = SettingsLocalStore(db);

    await inbox.upsertConversations(4, const [
      ConversationModel(
        id: 1,
        channel: 'whatsapp',
        status: 'open',
        externalId: 'عميل أ',
      ),
    ]);
    await inbox.upsertConversations(9, const [
      ConversationModel(
        id: 2,
        channel: 'email',
        status: 'open',
        externalId: 'عميل ب',
      ),
    ]);
    await domain.upsertEmails(4, 'inbox', const [
      EmailMessageModel(
        id: 1,
        sender: 'a@shop.test',
        recipient: 'me',
        subject: 'بريد أ',
      ),
    ]);
    await domain.upsertEmails(9, 'inbox', const [
      EmailMessageModel(
        id: 2,
        sender: 'b@shop.test',
        recipient: 'me',
        subject: 'بريد ب',
      ),
    ]);
    final now = DateTime.now();
    await feed.upsertStories(4, [
      StoryModel(
        id: 1,
        type: 'text',
        bodyText: 'قصة أ',
        expiresAt: now.add(const Duration(hours: 2)),
      ),
    ]);
    await feed.upsertStories(9, [
      StoryModel(
        id: 2,
        type: 'text',
        bodyText: 'قصة ب',
        expiresAt: now.add(const Duration(hours: 2)),
      ),
    ]);
    await settings.put(4, 'channels', [
      {
        'key': 'whatsapp',
        'name': 'واتساب',
        'connected': true,
        'status': 'connected',
      },
    ]);
    await settings.put(9, 'channels', [
      {
        'key': 'email',
        'name': 'بريد',
        'connected': false,
        'status': 'disconnected',
      },
    ]);

    expect((await inbox.getConversations(4)).single.externalId, 'عميل أ');
    expect((await inbox.getConversations(9)).single.externalId, 'عميل ب');
    expect((await domain.emails(4, folder: 'inbox')).single.subject, 'بريد أ');
    expect((await domain.emails(9, folder: 'inbox')).single.subject, 'بريد ب');
    expect((await feed.stories(4)).single.bodyText, 'قصة أ');
    expect((await feed.stories(9)).single.bodyText, 'قصة ب');
    expect(
      (await settings.get(4, 'channels')).toString(),
      contains('whatsapp'),
    );
    expect(
      (await settings.get(9, 'channels')).toString(),
      isNot(contains('whatsapp')),
    );
  });

  test(
    'worker rows stay on the profile and engagement, and logout clears the session',
    () async {
      final db = InboxDatabase.memory();
      addTearDown(db.close);
      final workers = WorkerLocalStore(db);
      final inbox = InboxLocalStore(db);
      await workers.put(15, 'products', [
        {'id': 1, 'name': 'منتج الارتباط'},
      ], scopeId: 10);
      await workers.put(15, 'products', [
        {'id': 2, 'name': 'ارتباط آخر'},
      ], scopeId: 11);
      await workers.put(16, 'products', [
        {'id': 3, 'name': 'عامل آخر'},
      ], scopeId: 10);
      await inbox.saveAccountSnapshot(
        const LocalAccountSnapshot(
          user: UserModel(id: 3, name: 'عامل'),
          participant: MobileParticipant(
            type: 'independent_worker',
            workerProfileId: 15,
          ),
          workspaces: [],
        ),
      );

      expect(
        (await workers.get(15, 'products', scopeId: 10)).toString(),
        contains('منتج الارتباط'),
      );
      expect(
        (await workers.get(15, 'products', scopeId: 11)).toString(),
        contains('ارتباط آخر'),
      );
      expect(
        (await workers.get(16, 'products', scopeId: 10)).toString(),
        isNot(contains('منتج الارتباط')),
      );
      final account = await inbox.loadAccountSnapshot();
      expect(account!.participant.isIndependentWorker, isTrue);
      expect(account.participant.workerProfileId, 15);
      expect(account.workspaces, isEmpty);

      await inbox.clearAll();
      expect(await workers.get(15, 'products', scopeId: 10), isNull);
      expect(await inbox.loadAccountSnapshot(), isNull);
      expect(await inbox.outboxCount(), 0);
    },
  );

  test(
    'worker-assigned conversation messages stay scoped and deduplicated locally',
    () async {
      final db = InboxDatabase.memory();
      addTearDown(db.close);
      final inbox = InboxLocalStore(db);
      final createdAt = DateTime(2026, 10, 6, 3, 30);

      await inbox.upsertConversations(4, const [
        ConversationModel(
          id: 7,
          channel: 'whatsapp',
          status: 'open',
          assignedUserId: 44,
          assignedWorkerEngagementId: 22,
          assignedParticipantType: 'independent_worker',
        ),
      ]);
      await inbox.upsertMessages(4, 7, [
        MessageModel(
          id: 31,
          conversationId: 7,
          direction: 'inbound',
          messageType: 'text',
          content: 'أول نسخة',
          createdAt: createdAt,
          workerEngagementId: 22,
          participantType: 'customer',
        ),
      ]);
      await inbox.upsertMessages(4, 7, [
        MessageModel(
          id: 31,
          conversationId: 7,
          direction: 'inbound',
          messageType: 'text',
          content: 'النسخة المحدثة',
          createdAt: createdAt,
          workerEngagementId: 22,
          participantType: 'customer',
        ),
      ]);

      final conversation = (await inbox.getConversations(4)).single;
      final messages = await inbox.getMessages(4, 7);

      expect(conversation.assignedWorkerEngagementId, 22);
      expect(conversation.assignedParticipantType, 'independent_worker');
      expect(messages, hasLength(1));
      expect(messages.single.content, 'النسخة المحدثة');
      expect(messages.single.workerEngagementId, 22);
      expect(await inbox.getConversations(9), isEmpty);
      expect(await inbox.getMessages(9, 7), isEmpty);
    },
  );

  test('outbox sends only the current workspace text queue', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    final session = InboxSession()..beginWorkspace(4);
    final api = await _api();
    final repo = _SendCounter(api);
    final worker = OutboxWorker(
      store: store,
      repository: repo,
      session: session,
    );
    await store.enqueueTextSend(
      workspaceId: 4,
      conversationId: 7,
      message: _pending('keep-4'),
    );
    await store.enqueueTextSend(
      workspaceId: 9,
      conversationId: 7,
      message: _pending('keep-9'),
    );
    final now = DateTime.now().millisecondsSinceEpoch;
    await db.custom(
      '''
      INSERT INTO inbox_outbox (
        workspace_id, client_id, operation_type, conversation_server_id, payload,
        state, attempts, next_attempt_at, created_at, updated_at
      ) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)
      ''',
      [4, 'disconnect-1', 'disconnect', 7, '{}', 'pending', now, now, now],
    );

    session.clear();
    await worker.flush();
    expect(repo.calls, 0);
    expect(await store.outboxCount(9), 1);

    session.beginWorkspace(4);
    await worker.flush(workspaceId: 9);
    expect(repo.calls, 0);

    await worker.flush(workspaceId: 4);
    expect(repo.calls, 1);
    expect((await store.outboxByClientId(4, 'disconnect-1'))?.state, 'failed');
    expect(await store.outboxByClientId(4, 'keep-4'), isNull);
    expect(await store.outboxCount(9), 1);
  });

  test(
    'email and stories drop the previous workspace before the refresh returns',
    () async {
      SharedPreferences.setMockInitialValues({'workspace_id': 4});
      final rawPrefs = await SharedPreferences.getInstance();
      final prefs = PrefsStore(rawPrefs);
      final api = isolatedTestApiClient(
        secureStore: _MemorySecure(),
        prefsStore: prefs,
      );
      final db = InboxDatabase.memory();
      addTearDown(db.close);
      final domain = DomainLocalStore(db);
      final feed = FeedLocalStore(db);
      final now = DateTime.now();
      await domain.upsertEmails(4, 'inbox', const [
        EmailMessageModel(
          id: 1,
          sender: 'a@shop.test',
          recipient: 'me',
          subject: 'بريد أ',
        ),
      ]);
      await domain.upsertEmails(9, 'inbox', const [
        EmailMessageModel(
          id: 2,
          sender: 'b@shop.test',
          recipient: 'me',
          subject: 'بريد ب',
        ),
      ]);
      await feed.upsertStories(4, [
        StoryModel(
          id: 1,
          type: 'text',
          bodyText: 'قصة أ',
          expiresAt: now.add(const Duration(hours: 2)),
        ),
      ]);
      await feed.upsertStories(9, [
        StoryModel(
          id: 2,
          type: 'text',
          bodyText: 'قصة ب',
          expiresAt: now.add(const Duration(hours: 2)),
        ),
      ]);

      late _SwitchableAuth auth;
      final container = ProviderContainer(
        overrides: [
          sharedPrefsProvider.overrideWithValue(rawPrefs),
          secureStoreProvider.overrideWithValue(_MemorySecure()),
          apiClientProvider.overrideWithValue(api),
          inboxDatabaseProvider.overrideWithValue(db),
          authControllerProvider.overrideWith((ref) {
            auth = _SwitchableAuth(ref);
            return auth;
          }),
          emailRepositoryProvider.overrideWithValue(
            EmailRepository(
              api,
              local: domain,
              workspaceIdOf: () => prefs.workspaceId,
            ),
          ),
          storyRepositoryProvider.overrideWithValue(
            StoryRepository(
              api,
              local: feed,
              workspaceIdOf: () => prefs.workspaceId,
            ),
          ),
        ],
      );
      addTearDown(container.dispose);

      container.read(emailControllerProvider);
      container.read(storiesControllerProvider);
      await _until(
        () => container
            .read(emailControllerProvider)
            .items
            .any((item) => item.subject == 'بريد أ'),
      );
      await _until(
        () => container
            .read(storiesControllerProvider)
            .buckets
            .expand((bucket) => bucket.stories)
            .any((story) => story.bodyText == 'قصة أ'),
      );

      await prefs.setWorkspaceId(9);
      auth.use(9);
      expect(container.read(emailControllerProvider).items, isEmpty);
      expect(container.read(storiesControllerProvider).buckets, isEmpty);

      await _until(
        () => container
            .read(emailControllerProvider)
            .items
            .any((item) => item.subject == 'بريد ب'),
      );
      await _until(
        () => container
            .read(storiesControllerProvider)
            .buckets
            .expand((bucket) => bucket.stories)
            .any((story) => story.bodyText == 'قصة ب'),
      );
      expect(
        container
            .read(emailControllerProvider)
            .items
            .any((item) => item.subject == 'بريد أ'),
        isFalse,
      );
      expect(
        container
            .read(storiesControllerProvider)
            .buckets
            .expand((bucket) => bucket.stories)
            .any((story) => story.bodyText == 'قصة أ'),
        isFalse,
      );
    },
  );
}

Future<void> _until(bool Function() ready) async {
  for (var i = 0; i < 40; i++) {
    if (ready()) return;
    await Future<void>.delayed(const Duration(milliseconds: 25));
  }
  fail('timed out waiting for local data');
}

MessageModel _pending(String clientId) {
  return MessageModel(
    id: -1,
    conversationId: 7,
    direction: 'outbound',
    messageType: 'text',
    content: clientId,
    createdAt: DateTime.now(),
    localPending: true,
    clientId: clientId,
  );
}

Future<ApiClient> _api() async {
  SharedPreferences.setMockInitialValues({});
  final prefs = PrefsStore(await SharedPreferences.getInstance());
  return isolatedTestApiClient(secureStore: _MemorySecure(), prefsStore: prefs);
}

class _SendCounter extends ConversationRepository {
  _SendCounter(super.api);

  int calls = 0;

  @override
  Future<MessageModel> sendMessageWithKey(
    int conversationId,
    String content,
    String key,
  ) async {
    calls++;
    return MessageModel(
      id: 1,
      conversationId: conversationId,
      direction: 'outbound',
      messageType: 'text',
      content: content,
      createdAt: DateTime.now(),
      clientId: key,
    );
  }
}

class _SwitchableAuth extends AuthController {
  _SwitchableAuth(super.ref);

  @override
  Future<void> bootstrap() async {
    use(4);
  }

  void use(int workspaceId) {
    state = AuthState(
      bootstrapping: false,
      token: 'saved-token',
      user: const UserModel(id: 1, name: 'موظف'),
      workspace: WorkspaceModel(id: workspaceId, name: 'مساحة $workspaceId'),
      participant: const MobileParticipant(type: 'company_staff'),
    );
  }
}

class _MemorySecure extends SecureStore {
  @override
  Future<String?> readToken() async => 'saved-token';
  @override
  Future<void> saveToken(String token) async {}
  @override
  Future<void> clearToken() async {}
}
