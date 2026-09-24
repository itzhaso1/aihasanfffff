import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/data/home_repository.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

const _workspaceA = WorkspaceModel(id: 1, name: 'Workspace A');
const _workspaceB = WorkspaceModel(id: 2, name: 'Workspace B');
const _user = UserModel(id: 9, name: 'Tester');

ConversationModel _conv(int id, {bool archived = false, bool muted = false}) {
  return ConversationModel(
    id: id,
    channel: 'whatsapp',
    status: 'open',
    externalId: 'c$id',
    archived: archived,
    muted: muted,
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
      workspace: _workspaceA,
      workspaces: const [_workspaceA, _workspaceB],
    );
  }

  @override
  Future<void> logout() async {}

  @override
  Future<({UserModel user, WorkspaceModel? workspace, List<WorkspaceModel> workspaces})> me() async {
    return (user: _user, workspace: _workspaceA, workspaces: const [_workspaceA, _workspaceB]);
  }
}

class _RecordingRepo extends ConversationRepository {
  _RecordingRepo(ApiClient api, this._store) : super(api, local: _store);

  final InboxLocalStore _store;
  List<ConversationModel> items = [_conv(7)];
  int? lastArchivedId;
  bool? lastArchivedValue;
  int? lastMutedId;
  bool? lastMutedValue;
  int? lastWorkspaceId;
  Object? archiveError;
  Object? muteError;

  @override
  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? search,
    String? cursor,
    int? workspaceId,
  }) async {
    final visible = items.where((item) {
      if (filter == 'archived') return item.archived;
      if (item.archived) return false;
      if (filter == 'unread') return item.unreadCount > 0;
      return true;
    }).toList();
    if (workspaceId != null) {
      await _store.upsertConversations(workspaceId, items);
    }
    return (items: visible, nextCursor: null);
  }

  @override
  Future<ConversationModel> getById(int id, {int? workspaceId}) async {
    return items.firstWhere((item) => item.id == id);
  }

  @override
  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? workspaceId,
  }) async {
    return (items: const <MessageModel>[], nextCursor: null);
  }

  @override
  Future<void> markRead(int conversationId, {int? messageId, int? workspaceId}) async {}

  @override
  Future<void> archive(int conversationId, {bool archived = true, int? workspaceId}) async {
    lastArchivedId = conversationId;
    lastArchivedValue = archived;
    lastWorkspaceId = workspaceId;
    if (archiveError != null) throw archiveError!;
    items = [
      for (final item in items)
        if (item.id == conversationId) item.copyWith(archived: archived) else item,
    ];
    if (workspaceId != null) {
      await _store.upsertConversations(workspaceId, items);
    }
  }

  @override
  Future<void> mute(int conversationId, {bool muted = true, int? workspaceId}) async {
    lastMutedId = conversationId;
    lastMutedValue = muted;
    lastWorkspaceId = workspaceId;
    if (muteError != null) throw muteError!;
    items = [
      for (final item in items)
        if (item.id == conversationId) item.copyWith(muted: muted) else item,
    ];
    if (workspaceId != null) {
      await _store.upsertConversations(workspaceId, items);
    }
  }
}

class _FakeHomeRepository extends HomeRepository {
  _FakeHomeRepository(super.api);

  @override
  Future<HomeSnapshot> home() async => const HomeSnapshot();
}

class _Harness {
  _Harness({required this.container, required this.db, required this.store, required this.repo});

  final ProviderContainer container;
  final InboxDatabase db;
  final InboxLocalStore store;
  final _RecordingRepo repo;
}

Future<_Harness> _open() async {
  SharedPreferences.setMockInitialValues({});
  final rawPrefs = await SharedPreferences.getInstance();
  final prefs = PrefsStore(rawPrefs);
  final secure = _MemorySecureStore();
  final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
  final db = InboxDatabase.memory();
  final store = InboxLocalStore(db);
  final repo = _RecordingRepo(api, store);
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
  final auth = container.read(authControllerProvider.notifier);
  for (var i = 0; i < 40; i++) {
    if (!container.read(authControllerProvider).bootstrapping) break;
    await Future<void>.delayed(const Duration(milliseconds: 5));
  }
  await auth.login('a@example.com', 'secret');
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

  test('archive action calls repository and updates the list', () async {
    h.container.read(conversationsControllerProvider);
    await waitUntil(() => h.container.read(conversationsControllerProvider).items.any((c) => c.id == 7));

    final error = await h.container.read(conversationsControllerProvider.notifier).setArchived(7, archived: true);
    expect(error, isNull);
    expect(h.repo.lastArchivedId, 7);
    expect(h.repo.lastArchivedValue, isTrue);
    expect(h.container.read(conversationsControllerProvider).items, isEmpty);
    expect((await h.store.getConversation(1, 7))?.archived, isTrue);
  });

  test('archive error restores the list and busy state', () async {
    h.repo.archiveError = ApiException('blocked', statusCode: 403);
    h.container.read(conversationsControllerProvider);
    await waitUntil(() => h.container.read(conversationsControllerProvider).items.any((c) => c.id == 7));

    final error = await h.container.read(conversationsControllerProvider.notifier).setArchived(7, archived: true);
    expect(error, 'blocked');
    expect(h.container.read(conversationsControllerProvider).items.single.id, 7);
    expect(h.container.read(conversationsControllerProvider).mutatingConversationId, isNull);
    expect(h.container.read(conversationsControllerProvider).items.single.archived, isFalse);
  });

  test('unarchive action calls repository', () async {
    h.repo.items = [_conv(7, archived: true)];
    h.container.read(conversationsControllerProvider);
    await h.container.read(conversationsControllerProvider.notifier).setFilter('archived');
    await waitUntil(() => h.container.read(conversationsControllerProvider).items.any((c) => c.id == 7));

    final error = await h.container.read(conversationsControllerProvider.notifier).setArchived(7, archived: false);
    expect(error, isNull);
    expect(h.repo.lastArchivedValue, isFalse);
    expect(h.container.read(conversationsControllerProvider).items, isEmpty);
  });

  test('mute action calls repository and unmute reverses it', () async {
    h.container.read(conversationsControllerProvider);
    await waitUntil(() => h.container.read(conversationsControllerProvider).items.any((c) => c.id == 7));

    var error = await h.container.read(conversationsControllerProvider.notifier).setMuted(7, muted: true);
    expect(error, isNull);
    expect(h.repo.lastMutedId, 7);
    expect(h.repo.lastMutedValue, isTrue);
    expect(h.container.read(conversationsControllerProvider).items.single.muted, isTrue);

    error = await h.container.read(conversationsControllerProvider.notifier).setMuted(7, muted: false);
    expect(error, isNull);
    expect(h.repo.lastMutedValue, isFalse);
    expect(h.container.read(conversationsControllerProvider).items.single.muted, isFalse);
  });

  test('mute error restores unmuted state', () async {
    h.repo.muteError = ApiException('nope', statusCode: 500);
    h.container.read(conversationsControllerProvider);
    await waitUntil(() => h.container.read(conversationsControllerProvider).items.any((c) => c.id == 7));

    final error = await h.container.read(conversationsControllerProvider.notifier).setMuted(7, muted: true);
    expect(error, 'nope');
    expect(h.container.read(conversationsControllerProvider).items.single.muted, isFalse);
    expect(h.container.read(conversationsControllerProvider).mutatingConversationId, isNull);
  });

  test('archive writes only the active workspace cache', () async {
    await h.store.upsertConversations(1, [_conv(7)]);
    await h.store.upsertConversations(2, [_conv(7)]);

    await h.repo.archive(7, archived: true, workspaceId: 1);

    expect((await h.store.getConversation(1, 7))?.archived, isTrue);
    expect((await h.store.getConversation(2, 7))?.archived, isFalse);
  });

  test('chat controller archive calls repository without leaving a busy state', () async {
    h.container.read(conversationsControllerProvider);
    h.container.read(chatControllerProvider(7));
    await waitUntil(() => h.container.read(chatControllerProvider(7)).conversation?.id == 7);

    final error = await h.container.read(chatControllerProvider(7).notifier).setArchived(archived: true);
    expect(error, isNull);
    expect(h.repo.lastArchivedId, 7);
    expect(h.repo.lastArchivedValue, isTrue);
    expect(h.container.read(chatControllerProvider(7)).conversation?.archived, isTrue);
    expect(h.container.read(chatControllerProvider(7)).mutating, isFalse);
  });
}
