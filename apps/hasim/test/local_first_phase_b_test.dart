import 'dart:async';

import 'package:flutter/material.dart';
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
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  test('account snapshot round-trips without storing a token', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    const user = UserModel(id: 4, name: 'ليان', email: 'lian@example.com');
    const workspace = WorkspaceModel(id: 8, name: 'المتجر', isOwner: true, role: 'owner');
    await store.saveAccountSnapshot(
      const LocalAccountSnapshot(
        user: user,
        participant: MobileParticipant(type: 'company_staff'),
        workspaces: [workspace],
        currentWorkspaceId: 8,
      ),
    );

    final loaded = await store.loadAccountSnapshot();
    expect(loaded?.user.name, 'ليان');
    expect(loaded?.user.email, 'lian@example.com');
    expect(loaded?.participant.isIndependentWorker, isFalse);
    expect(loaded?.currentWorkspaceId, 8);
    expect(loaded?.workspaces.single.isOwner, isTrue);
    expect(loaded?.workspaces.single.role, 'owner');
    final columns = await db.select('PRAGMA table_info(local_account)');
    expect(columns.any((row) => row['name'].toString().contains('token')), isFalse);
  });

  test('workspace snapshots stay isolated from each other', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    await store.upsertConversations(1, [
      _conversation(1, 'الأولى'),
    ]);
    await store.upsertConversations(2, [
      _conversation(2, 'الثانية'),
    ]);
    await store.upsertMessages(1, 1, [_message(10, 1, 'من الأولى', 1)]);
    await store.upsertMessages(2, 2, [_message(11, 2, 'من الثانية', 1)]);

    expect((await store.getConversations(1)).single.externalId, 'الأولى');
    expect((await store.getConversations(2)).single.externalId, 'الثانية');
    expect((await store.getMessages(1, 1)).single.content, 'من الأولى');
    expect(await store.getMessages(2, 1), isEmpty);
  });

  test('latest message window is chronological and skips older rows', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    final messages = [
      for (var i = 0; i < 400; i++)
        _message(i + 1, 7, 'm$i', i + 1),
    ];
    await store.upsertMessages(3, 7, messages);

    final latest = await store.getMessages(3, 7);
    expect(latest, hasLength(InboxLocalStore.latestMessageWindow));
    expect(latest.first.content, 'm${400 - InboxLocalStore.latestMessageWindow}');
    expect(latest.last.content, 'm399');
    final older = await store.getOlderMessages(
      3,
      7,
      before: latest.first.createdAt!,
    );
    expect(older, isNotEmpty);
    expect(older.last.createdAt!.isBefore(latest.first.createdAt!), isTrue);
  });

  test('message attribution is stored without collapsing worker into human', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    await store.upsertMessages(1, 4, [
      const MessageModel(
        id: 1,
        conversationId: 4,
        direction: 'outbound',
        messageType: 'text',
        content: 'عامل',
        aiGenerated: false,
        workerEngagementId: 55,
        participantType: 'worker',
        createdAt: null,
      ),
      const MessageModel(
        id: 2,
        conversationId: 4,
        direction: 'outbound',
        messageType: 'text',
        content: 'ذكاء',
        aiGenerated: true,
        participantType: 'ai',
      ),
    ]);
    final rows = await store.getMessages(1, 4);
    expect(rows.first.participantType, 'worker');
    expect(rows.first.workerEngagementId, 55);
    expect(rows.first.aiGenerated, isFalse);
    expect(rows.last.participantType, 'ai');
    expect(rows.last.aiGenerated, isTrue);
  });

  test('conversation list query stays fast for a thousand rows', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    await db.transaction((tx) async {
      for (var i = 0; i < 1000; i++) {
        await tx.runCustom(
          'INSERT INTO inbox_conversations ('
          'workspace_id, server_id, channel, status, external_id, last_message_at, updated_at, cached_at'
          ') VALUES (1, ?, ?, ?, ?, ?, 1, 1)',
          [i + 1, 'whatsapp', 'open', 'c$i', i + 1],
        );
      }
      for (var i = 0; i < 10000; i++) {
        final conversationId = (i % 1000) + 1;
        await tx.runCustom(
          'INSERT INTO inbox_messages ('
          'workspace_id, conversation_server_id, server_id, direction, message_type, content, created_at, cached_at'
          ') VALUES (1, ?, ?, ?, ?, ?, ?, 1)',
          [conversationId, i + 1, 'inbound', 'text', 'body$i', i + 1],
        );
      }
    });

    final started = DateTime.now();
    final list = await store.getConversations(1);
    final opened = await store.getMessages(1, 1000);
    final elapsed = DateTime.now().difference(started);
    expect(list, hasLength(1000));
    expect(list.first.externalId, 'c999');
    expect(opened.last.content, 'body9999');
    expect(elapsed.inMilliseconds, lessThan(1500));
  });

  test('outbox send stays on its workspace', () async {
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    await store.enqueueTextSend(
      workspaceId: 1,
      conversationId: 9,
      message: MessageModel(
        id: -1,
        conversationId: 9,
        direction: 'outbound',
        messageType: 'text',
        content: 'مرحبًا',
        clientId: 'client-a',
        localPending: true,
        createdAt: DateTime.now(),
      ),
    );
    await store.enqueueTextSend(
      workspaceId: 2,
      conversationId: 9,
      message: MessageModel(
        id: -2,
        conversationId: 9,
        direction: 'outbound',
        messageType: 'text',
        content: 'أخرى',
        clientId: 'client-b',
        localPending: true,
        createdAt: DateTime.now(),
      ),
    );

    final due = await store.dueOutbox(workspaceId: 1);
    expect(due, hasLength(1));
    expect(due.single.clientId, 'client-a');
    expect((await store.getMessages(1, 9)).single.localPending, isTrue);
    expect(await store.getMessages(2, 9), isNotEmpty);
  });

  testWidgets('startup shows the local account before me() returns', (tester) async {
    SharedPreferences.setMockInitialValues({'workspace_id': 8});
    final rawPrefs = await SharedPreferences.getInstance();
    final prefs = PrefsStore(rawPrefs);
    final secure = _MemorySecureStore()..token = 'saved-token';
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    await store.saveAccountSnapshot(
      const LocalAccountSnapshot(
        user: UserModel(id: 4, name: 'ليان'),
        participant: MobileParticipant(type: 'company_staff'),
        workspaces: [WorkspaceModel(id: 8, name: 'المتجر', isOwner: true)],
        currentWorkspaceId: 8,
      ),
    );
    final meGate = Completer<void>();
    final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
    final container = ProviderContainer(
      overrides: [
        sharedPrefsProvider.overrideWithValue(rawPrefs),
        secureStoreProvider.overrideWithValue(secure),
        apiClientProvider.overrideWithValue(api),
        inboxDatabaseProvider.overrideWithValue(db),
        inboxLocalStoreProvider.overrideWithValue(store),
        authRepositoryProvider.overrideWithValue(_HangingAuth(api, meGate)),
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      ],
    );
    addTearDown(container.dispose);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: const MaterialApp(home: _AccountLabel()),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('ليان'), findsOneWidget);
    expect(meGate.isCompleted, isFalse);
    meGate.complete();
  });

  testWidgets('conversation title appears from sqlite before the list request', (tester) async {
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final rawPrefs = await SharedPreferences.getInstance();
    final prefs = PrefsStore(rawPrefs);
    final secure = _MemorySecureStore()..token = 'saved-token';
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final store = InboxLocalStore(db);
    await store.upsertConversations(1, [
      const ConversationModel(
        id: 3,
        channel: 'whatsapp',
        status: 'open',
        externalId: 'عميل محلي',
      ),
    ]);
    final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
    final repo = _SlowListRepository(api, local: store);
    final container = ProviderContainer(
      overrides: [
        sharedPrefsProvider.overrideWithValue(rawPrefs),
        secureStoreProvider.overrideWithValue(secure),
        apiClientProvider.overrideWithValue(api),
        inboxDatabaseProvider.overrideWithValue(db),
        inboxLocalStoreProvider.overrideWithValue(store),
        inboxSessionProvider.overrideWithValue(_session()),
        authControllerProvider.overrideWith(_seededAuth),
        conversationRepositoryProvider.overrideWithValue(repo),
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      ],
    );
    addTearDown(container.dispose);

    await tester.pumpWidget(
      UncontrolledProviderScope(
        container: container,
        child: const MaterialApp(home: _ConversationLabel()),
      ),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.text('عميل محلي'), findsOneWidget);
    repo.gate.complete();
    await tester.pump();
  });
}

ConversationModel _conversation(int id, String name) {
  return ConversationModel(
    id: id,
    channel: 'whatsapp',
    status: 'open',
    externalId: name,
  );
}

MessageModel _message(int id, int conversationId, String content, int millis) {
  return MessageModel(
    id: id,
    conversationId: conversationId,
    direction: 'inbound',
    messageType: 'text',
    content: content,
    createdAt: DateTime.fromMillisecondsSinceEpoch(millis),
  );
}

class _MemorySecureStore extends SecureStore {
  String? token;

  @override
  Future<String?> readToken() async => token;

  @override
  Future<void> saveToken(String value) async => token = value;

  @override
  Future<void> clearToken() async => token = null;
}

class _HangingAuth extends AuthRepository {
  _HangingAuth(super.api, this.gate);

  final Completer<void> gate;

  @override
  Future<({UserModel user, WorkspaceModel? workspace, List<WorkspaceModel> workspaces, MobileParticipant participant})> me() async {
    await gate.future;
    return (
      user: const UserModel(id: 4, name: 'من الشبكة'),
      workspace: const WorkspaceModel(id: 8, name: 'المتجر'),
      workspaces: const [WorkspaceModel(id: 8, name: 'المتجر')],
      participant: const MobileParticipant(type: 'company_staff'),
    );
  }
}

class _SlowListRepository extends ConversationRepository {
  _SlowListRepository(super.api, {super.local});

  final Completer<void> gate = Completer<void>();

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
    await gate.future;
    return (items: const <ConversationModel>[], nextCursor: null);
  }

  @override
  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? workspaceId,
  }) async {
    return (items: const <MessageModel>[], nextCursor: null);
  }
}

class _AccountLabel extends ConsumerWidget {
  const _AccountLabel();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final name = ref.watch(authControllerProvider).user?.name ?? '';
    return Text(name);
  }
}

class _ConversationLabel extends ConsumerWidget {
  const _ConversationLabel();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final items = ref.watch(conversationsControllerProvider).items;
    final title = items.isEmpty ? '' : items.first.title;
    return Text(title);
  }
}

AuthController _seededAuth(Ref ref) {
  return _SeededAuth(ref);
}

class _SeededAuth extends AuthController {
  _SeededAuth(super.ref);

  @override
  Future<void> bootstrap() async {
    state = const AuthState(
      bootstrapping: false,
      token: 'saved-token',
      user: UserModel(id: 1, name: 'موظف'),
      workspace: WorkspaceModel(id: 1, name: 'مساحة'),
      participant: MobileParticipant(type: 'company_staff'),
    );
  }
}

InboxSession _session() {
  final session = InboxSession();
  session.beginWorkspace(1);
  return session;
}
