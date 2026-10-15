import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/data/home_repository.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

const _workspace = WorkspaceModel(id: 1, name: 'Workspace A');
const _user = UserModel(id: 9, name: 'Tester');

ConversationModel _conv(
  int id, {
  required String name,
  int unread = 0,
  DateTime? at,
  int? lastMessageId,
  String lastMessageDirection = 'inbound',
  int? assignedUserId,
  int? assignedWorkerEngagementId,
}) {
  return ConversationModel(
    id: id,
    channel: 'whatsapp',
    status: 'open',
    externalId: name,
    unreadCount: unread,
    lastMessageAt: at ?? DateTime.utc(2026, 1, 1, 12),
    assignedUserId: assignedUserId,
    assignedWorkerEngagementId: assignedWorkerEngagementId,
    lastMessage: lastMessageId == null
        ? null
        : MessageModel(
            id: lastMessageId,
            conversationId: id,
            direction: lastMessageDirection,
            messageType: 'text',
            content: name,
          ),
  );
}

class _MemorySecureStore extends SecureStore {
  String? _token;
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
  Future<LoginResult> login({
    required String emailOrPhone,
    required String password,
    int? workspaceId,
    String deviceName = 'حاسم Flutter',
    String deviceType = 'mobile',
  }) async {
    return LoginResult(
      token: 'token-a',
      user: _user,
      workspace: _workspace,
      workspaces: const [_workspace],
      participant: const MobileParticipant(type: 'company_staff'),
    );
  }

  @override
  Future<void> logout() async {}

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
      user: _user,
      workspace: _workspace,
      workspaces: const [_workspace],
      participant: const MobileParticipant(type: 'company_staff'),
    );
  }
}

class _PagedRepo extends ConversationRepository {
  _PagedRepo(super.api, this._store) : super(local: _store);

  final InboxLocalStore _store;
  bool markReadShouldFail = false;
  int markReadCalls = 0;
  List<MessageModel> remoteMessages = const [];
  final page1 = [
    _conv(1, name: 'one', unread: 2, at: DateTime.utc(2026, 1, 1, 12)),
    _conv(2, name: 'two', unread: 0, at: DateTime.utc(2026, 1, 1, 11)),
  ];
  final page2 = [
    _conv(3, name: 'three', unread: 1, at: DateTime.utc(2026, 1, 1, 10)),
  ];

  @override
  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    String? cursor,
    int? workspaceId,
  }) async {
    final items = cursor == 'next-1' ? page2 : page1;
    if (workspaceId != null) {
      await _store.upsertConversations(workspaceId, items);
    }
    return (items: items, nextCursor: cursor == null ? 'next-1' : null);
  }

  @override
  Future<void> markRead(
    int conversationId, {
    int? messageId,
    int? workspaceId,
  }) async {
    markReadCalls++;
    if (markReadShouldFail) {
      throw Exception('mark-read failed');
    }
    if (workspaceId != null) {
      await _store.markConversationRead(workspaceId, conversationId);
    }
  }

  @override
  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? workspaceId,
  }) async {
    if (remoteMessages.isNotEmpty) {
      return (items: remoteMessages, nextCursor: null);
    }
    return super.messages(
      conversationId,
      cursor: cursor,
      workspaceId: workspaceId,
    );
  }
}

class _FakeHomeRepository extends HomeRepository {
  _FakeHomeRepository(super.api);

  HomeSnapshot snapshot = const HomeSnapshot(unreadConversations: 5);

  @override
  Future<HomeSnapshot> home() async => snapshot;
}

class _Harness {
  _Harness({
    required this.container,
    required this.db,
    required this.store,
    required this.repo,
  });

  final ProviderContainer container;
  final InboxDatabase db;
  final InboxLocalStore store;
  final _PagedRepo repo;
}

Future<_Harness> _open({bool login = true}) async {
  SharedPreferences.setMockInitialValues({});
  final rawPrefs = await SharedPreferences.getInstance();
  final prefs = PrefsStore(rawPrefs);
  final secure = _MemorySecureStore();
  final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
  final db = InboxDatabase.memory();
  final store = InboxLocalStore(db);
  final repo = _PagedRepo(api, store);
  final container = ProviderContainer(
    overrides: [
      sharedPrefsProvider.overrideWithValue(rawPrefs),
      secureStoreProvider.overrideWithValue(secure),
      apiClientProvider.overrideWithValue(api),
      inboxDatabaseProvider.overrideWithValue(db),
      inboxLocalStoreProvider.overrideWithValue(store),
      inboxSessionProvider.overrideWithValue(InboxSession()..beginWorkspace(1)),
      authRepositoryProvider.overrideWithValue(_FakeAuthRepository(api)),
      conversationRepositoryProvider.overrideWithValue(repo),
      homeRepositoryProvider.overrideWithValue(_FakeHomeRepository(api)),
      realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
    ],
  );
  if (login) {
    final auth = container.read(authControllerProvider.notifier);
    for (var i = 0; i < 40; i++) {
      if (!container.read(authControllerProvider).bootstrapping) break;
      await Future<void>.delayed(const Duration(milliseconds: 5));
    }
    await auth.login('a@example.com', 'secret');
  }
  return _Harness(container: container, db: db, store: store, repo: repo);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _Harness h;

  setUp(() async {
    h = await _open();
  });

  tearDown(() async {
    h.container.dispose();
    await h.db.close();
  });

  Future<void> waitUntil(bool Function() ready) async {
    for (var i = 0; i < 50; i++) {
      if (ready()) return;
      await Future<void>.delayed(const Duration(milliseconds: 20));
    }
  }

  test(
    'load more then polling keeps page-2 rows and the load-more cursor',
    () async {
      h.container.read(conversationsControllerProvider);
      await waitUntil(
        () =>
            h.container.read(conversationsControllerProvider).items.length >= 2,
      );
      expect(
        h.container
            .read(conversationsControllerProvider)
            .items
            .map((c) => c.id),
        [1, 2],
      );
      expect(
        h.container.read(conversationsControllerProvider).nextCursor,
        'next-1',
      );

      await h.container
          .read(conversationsControllerProvider.notifier)
          .loadMore();
      expect(
        h.container
            .read(conversationsControllerProvider)
            .items
            .map((c) => c.id),
        [1, 2, 3],
      );
      expect(
        h.container.read(conversationsControllerProvider).nextCursor,
        isNull,
      );

      await h.container
          .read(conversationsControllerProvider.notifier)
          .refresh(silent: true);
      final state = h.container.read(conversationsControllerProvider);
      expect(state.items.map((c) => c.id), [1, 2, 3]);
      expect(state.items.map((c) => c.id).toSet(), hasLength(3));
      expect(state.nextCursor, isNull);
    },
  );

  test(
    'markRead updates Drift, Home, and Conversations from one local source',
    () async {
      h.container.read(conversationsControllerProvider);
      h.container.read(homeControllerProvider);
      await waitUntil(
        () => h.container.read(homeControllerProvider).snapshot != null,
      );
      await waitUntil(
        () => h.container
            .read(conversationsControllerProvider)
            .items
            .any((c) => c.id == 1),
      );

      expect(
        h.container.read(homeControllerProvider).snapshot?.unreadConversations,
        5,
      );
      expect(
        h.container
            .read(conversationsControllerProvider)
            .items
            .singleWhere((c) => c.id == 1)
            .unreadCount,
        2,
      );

      await h.repo.markRead(1, workspaceId: 1);
      h.container.read(inboxLocalRevisionProvider.notifier).state++;
      await waitUntil(() {
        final items = h.container.read(conversationsControllerProvider).items;
        return items.any((c) => c.id == 1 && c.unreadCount == 0) &&
            h.container
                    .read(homeControllerProvider)
                    .snapshot
                    ?.unreadConversations ==
                4;
      });

      expect((await h.store.getConversation(1, 1))?.unreadCount, 0);
      expect(
        h.container
            .read(conversationsControllerProvider)
            .items
            .singleWhere((c) => c.id == 1)
            .unreadCount,
        0,
      );
      expect(
        h.container.read(homeControllerProvider).snapshot?.unreadConversations,
        4,
      );
    },
  );

  test(
    'polling after markRead does not restore the stale unread count',
    () async {
      await h.store.upsertConversations(1, [_conv(1, name: 'one', unread: 2)]);
      await h.store.markConversationRead(1, 1);
      expect((await h.store.getConversation(1, 1))?.unreadCount, 0);

      await h.store.upsertConversations(1, [_conv(1, name: 'one', unread: 2)]);
      expect((await h.store.getConversation(1, 1))?.unreadCount, 0);
      expect(await h.store.staleServerUnreadClearedCount(1), 1);
    },
  );

  test('failed markRead does not claim local unread=0', () async {
    await h.store.upsertConversations(1, [_conv(1, name: 'one', unread: 2)]);
    h.repo.markReadShouldFail = true;
    await expectLater(
      h.repo.markRead(1, workspaceId: 1),
      throwsA(isA<Exception>()),
    );
    expect((await h.store.getConversation(1, 1))?.unreadCount, 2);
  });

  test('server unread 0 is kept even when last_message_at is slightly newer', () async {
    final original = DateTime.utc(2026, 1, 1, 12);
    await h.store.upsertConversations(1, [
      _conv(1, name: 'one', unread: 2, at: original, lastMessageId: 10),
    ]);
    await h.store.markConversationRead(1, 1);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);

    await h.store.upsertConversations(1, [
      _conv(
        1,
        name: 'one',
        unread: 0,
        at: original.add(const Duration(milliseconds: 5)),
        lastMessageId: 10,
      ),
    ]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);
  });

  test('stale unread with the same last message id is not restored', () async {
    final original = DateTime.utc(2026, 1, 1, 12);
    await h.store.upsertConversations(1, [
      _conv(1, name: 'one', unread: 5, at: original, lastMessageId: 10),
    ]);
    await h.store.markConversationRead(1, 1);

    await h.store.upsertConversations(1, [
      _conv(
        1,
        name: 'one',
        unread: 5,
        at: original.add(const Duration(milliseconds: 8)),
        lastMessageId: 10,
      ),
    ]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);
    expect(await h.store.staleServerUnreadClearedCount(1), 1);
  });

  test('newer inbound message restores unread after local read', () async {
    final original = DateTime.utc(2026, 1, 1, 12);
    await h.store.upsertConversations(1, [
      _conv(1, name: 'one', unread: 2, at: original, lastMessageId: 10),
    ]);
    await h.store.markConversationRead(1, 1);

    await h.store.upsertConversations(1, [
      _conv(
        1,
        name: 'one',
        unread: 1,
        at: original.add(const Duration(minutes: 1)),
        lastMessageId: 11,
      ),
    ]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 1);
  });

  test('same outbound last message after send does not restore unread', () async {
    final original = DateTime.utc(2026, 1, 1, 12);
    await h.store.upsertConversations(1, [
      _conv(1, name: 'one', unread: 5, at: original, lastMessageId: 10),
    ]);
    await h.store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 1,
      message: MessageModel(
        id: -1,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رد',
        createdAt: original.add(const Duration(minutes: 1)),
        clientId: 'send-high-water',
      ),
    );
    await h.store.completeOutbox(
      workspaceId: 1,
      clientId: 'send-high-water',
      server: MessageModel(
        id: 12,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رد',
        createdAt: original.add(const Duration(minutes: 1)),
        clientId: 'send-high-water',
      ),
    );

    await h.store.upsertConversations(1, [
      _conv(
        1,
        name: 'one',
        unread: 5,
        at: original.add(const Duration(minutes: 1)),
        lastMessageId: 12,
        lastMessageDirection: 'outbound',
      ),
    ]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);
    expect(await h.store.staleServerUnreadClearedCount(1), 1);
  });

  test('newer inbound after send restores unread', () async {
    final original = DateTime.utc(2026, 1, 1, 12);
    await h.store.upsertConversations(1, [
      _conv(1, name: 'one', unread: 4, at: original, lastMessageId: 10),
    ]);
    await h.store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 1,
      message: MessageModel(
        id: -1,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رد',
        createdAt: original.add(const Duration(minutes: 1)),
        clientId: 'send-then-inbound',
      ),
    );
    await h.store.completeOutbox(
      workspaceId: 1,
      clientId: 'send-then-inbound',
      server: MessageModel(
        id: 20,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رد',
        createdAt: original.add(const Duration(minutes: 1)),
        clientId: 'send-then-inbound',
      ),
    );
    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);

    await h.store.upsertConversations(1, [
      _conv(
        1,
        name: 'one',
        unread: 1,
        at: original.add(const Duration(minutes: 2)),
        lastMessageId: 21,
      ),
    ]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 1);
  });

  test('newer outbound after inbound activity restores unread', () async {
    final original = DateTime.utc(2026, 1, 1, 12);
    await h.store.upsertConversations(1, [
      _conv(1, name: 'one', unread: 0, at: original, lastMessageId: 20),
    ]);
    await h.store.markConversationRead(1, 1);

    await h.store.upsertConversations(1, [
      _conv(
        1,
        name: 'one',
        unread: 1,
        at: original.add(const Duration(minutes: 2)),
        lastMessageId: 22,
        lastMessageDirection: 'outbound',
      ),
    ]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 1);
  });

  test('missing last message still restores unread from a newer timestamp', () async {
    final original = DateTime.utc(2026, 1, 1, 12);
    await h.store.upsertConversations(1, [
      _conv(1, name: 'one', unread: 2, at: original, lastMessageId: 10),
    ]);
    await h.store.markConversationRead(1, 1);

    await h.store.upsertConversations(1, [
      _conv(
        1,
        name: 'one',
        unread: 3,
        at: original.add(const Duration(minutes: 3)),
      ),
    ]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 3);
  });

  test('list last_message is stored so the open chat can show it', () async {
    await h.store.upsertConversations(1, [
      _conv(1, name: 'مرحبا من العميل', unread: 1, lastMessageId: 77),
    ]);
    final messages = await h.store.getMessages(1, 1);
    expect(messages.single.id, 77);
    expect(messages.single.content, 'مرحبا من العميل');
    expect(messages.single.direction, 'inbound');
  });

  test('a later local snapshot cannot hide the inbound customer message', () async {
    await h.store.upsertConversations(1, [
      _conv(
        1,
        name: 'رد الذكاء',
        unread: 1,
        lastMessageId: 22,
        lastMessageDirection: 'outbound',
      ),
    ]);
    h.repo.remoteMessages = const [
      MessageModel(
        id: 22,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رد الذكاء',
        aiGenerated: true,
      ),
      MessageModel(
        id: 21,
        conversationId: 1,
        direction: 'inbound',
        messageType: 'text',
        content: 'مرحبا من العميل',
      ),
    ];

    h.container.read(chatControllerProvider(1));
    await h.container.read(chatControllerProvider(1).notifier).refresh(
      silent: true,
    );
    expect(
      h.container.read(chatControllerProvider(1)).messages.map((m) => m.content),
      containsAll(['مرحبا من العميل', 'رد الذكاء']),
    );

    await h.store.upsertMessages(1, 1, const [
      MessageModel(
        id: 22,
        conversationId: 1,
        direction: 'outbound',
        messageType: 'text',
        content: 'رد الذكاء',
        aiGenerated: true,
      ),
    ]);
    await Future<void>.delayed(const Duration(milliseconds: 50));

    final shown = h.container.read(chatControllerProvider(1)).messages;
    expect(shown.map((m) => m.id), containsAll([21, 22]));
    expect(
      shown.singleWhere((m) => m.id == 21).direction,
      'inbound',
    );
    expect(
      shown.singleWhere((m) => m.id == 22).direction,
      'outbound',
    );
  });

  test('a fast AI row cannot stay ahead of the customer message that caused it', () async {
    const customer = MessageModel(
      id: 21,
      conversationId: 1,
      direction: 'inbound',
      messageType: 'text',
      content: 'مرحبا من العميل',
    );
    final reply = MessageModel(
      id: 22,
      conversationId: 1,
      direction: 'outbound',
      messageType: 'text',
      content: 'رد الذكاء',
      aiGenerated: true,
      createdAt: DateTime.utc(2026, 1, 2, 12),
    );
    // Newest-first, the shape the messages API returns. The customer row has
    // no timestamp, which previously sorted it after the reply.
    h.repo.remoteMessages = [reply, customer];

    h.container.read(chatControllerProvider(1));
    await h.container.read(chatControllerProvider(1).notifier).refresh(
      silent: true,
    );
    await h.store.upsertMessages(1, 1, [reply]);
    await Future<void>.delayed(const Duration(milliseconds: 50));
    await h.store.upsertMessages(1, 1, [customer]);
    await Future<void>.delayed(const Duration(milliseconds: 50));

    final shown = h.container.read(chatControllerProvider(1)).messages;
    final ids = shown.map((m) => m.id).toList();
    expect(ids.where((id) => id == 21), hasLength(1));
    expect(ids.where((id) => id == 22), hasLength(1));
    expect(ids.indexOf(21), lessThan(ids.indexOf(22)));
    expect(shown.singleWhere((m) => m.id == 21).content, 'مرحبا من العميل');
    expect(shown.singleWhere((m) => m.id == 22).content, 'رد الذكاء');
  });

  test('refresh keeps a fetched inbound that the painted list had missed', () async {
    await h.store.upsertConversations(1, [
      _conv(1, name: 'قديمة', unread: 1, lastMessageId: 10),
    ]);
    await h.store.upsertMessages(1, 1, [
      const MessageModel(
        id: 10,
        conversationId: 1,
        direction: 'inbound',
        messageType: 'text',
        content: 'قديمة',
      ),
    ]);
    h.repo.remoteMessages = [
      const MessageModel(
        id: 11,
        conversationId: 1,
        direction: 'inbound',
        messageType: 'text',
        content: 'جديدة من العميل',
      ),
      const MessageModel(
        id: 10,
        conversationId: 1,
        direction: 'inbound',
        messageType: 'text',
        content: 'قديمة',
      ),
    ];

    h.container.read(chatControllerProvider(1));
    await waitUntil(
      () => h.container
          .read(chatControllerProvider(1))
          .messages
          .any((m) => m.id == 10),
    );
    await h.container.read(chatControllerProvider(1).notifier).refresh(
      silent: true,
    );
    expect(
      h.container.read(chatControllerProvider(1)).messages.map((m) => m.id),
      contains(11),
    );
    expect(
      h.container
          .read(chatControllerProvider(1))
          .messages
          .singleWhere((m) => m.id == 11)
          .content,
      'جديدة من العميل',
    );
  });

  test('opening a chat clears local unread without sending', () async {
    await h.store.upsertConversations(1, [
      _conv(1, name: 'one', unread: 4, lastMessageId: 10),
    ]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 4);

    h.container.read(chatControllerProvider(1));
    await waitUntil(() {
      final cached = h.container.read(chatControllerProvider(1)).conversation;
      return cached != null && cached.unreadCount == 0;
    });
    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);
  });

  test('unassigned conversations are stored under the workspace', () async {
    await h.store.upsertConversations(1, [
      _conv(4, name: 'unassigned', unread: 3),
    ]);
    await h.store.upsertConversations(2, [
      _conv(5, name: 'other company', unread: 9),
    ]);

    final companyA = await h.store.getConversations(1);
    expect(companyA.map((c) => c.id), [4]);
    expect(companyA.single.unreadCount, 3);
    expect(companyA.single.assignedUserId, isNull);
    expect(companyA.single.assignedWorkerEngagementId, isNull);

    final companyB = await h.store.getConversations(2);
    expect(companyB.map((c) => c.id), [5]);
    expect(companyB.single.unreadCount, 9);
  });
}
