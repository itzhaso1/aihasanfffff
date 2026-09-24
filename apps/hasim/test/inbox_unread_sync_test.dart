import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
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

ConversationModel _conv(int id, {required String name, int unread = 0, DateTime? at}) {
  return ConversationModel(
    id: id,
    channel: 'whatsapp',
    status: 'open',
    externalId: name,
    unreadCount: unread,
    lastMessageAt: at ?? DateTime.utc(2026, 1, 1, 12),
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
    );
  }

  @override
  Future<void> logout() async {}

  @override
  Future<({UserModel user, WorkspaceModel? workspace, List<WorkspaceModel> workspaces})> me() async {
    return (user: _user, workspace: _workspace, workspaces: const [_workspace]);
  }
}

class _PagedRepo extends ConversationRepository {
  _PagedRepo(ApiClient api, this._store) : super(api, local: _store);

  final InboxLocalStore _store;
  bool markReadShouldFail = false;
  int markReadCalls = 0;
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
  Future<void> markRead(int conversationId, {int? messageId, int? workspaceId}) async {
    markReadCalls++;
    if (markReadShouldFail) {
      throw Exception('mark-read failed');
    }
    if (workspaceId != null) {
      await _store.markConversationRead(workspaceId, conversationId);
    }
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

  test('load more then polling keeps page-2 rows and the load-more cursor', () async {
    h.container.read(conversationsControllerProvider);
    await waitUntil(() => h.container.read(conversationsControllerProvider).items.length >= 2);
    expect(h.container.read(conversationsControllerProvider).items.map((c) => c.id), [1, 2]);
    expect(h.container.read(conversationsControllerProvider).nextCursor, 'next-1');

    await h.container.read(conversationsControllerProvider.notifier).loadMore();
    expect(h.container.read(conversationsControllerProvider).items.map((c) => c.id), [1, 2, 3]);
    expect(h.container.read(conversationsControllerProvider).nextCursor, isNull);

    await h.container.read(conversationsControllerProvider.notifier).refresh(silent: true);
    final state = h.container.read(conversationsControllerProvider);
    expect(state.items.map((c) => c.id), [1, 2, 3]);
    expect(state.items.map((c) => c.id).toSet(), hasLength(3));
    expect(state.nextCursor, isNull);
  });

  test('markRead updates Drift, Home, and Conversations from one local source', () async {
    h.container.read(conversationsControllerProvider);
    h.container.read(homeControllerProvider);
    await waitUntil(() => h.container.read(homeControllerProvider).snapshot != null);
    await waitUntil(() => h.container.read(conversationsControllerProvider).items.any((c) => c.id == 1));

    expect(h.container.read(homeControllerProvider).snapshot?.unreadConversations, 5);
    expect(h.container.read(conversationsControllerProvider).items.singleWhere((c) => c.id == 1).unreadCount, 2);

    await h.repo.markRead(1, workspaceId: 1);
    h.container.read(inboxLocalRevisionProvider.notifier).state++;
    await waitUntil(() {
      final items = h.container.read(conversationsControllerProvider).items;
      return items.any((c) => c.id == 1 && c.unreadCount == 0) &&
          h.container.read(homeControllerProvider).snapshot?.unreadConversations == 4;
    });

    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);
    expect(h.container.read(conversationsControllerProvider).items.singleWhere((c) => c.id == 1).unreadCount, 0);
    expect(h.container.read(homeControllerProvider).snapshot?.unreadConversations, 4);
  });

  test('polling after markRead does not restore the stale unread count', () async {
    await h.store.upsertConversations(1, [_conv(1, name: 'one', unread: 2)]);
    await h.store.markConversationRead(1, 1);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);

    await h.store.upsertConversations(1, [_conv(1, name: 'one', unread: 2)]);
    expect((await h.store.getConversation(1, 1))?.unreadCount, 0);
    expect(await h.store.staleServerUnreadClearedCount(1), 1);
  });

  test('failed markRead does not claim local unread=0', () async {
    await h.store.upsertConversations(1, [_conv(1, name: 'one', unread: 2)]);
    h.repo.markReadShouldFail = true;
    await expectLater(h.repo.markRead(1, workspaceId: 1), throwsA(isA<Exception>()));
    expect((await h.store.getConversation(1, 1))?.unreadCount, 2);
  });
}
