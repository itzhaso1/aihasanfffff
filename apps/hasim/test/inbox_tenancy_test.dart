import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_client.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/data/home_repository.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/workspace/data/workspace_repository.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

const _workspaceA = WorkspaceModel(id: 1, name: 'Workspace A');
const _workspaceB = WorkspaceModel(id: 2, name: 'Workspace B');
const _user = UserModel(id: 9, name: 'Tester');

final _loginA = LoginResult(
  token: 'token-a',
  user: _user,
  workspace: _workspaceA,
  workspaces: const [_workspaceA, _workspaceB],
);

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

  LoginResult nextLogin = _loginA;
  bool logoutCalled = false;

  @override
  Future<LoginResult> login({
    required String emailOrPhone,
    required String password,
    int? workspaceId,
    String deviceName = 'حاسم Flutter',
    String deviceType = 'mobile',
  }) async {
    return nextLogin;
  }

  @override
  Future<void> logout() async {
    logoutCalled = true;
  }

  @override
  Future<({UserModel user, WorkspaceModel? workspace, List<WorkspaceModel> workspaces})> me() async {
    return (
      user: nextLogin.user,
      workspace: nextLogin.workspace,
      workspaces: nextLogin.workspaces,
    );
  }
}

class _FakeWorkspaceRepository extends WorkspaceRepository {
  _FakeWorkspaceRepository(super.api);

  bool fail = false;
  int? lastSwitchId;

  @override
  Future<WorkspaceModel> switchTo(int workspaceId) async {
    if (fail) throw ApiException('رفض الخادم');
    lastSwitchId = workspaceId;
    return workspaceId == 2 ? _workspaceB : _workspaceA;
  }
}

class _FakeConversationRepository extends ConversationRepository {
  _FakeConversationRepository(ApiClient api, this._prefs, {required InboxLocalStore store})
      : _store = store,
        super(api, local: store);

  final PrefsStore _prefs;
  final InboxLocalStore _store;
  Completer<void>? holdFirstList;
  Completer<void>? holdFirstMessages;
  int listCalls = 0;
  int messageCalls = 0;
  List<ConversationModel> itemsA = [_conv(10, 'Tenant A')];
  List<ConversationModel> itemsB = [_conv(20, 'Tenant B')];
  List<MessageModel> messagesA = [_msg(1, 123, 'A-123')];
  List<MessageModel> messagesB = [_msg(2, 123, 'B-123')];

  @override
  Future<({List<ConversationModel> items, String? nextCursor})> list({
    String filter = 'all',
    String? channel,
    String? search,
    String? cursor,
    int? workspaceId,
  }) async {
    listCalls++;
    final call = listCalls;
    final scoped = workspaceId ?? _prefs.workspaceId;
    if (call == 1 && holdFirstList != null) {
      await holdFirstList!.future;
      if (scoped != null) await _store.upsertConversations(scoped, itemsA);
      return (items: itemsA, nextCursor: null);
    }
    final items = scoped == 2 ? itemsB : itemsA;
    if (scoped != null) await _store.upsertConversations(scoped, items);
    return (items: items, nextCursor: null);
  }

  @override
  Future<ConversationModel> getById(int id, {int? workspaceId}) async {
    return ConversationModel(
      id: id,
      channel: 'whatsapp',
      status: 'open',
      externalId: 'c$id',
    );
  }

  @override
  Future<({List<MessageModel> items, String? nextCursor})> messages(
    int conversationId, {
    String? cursor,
    int? workspaceId,
  }) async {
    messageCalls++;
    final call = messageCalls;
    final scoped = workspaceId ?? _prefs.workspaceId;
    if (call == 1 && holdFirstMessages != null) {
      await holdFirstMessages!.future;
      if (scoped != null) await _store.upsertMessages(scoped, conversationId, messagesA);
      return (items: messagesA, nextCursor: null);
    }
    final items = scoped == 2 ? messagesB : messagesA;
    if (scoped != null) await _store.upsertMessages(scoped, conversationId, items);
    return (items: items, nextCursor: null);
  }

  @override
  Future<void> markRead(int conversationId, {int? messageId, int? workspaceId}) async {}
}

class _FakeHomeRepository extends HomeRepository {
  _FakeHomeRepository(super.api);

  HomeSnapshot snapshot = const HomeSnapshot(unreadConversations: 4);

  @override
  Future<HomeSnapshot> home() async => snapshot;
}

class _Harness {
  _Harness({
    required this.container,
    required this.db,
    required this.store,
    required this.prefs,
    required this.secure,
    required this.authRepo,
    required this.workspaceRepo,
    required this.conversationRepo,
  });

  final ProviderContainer container;
  final InboxDatabase db;
  final InboxLocalStore store;
  final PrefsStore prefs;
  final _MemorySecureStore secure;
  final _FakeAuthRepository authRepo;
  final _FakeWorkspaceRepository workspaceRepo;
  final _FakeConversationRepository conversationRepo;

  AuthController get auth => container.read(authControllerProvider.notifier);

  Future<void> waitUntilReady() async {
    for (var i = 0; i < 40; i++) {
      if (!container.read(authControllerProvider).bootstrapping) return;
      await Future<void>.delayed(const Duration(milliseconds: 5));
    }
  }

  Future<void> loginA() async {
    await waitUntilReady();
    final ok = await auth.login('a@example.com', 'secret');
    expect(ok, isTrue);
    expect(container.read(authControllerProvider).workspace?.id, 1);
  }
}

Future<_Harness> _openHarness() async {
  SharedPreferences.setMockInitialValues({});
  final rawPrefs = await SharedPreferences.getInstance();
  final prefs = PrefsStore(rawPrefs);
  final secure = _MemorySecureStore();
  final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
  final db = InboxDatabase.memory();
  final store = InboxLocalStore(db);
  final authRepo = _FakeAuthRepository(api);
  final workspaceRepo = _FakeWorkspaceRepository(api);
  final conversationRepo = _FakeConversationRepository(api, prefs, store: store);
  final container = ProviderContainer(
    overrides: [
      sharedPrefsProvider.overrideWithValue(rawPrefs),
      secureStoreProvider.overrideWithValue(secure),
      apiClientProvider.overrideWithValue(api),
      inboxDatabaseProvider.overrideWithValue(db),
      inboxLocalStoreProvider.overrideWithValue(store),
      authRepositoryProvider.overrideWithValue(authRepo),
      workspaceRepositoryProvider.overrideWithValue(workspaceRepo),
      conversationRepositoryProvider.overrideWithValue(conversationRepo),
      homeRepositoryProvider.overrideWithValue(_FakeHomeRepository(api)),
      realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
    ],
  );
  return _Harness(
    container: container,
    db: db,
    store: store,
    prefs: prefs,
    secure: secure,
    authRepo: authRepo,
    workspaceRepo: workspaceRepo,
    conversationRepo: conversationRepo,
  );
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late _Harness h;

  setUp(() async {
    h = await _openHarness();
  });

  tearDown(() async {
    h.container.dispose();
    await h.db.close();
  });

  test('logout wipes all inbox caches and keeps theme preference', () async {
    await h.prefs.setThemeMode('dark');
    await h.loginA();
    await h.store.upsertConversations(1, [_conv(10, 'Tenant A')]);
    await h.store.upsertMessages(1, 123, [_msg(1, 123, 'secret A')]);
    await h.store.upsertConversations(2, [_conv(20, 'Tenant B')]);

    await h.auth.logout();

    expect(h.authRepo.logoutCalled, isTrue);
    expect(await h.store.conversationCount(), 0);
    expect(await h.store.messageCount(), 0);
    expect(await h.store.outboxCount(), 0);
    expect(await h.secure.readToken(), isNull);
    expect(h.prefs.workspaceId, isNull);
    expect(h.container.read(authControllerProvider).isAuthenticated, isFalse);
    expect(h.prefs.themeMode, 'dark');
  });

  test('401 unauthorized event wipes inbox caches', () async {
    await h.loginA();
    await h.store.upsertConversations(1, [_conv(10, 'Tenant A')]);
    await h.store.upsertMessages(1, 123, [_msg(1, 123, 'secret A')]);

    unauthorizedEvents.add(null);
    await Future<void>.delayed(const Duration(milliseconds: 30));

    expect(await h.store.conversationCount(), 0);
    expect(await h.store.messageCount(), 0);
    expect(h.container.read(authControllerProvider).isAuthenticated, isFalse);
    expect(h.prefs.workspaceId, isNull);
    expect(await h.secure.readToken(), isNull);
  });

  test('failed workspace switch keeps A active and A cache usable', () async {
    await h.loginA();
    await h.store.upsertConversations(1, [_conv(10, 'Tenant A')]);
    await h.store.upsertMessages(1, 123, [_msg(1, 123, 'A-123')]);
    h.workspaceRepo.fail = true;

    final ok = await h.auth.switchWorkspace(_workspaceB);

    expect(ok, isFalse);
    expect(h.container.read(authControllerProvider).workspace?.id, 1);
    expect(h.prefs.workspaceId, 1);
    expect((await h.store.getConversations(1)).single.externalId, 'Tenant A');
    expect((await h.store.getMessages(1, 123)).single.content, 'A-123');
    expect(await h.store.getConversations(2), isEmpty);
  });

  test('successful switch loads B cache and never surfaces A conversations', () async {
    await h.loginA();
    await h.store.upsertConversations(1, [_conv(10, 'Tenant A')]);
    await h.store.upsertConversations(2, [_conv(20, 'Tenant B')]);

    h.container.read(conversationsControllerProvider);
    await Future<void>.delayed(const Duration(milliseconds: 20));
    expect(
      h.container.read(conversationsControllerProvider).items.map((c) => c.externalId),
      ['Tenant A'],
    );

    final ok = await h.auth.switchWorkspace(_workspaceB);
    expect(ok, isTrue);
    await Future<void>.delayed(const Duration(milliseconds: 20));

    final state = h.container.read(conversationsControllerProvider);
    expect(state.items.map((c) => c.externalId), ['Tenant B']);
    expect(state.items.any((c) => c.externalId == 'Tenant A'), isFalse);
    expect(h.container.read(authControllerProvider).workspace?.id, 2);
    expect(h.prefs.workspaceId, 2);
    expect((await h.store.getConversations(1)).single.externalId, 'Tenant A');
    expect((await h.store.getConversations(2)).single.externalId, 'Tenant B');
  });

  test('stale workspace A list response does not update B UI or cache', () async {
    await h.loginA();
    await h.store.upsertConversations(1, [_conv(10, 'Tenant A')]);
    await h.store.upsertConversations(2, [_conv(20, 'Cached B')]);
    h.conversationRepo.holdFirstList = Completer<void>();

    h.container.read(conversationsControllerProvider);
    await Future<void>.delayed(const Duration(milliseconds: 10));

    final switched = await h.auth.switchWorkspace(_workspaceB);
    expect(switched, isTrue);
    await Future<void>.delayed(const Duration(milliseconds: 20));

    expect(
      h.container.read(conversationsControllerProvider).items.map((c) => c.externalId),
      ['Tenant B'],
    );

    h.conversationRepo.holdFirstList!.complete();
    await Future<void>.delayed(const Duration(milliseconds: 20));

    final state = h.container.read(conversationsControllerProvider);
    expect(state.items.any((c) => c.externalId == 'Tenant A'), isFalse);
    expect(state.items.map((c) => c.externalId), ['Tenant B']);
    expect((await h.store.getConversations(2)).single.externalId, 'Tenant B');
    expect((await h.store.getConversations(1)).single.externalId, 'Tenant A');
  });

  test('stale chat response cannot write workspace B message cache', () async {
    await h.loginA();
    await h.store.upsertMessages(1, 123, [_msg(1, 123, 'A-123')]);
    await h.store.upsertMessages(2, 123, [_msg(2, 123, 'B-123')]);
    h.conversationRepo.holdFirstMessages = Completer<void>();

    h.container.read(chatControllerProvider(123));
    await Future<void>.delayed(const Duration(milliseconds: 10));

    await h.auth.switchWorkspace(_workspaceB);
    await Future<void>.delayed(const Duration(milliseconds: 20));

    h.conversationRepo.holdFirstMessages!.complete();
    await Future<void>.delayed(const Duration(milliseconds: 20));

    expect((await h.store.getMessages(1, 123)).single.content, 'A-123');
    expect((await h.store.getMessages(2, 123)).single.content, 'B-123');
    expect(
      h.container.read(chatControllerProvider(123)).messages.any((m) => m.content == 'A-123'),
      isFalse,
    );
  });

  test('home refresh writes only the active workspace conversation cache', () async {
    await h.loginA();
    await h.store.upsertConversations(2, [_conv(20, 'Tenant B')]);

    h.container.read(homeControllerProvider);
    await Future<void>.delayed(const Duration(milliseconds: 20));

    expect((await h.store.getConversations(1)).single.externalId, 'Tenant A');
    expect((await h.store.getConversations(2)).single.externalId, 'Tenant B');
  });

  test('stale home refresh does not overwrite the new workspace cache', () async {
    await h.loginA();
    await h.store.upsertConversations(2, [_conv(20, 'Cached B')]);
    h.conversationRepo.holdFirstList = Completer<void>();

    h.container.read(homeControllerProvider);
    await Future<void>.delayed(const Duration(milliseconds: 10));

    await h.auth.switchWorkspace(_workspaceB);
    await Future<void>.delayed(const Duration(milliseconds: 20));

    h.conversationRepo.holdFirstList!.complete();
    await Future<void>.delayed(const Duration(milliseconds: 20));

    expect((await h.store.getConversations(2)).map((c) => c.externalId), isNot(contains('Tenant A')));
    expect(
      h.container.read(homeControllerProvider).recentConversations.any((c) => c.externalId == 'Tenant A'),
      isFalse,
    );
  });
}
