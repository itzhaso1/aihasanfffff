import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_database.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/core/storage/secure_store.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/features/auth/data/auth_repository.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/data/conversation_handoff.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/conversations/presentation/chat_screen.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/data/home_repository.dart';
import 'package:hasim/features/settings/data/company_worker_discovery_repository.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/realtime/realtime_service.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'support/isolated_api_client.dart';

const _workspace = WorkspaceModel(id: 1, name: 'مساحة');
const _user = UserModel(id: 9, name: 'موظف الشركة');

ConversationModel _conv({
  String priority = 'normal',
  String payment = 'unpaid',
  String review = 'no_review_needed',
  String handoff = 'none',
  int unread = 3,
  int? assignedUserId,
}) {
  return ConversationModel(
    id: 7,
    channel: 'whatsapp',
    status: 'open',
    unreadCount: unread,
    customerPriority: priority,
    paymentStatus: payment,
    reviewStatus: review,
    handoffStatus: handoff,
    assignedUserId: assignedUserId,
    customer: const CustomerModel(id: 2, name: 'أحمد'),
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
  _FakeAuthRepository(super.api, {this.worker = false});

  final bool worker;

  @override
  Future<LoginResult> login({
    required String emailOrPhone,
    required String password,
    int? workspaceId,
    String deviceName = 'حاسم Flutter',
    String deviceType = 'mobile',
  }) async {
    return LoginResult(
      token: 'token',
      user: _user,
      workspace: worker ? null : _workspace,
      workspaces: worker ? const [] : const [_workspace],
      participant: MobileParticipant(
        type: worker ? 'independent_worker' : 'company_staff',
      ),
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
      workspace: worker ? null : _workspace,
      workspaces: worker ? const <WorkspaceModel>[] : const [_workspace],
      participant: MobileParticipant(
        type: worker ? 'independent_worker' : 'company_staff',
      ),
    );
  }
}

class _RecordingRepo extends ConversationRepository {
  _RecordingRepo(super.api, this._store) : super(local: _store);

  final InboxLocalStore _store;
  ConversationModel item = _conv();
  Object? error;
  Completer<void>? gate;
  int classificationCalls = 0;
  int handoffCalls = 0;
  int assignCalls = 0;
  Map<String, dynamic>? lastClassification;
  String? lastHandoffReason;
  int? lastAssignUserId;
  int? lastAssignWorkerEngagementId;

  Future<void> _hold() async {
    final hold = gate;
    if (hold != null) await hold.future;
    final thrown = error;
    if (thrown != null) {
      if (thrown is Exception) throw thrown;
      throw ApiException(thrown.toString());
    }
  }

  @override
  Future<ConversationModel> getById(int id, {int? workspaceId}) async {
    if (workspaceId != null) {
      await _store.upsertConversations(workspaceId, [item]);
    }
    return item;
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
  Future<void> markRead(
    int conversationId, {
    int? messageId,
    int? workspaceId,
  }) async {}

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
    if (workspaceId != null) {
      await _store.upsertConversations(workspaceId, [item]);
    }
    return (items: [item], nextCursor: null);
  }

  @override
  Future<ConversationModel> updateClassification(
    int conversationId, {
    required String customerPriority,
    required String paymentStatus,
    required String reviewStatus,
    int? workspaceId,
  }) async {
    classificationCalls++;
    lastClassification = {
      'customer_priority': customerPriority,
      'payment_status': paymentStatus,
      'review_status': reviewStatus,
    };
    await _hold();
    item = item.copyWith(
      customerPriority: customerPriority,
      paymentStatus: paymentStatus,
      reviewStatus: reviewStatus,
      classificationSource: 'manual',
    );
    return ConversationModel(
      id: item.id,
      channel: item.channel,
      status: item.status,
      customerPriority: customerPriority,
      paymentStatus: paymentStatus,
      reviewStatus: reviewStatus,
      classificationSource: 'manual',
      customer: item.customer,
    );
  }

  @override
  Future<ConversationModel> requestHandoff(
    int conversationId, {
    String? reason,
    int? workspaceId,
  }) async {
    handoffCalls++;
    lastHandoffReason = reason;
    await _hold();
    item = item.copyWith(
      handoffStatus: 'queued',
      handoffReason: reason,
      aiEnabled: false,
    );
    return ConversationModel(
      id: item.id,
      channel: item.channel,
      status: item.status,
      handoffStatus: 'queued',
      handoffReason: reason,
      aiEnabled: false,
      customer: item.customer,
    );
  }

  @override
  Future<ConversationModel> assignHandoff(
    int conversationId, {
    int? userId,
    int? workerEngagementId,
    int? workspaceId,
  }) async {
    assignCalls++;
    lastAssignUserId = userId;
    lastAssignWorkerEngagementId = workerEngagementId;
    await _hold();
    item = item.copyWith(
      handoffStatus: 'assigned',
      assignedUserId: userId,
      assignedWorkerEngagementId: workerEngagementId,
      assignedUserName: 'موظف الشركة',
    );
    return ConversationModel(
      id: item.id,
      channel: item.channel,
      status: item.status,
      handoffStatus: 'assigned',
      assignedUserId: userId,
      assignedWorkerEngagementId: workerEngagementId,
      assignedUserName: 'موظف الشركة',
      customer: item.customer,
    );
  }
}

class _FakeHomeRepository extends HomeRepository {
  _FakeHomeRepository(super.api);
  @override
  Future<HomeSnapshot> home() async => const HomeSnapshot();
}

class _FakeWorkerDiscoveryRepository extends CompanyWorkerDiscoveryRepository {
  _FakeWorkerDiscoveryRepository(super.api);

  @override
  Future<List<CompanyWorkerOpenEngagement>> fetchOpenEngagements() async {
    return const [
      CompanyWorkerOpenEngagement(
        id: 22,
        workerProfileId: 4,
        status: 'active',
        workerDisplayName: 'عامل الدعم',
        jobTitle: 'دعم العملاء',
      ),
    ];
  }
}

class _SeededAuthController extends AuthController {
  _SeededAuthController(super.ref, this.seed);
  final AuthState seed;
  @override
  Future<void> bootstrap() async {
    state = seed;
  }
}

class _Harness {
  _Harness({required this.container, required this.db, required this.repo});
  final ProviderContainer container;
  final InboxDatabase db;
  final _RecordingRepo repo;
}

Future<_Harness> _openController({
  ConversationModel? item,
  bool worker = false,
}) async {
  SharedPreferences.setMockInitialValues(
    worker ? <String, Object>{} : {'workspace_id': 1},
  );
  final rawPrefs = await SharedPreferences.getInstance();
  final prefs = PrefsStore(rawPrefs);
  final secure = _MemorySecureStore();
  final api = isolatedTestApiClient(secureStore: secure, prefsStore: prefs);
  final db = InboxDatabase.memory();
  final store = InboxLocalStore(db);
  final repo = _RecordingRepo(api, store)..item = item ?? _conv();
  final session = InboxSession();
  if (!worker) {
    session.beginWorkspace(1);
  }
  final container = ProviderContainer(
    overrides: [
      sharedPrefsProvider.overrideWithValue(rawPrefs),
      secureStoreProvider.overrideWithValue(secure),
      apiClientProvider.overrideWithValue(api),
      inboxDatabaseProvider.overrideWithValue(db),
      inboxLocalStoreProvider.overrideWithValue(store),
      inboxSessionProvider.overrideWithValue(session),
      authRepositoryProvider.overrideWithValue(
        _FakeAuthRepository(api, worker: worker),
      ),
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
  return _Harness(container: container, db: db, repo: repo);
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  GoogleFonts.config.allowRuntimeFetching = false;

  const companyAuth = AuthState(
    bootstrapping: false,
    token: 'company-token',
    user: _user,
    workspace: _workspace,
    participant: MobileParticipant(type: 'company_staff'),
  );
  const workerAuth = AuthState(
    bootstrapping: false,
    token: 'worker-token',
    user: UserModel(id: 3, name: 'عامل'),
    participant: MobileParticipant(type: 'independent_worker'),
  );

  Future<void> waitUntil(bool Function() ready) async {
    for (var i = 0; i < 50; i++) {
      if (ready()) return;
      await Future<void>.delayed(const Duration(milliseconds: 20));
    }
  }

  test('open conversations can be assigned without a prior handoff request', () {
    expect(ConversationHandoffValues.canAssign(_conv(handoff: 'none')), isTrue);
    expect(ConversationHandoffValues.canRequest(_conv(handoff: 'none')), isTrue);
    expect(
      ConversationHandoffValues.canAssign(_conv(handoff: 'assigned')),
      isFalse,
    );
  });

  test(
    'direct worker assignment of an open conversation updates chat, list, and local store',
    () async {
      final h = await _openController(item: _conv(handoff: 'none'));
      addTearDown(() async {
        h.container.dispose();
        await h.db.close();
      });
      h.container.read(conversationsControllerProvider);
      h.container.read(chatControllerProvider(7));
      await waitUntil(
        () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
      );
      await waitUntil(
        () => h.container
            .read(conversationsControllerProvider)
            .items
            .any((c) => c.id == 7),
      );

      final error = await h.container
          .read(chatControllerProvider(7).notifier)
          .assignHandoff(workerEngagementId: 22);
      expect(error, isNull);
      expect(h.repo.assignCalls, 1);
      expect(h.repo.lastAssignUserId, isNull);
      expect(h.repo.lastAssignWorkerEngagementId, 22);

      final chat = h.container.read(chatControllerProvider(7)).conversation;
      expect(chat?.handoffStatus, 'assigned');
      expect(chat?.assignedWorkerEngagementId, 22);
      expect(
        h.container
            .read(conversationsControllerProvider)
            .items
            .single
            .handoffStatus,
        'assigned',
      );
      expect(
        h.container
            .read(conversationsControllerProvider)
            .items
            .single
            .assignedWorkerEngagementId,
        22,
      );

      final stored = await h.repo.cachedConversation(1, 7);
      expect(stored?.handoffStatus, 'assigned');
      expect(stored?.assignedWorkerEngagementId, 22);
      expect(await h.repo.cachedConversation(2, 7), isNull);
    },
  );

  test(
    'failed worker assignment of an open conversation keeps the previous state',
    () async {
      final h = await _openController(item: _conv(handoff: 'none'));
      addTearDown(() async {
        h.container.dispose();
        await h.db.close();
      });
      h.repo.error = ApiException('تعذر تعيين المحادثة.');
      h.container.read(chatControllerProvider(7));
      await waitUntil(
        () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
      );

      final error = await h.container
          .read(chatControllerProvider(7).notifier)
          .assignHandoff(workerEngagementId: 22);
      expect(error, 'تعذر تعيين المحادثة.');
      expect(
        h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
        'none',
      );
      expect(
        h.container
            .read(chatControllerProvider(7))
            .conversation
            ?.assignedWorkerEngagementId,
        isNull,
      );
      expect(
        h.container.read(chatControllerProvider(7)).conversation?.assignedUserId,
        isNull,
      );
    },
  );

  test('classification write updates chat and list from the server', () async {
    final h = await _openController(item: _conv(unread: 4));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.container.read(conversationsControllerProvider);
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    await waitUntil(
      () => h.container
          .read(conversationsControllerProvider)
          .items
          .any((c) => c.id == 7),
    );

    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .updateClassification(
          customerPriority: 'important',
          paymentStatus: 'paid',
          reviewStatus: 'needs_review',
        );
    expect(error, isNull);
    expect(h.repo.classificationCalls, 1);
    expect(h.repo.lastClassification, {
      'customer_priority': 'important',
      'payment_status': 'paid',
      'review_status': 'needs_review',
    });
    final chat = h.container.read(chatControllerProvider(7));
    expect(chat.conversation?.customerPriority, 'important');
    expect(chat.conversation?.unreadCount, 4);
    expect(chat.mutating, isFalse);
    expect(
      h.container
          .read(conversationsControllerProvider)
          .items
          .single
          .customerPriority,
      'important',
    );
  });

  test('classification failure keeps previous values', () async {
    final h = await _openController(item: _conv(priority: 'normal'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.repo.error = ApiException('قيمة التصنيف غير مسموحة.');
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .updateClassification(
          customerPriority: 'important',
          paymentStatus: 'paid',
          reviewStatus: 'needs_review',
        );
    expect(error, 'قيمة التصنيف غير مسموحة.');
    expect(
      h.container
          .read(chatControllerProvider(7))
          .conversation
          ?.customerPriority,
      'normal',
    );
    expect(h.container.read(chatControllerProvider(7)).mutating, isFalse);
  });

  test('duplicate classification while loading is ignored', () async {
    final h = await _openController();
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.repo.gate = Completer<void>();
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final first = h.container
        .read(chatControllerProvider(7).notifier)
        .updateClassification(
          customerPriority: 'important',
          paymentStatus: 'paid',
          reviewStatus: 'needs_review',
        );
    await Future<void>.delayed(const Duration(milliseconds: 10));
    final second = await h.container
        .read(chatControllerProvider(7).notifier)
        .updateClassification(
          customerPriority: 'not_important',
          paymentStatus: 'unpaid',
          reviewStatus: 'no_review_needed',
        );
    expect(second, isNull);
    h.repo.gate!.complete();
    expect(await first, isNull);
    expect(h.repo.classificationCalls, 1);
  });

  test('handoff request updates status from the server', () async {
    final h = await _openController(item: _conv(handoff: 'none'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .requestHandoff(reason: 'payment_issue');
    expect(error, isNull);
    expect(h.repo.handoffCalls, 1);
    expect(h.repo.lastHandoffReason, 'payment_issue');
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'queued',
    );
  });

  test('handoff request failure keeps previous status', () async {
    final h = await _openController(item: _conv(handoff: 'none'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.repo.error = ApiException(
      'Cannot assign handoff before it is requested.',
    );
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .requestHandoff(reason: 'agent_requested');
    expect(error, isNotNull);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'none',
    );
  });

  test('duplicate handoff request while loading is ignored', () async {
    final h = await _openController(item: _conv(handoff: 'none'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.repo.gate = Completer<void>();
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final first = h.container
        .read(chatControllerProvider(7).notifier)
        .requestHandoff(reason: 'agent_requested');
    await Future<void>.delayed(const Duration(milliseconds: 10));
    await h.container
        .read(chatControllerProvider(7).notifier)
        .requestHandoff(reason: 'payment_issue');
    h.repo.gate!.complete();
    await first;
    expect(h.repo.handoffCalls, 1);
  });

  test('handoff assign updates assigned user from the server', () async {
    final h = await _openController(item: _conv(handoff: 'queued'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .assignHandoff(userId: 9);
    expect(error, isNull);
    expect(h.repo.assignCalls, 1);
    expect(h.repo.lastAssignUserId, 9);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.assignedUserId,
      9,
    );
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'assigned',
    );
  });

  test('handoff assign failure keeps previous assignment', () async {
    final h = await _openController(item: _conv(handoff: 'queued'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.repo.error = ApiException(
      'Cannot assign handoff before it is requested.',
    );
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .assignHandoff(userId: 9);
    expect(error, isNotNull);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.assignedUserId,
      isNull,
    );
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'queued',
    );
  });

  test('duplicate assign while loading is ignored', () async {
    final h = await _openController(item: _conv(handoff: 'queued'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.repo.gate = Completer<void>();
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final first = h.container
        .read(chatControllerProvider(7).notifier)
        .assignHandoff(userId: 9);
    await Future<void>.delayed(const Duration(milliseconds: 10));
    await h.container
        .read(chatControllerProvider(7).notifier)
        .assignHandoff(userId: 9);
    h.repo.gate!.complete();
    await first;
    expect(h.repo.assignCalls, 1);
  });

  test(
    'independent worker cannot call classification or handoff writes',
    () async {
      final h = await _openController(
        item: _conv(handoff: 'queued'),
        worker: true,
      );
      addTearDown(() async {
        h.container.dispose();
        await h.db.close();
      });
      h.container.read(chatControllerProvider(7));
      await waitUntil(
        () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
      );
      final classify = await h.container
          .read(chatControllerProvider(7).notifier)
          .updateClassification(
            customerPriority: 'important',
            paymentStatus: 'paid',
            reviewStatus: 'needs_review',
          );
      final request = await h.container
          .read(chatControllerProvider(7).notifier)
          .requestHandoff(reason: 'agent_requested');
      final assign = await h.container
          .read(chatControllerProvider(7).notifier)
          .assignHandoff(userId: 9);
      expect(classify, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(request, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(assign, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(h.repo.classificationCalls, 0);
      expect(h.repo.handoffCalls, 0);
      expect(h.repo.assignCalls, 0);
    },
  );

  test('rejected classification values never hit the API', () async {
    final h = await _openController();
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .updateClassification(
          customerPriority: 'vip',
          paymentStatus: 'paid',
          reviewStatus: 'needs_review',
        );
    expect(error, 'قيمة التصنيف غير مسموحة.');
    expect(h.repo.classificationCalls, 0);
  });

  Widget chatApp({
    required _RecordingRepo repo,
    required SharedPreferences prefs,
    required AuthState auth,
  }) {
    final session = InboxSession();
    final workspaceId = auth.workspace?.id;
    if (workspaceId != null) {
      session.beginWorkspace(workspaceId);
    }
    return ProviderScope(
      overrides: [
        authControllerProvider.overrideWith(
          (ref) => _SeededAuthController(ref, auth),
        ),
        sharedPrefsProvider.overrideWithValue(prefs),
        secureStoreProvider.overrideWithValue(_MemorySecureStore()),
        apiClientProvider.overrideWithValue(
          isolatedTestApiClient(
            secureStore: _MemorySecureStore(),
            prefsStore: PrefsStore(prefs),
          ),
        ),
        inboxSessionProvider.overrideWithValue(session),
        conversationRepositoryProvider.overrideWithValue(repo),
        companyWorkerDiscoveryRepositoryProvider.overrideWithValue(
          _FakeWorkerDiscoveryRepository(
            isolatedTestApiClient(
              secureStore: _MemorySecureStore(),
              prefsStore: PrefsStore(prefs),
            ),
          ),
        ),
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      ],
      child: MaterialApp(
        theme: AppTheme.light(),
        locale: const Locale('ar'),
        supportedLocales: AppLocalizations.supportedLocales,
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        home: const ChatScreen(conversationId: 7),
      ),
    );
  }

  void setPhone(WidgetTester tester) {
    tester.view.physicalSize = const Size(400, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.resetPhysicalSize);
    addTearDown(tester.view.resetDevicePixelRatio);
  }

  Future<void> openMore(WidgetTester tester) async {
    await tester.tap(find.byIcon(Icons.more_vert_rounded));
    await tester.pumpAndSettle();
    await tester.tap(find.text('المزيد'));
    await tester.pumpAndSettle();
  }

  testWidgets('company chat shows current classification and saves it', (
    tester,
  ) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final repo =
        _RecordingRepo(
            isolatedTestApiClient(
              secureStore: _MemorySecureStore(),
              prefsStore: PrefsStore(prefs),
            ),
            InboxLocalStore(db),
          )
          ..item = _conv(
            priority: 'important',
            payment: 'paid',
            review: 'needs_review',
          );

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();

    await openMore(tester);
    expect(find.textContaining('تصنيف المحادثة'), findsOneWidget);
    expect(find.textContaining('مهم'), findsOneWidget);
    await tester.tap(find.byKey(const Key('chat-action-classify')));
    await tester.pumpAndSettle();

    expect(find.text('مهم'), findsWidgets);
    await tester.tap(find.byKey(const Key('classification-normal')));
    await tester.tap(find.byKey(const Key('classification-save')));
    await tester.pumpAndSettle();

    expect(repo.classificationCalls, 1);
    expect(repo.lastClassification?['customer_priority'], 'normal');
  });

  testWidgets('classification save is ignored while already submitting', (
    tester,
  ) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final repo = _RecordingRepo(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
      InboxLocalStore(db),
    )..gate = Completer<void>();

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-classify')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('classification-save')));
    await tester.pump();
    await tester.tap(find.byKey(const Key('classification-save')));
    await tester.pump();
    expect(repo.classificationCalls, 1);
    repo.gate!.complete();
    await tester.pumpAndSettle();
  });

  testWidgets('company chat requests handoff with a contract reason', (
    tester,
  ) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final repo = _RecordingRepo(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
      InboxLocalStore(db),
    )..item = _conv(handoff: 'none');

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-request-handoff')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('handoff-reason-payment_issue')));
    await tester.tap(find.byKey(const Key('handoff-request-submit')));
    await tester.pumpAndSettle();
    expect(repo.handoffCalls, 1);
    expect(repo.lastHandoffReason, 'payment_issue');
  });

  testWidgets('handoff request shows backend failure', (tester) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final repo = _RecordingRepo(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
      InboxLocalStore(db),
    )..error = ApiException('تعذر طلب التحويل.');

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-request-handoff')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('handoff-request-submit')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('handoff-request-error')), findsOneWidget);
    expect(find.text('تعذر طلب التحويل.'), findsOneWidget);
  });

  testWidgets('company chat assigns from session member target', (
    tester,
  ) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final repo = _RecordingRepo(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
      InboxLocalStore(db),
    )..item = _conv(handoff: 'queued');

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-assign-handoff')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('handoff-assign-target-9')), findsOneWidget);
    await tester.tap(find.byKey(const Key('handoff-assign-target-9')));
    await tester.pumpAndSettle();
    expect(repo.assignCalls, 1);
    expect(repo.lastAssignUserId, 9);
  });

  testWidgets('company chat assigns an active worker engagement', (
    tester,
  ) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final repo = _RecordingRepo(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
      InboxLocalStore(db),
    )..item = _conv(handoff: 'queued');

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-assign-handoff')));
    await tester.pumpAndSettle();
    expect(
      find.byKey(const Key('handoff-assign-target-engagement-22')),
      findsOneWidget,
    );
    await tester.tap(
      find.byKey(const Key('handoff-assign-target-engagement-22')),
    );
    await tester.pumpAndSettle();

    expect(repo.assignCalls, 1);
    expect(repo.lastAssignUserId, isNull);
    expect(repo.lastAssignWorkerEngagementId, 22);
  });

  testWidgets(
    'company can assign an open conversation to a worker without requesting handoff',
    (tester) async {
      setPhone(tester);
      SharedPreferences.setMockInitialValues({'workspace_id': 1});
      final prefs = await SharedPreferences.getInstance();
      final db = InboxDatabase.memory();
      addTearDown(db.close);
      final repo = _RecordingRepo(
        isolatedTestApiClient(
          secureStore: _MemorySecureStore(),
          prefsStore: PrefsStore(prefs),
        ),
        InboxLocalStore(db),
      )..item = _conv(handoff: 'none');

      await tester.pumpWidget(
        chatApp(repo: repo, prefs: prefs, auth: companyAuth),
      );
      await tester.pumpAndSettle();
      await openMore(tester);
      expect(find.byKey(const Key('chat-action-assign-handoff')), findsOneWidget);
      await tester.tap(find.byKey(const Key('chat-action-assign-handoff')));
      await tester.pumpAndSettle();
      await tester.tap(
        find.byKey(const Key('handoff-assign-target-engagement-22')),
      );
      await tester.pumpAndSettle();

      expect(repo.assignCalls, 1);
      expect(repo.lastAssignWorkerEngagementId, 22);
      expect(repo.lastAssignUserId, isNull);
      expect(find.text('تم تعيين المحادثة'), findsOneWidget);
      final container = ProviderScope.containerOf(
        tester.element(find.byType(ChatScreen)),
      );
      expect(
        container.read(chatControllerProvider(7)).conversation?.handoffStatus,
        'assigned',
      );
      expect(
        container
            .read(chatControllerProvider(7))
            .conversation
            ?.assignedWorkerEngagementId,
        22,
      );
    },
  );

  testWidgets(
    'failed open-conversation assignment keeps none and shows the error',
    (tester) async {
      setPhone(tester);
      SharedPreferences.setMockInitialValues({'workspace_id': 1});
      final prefs = await SharedPreferences.getInstance();
      final db = InboxDatabase.memory();
      addTearDown(db.close);
      final repo =
          _RecordingRepo(
              isolatedTestApiClient(
                secureStore: _MemorySecureStore(),
                prefsStore: PrefsStore(prefs),
              ),
              InboxLocalStore(db),
            )
            ..item = _conv(handoff: 'none')
            ..error = ApiException('تعذر تعيين المحادثة.');

      await tester.pumpWidget(
        chatApp(repo: repo, prefs: prefs, auth: companyAuth),
      );
      await tester.pumpAndSettle();
      await openMore(tester);
      await tester.tap(find.byKey(const Key('chat-action-assign-handoff')));
      await tester.pumpAndSettle();
      await tester.tap(
        find.byKey(const Key('handoff-assign-target-engagement-22')),
      );
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('handoff-assign-error')), findsOneWidget);
      expect(find.text('تعذر تعيين المحادثة.'), findsOneWidget);
      expect(find.text('تم تعيين المحادثة'), findsNothing);
      final container = ProviderScope.containerOf(
        tester.element(find.byType(ChatScreen)),
      );
      expect(
        container.read(chatControllerProvider(7)).conversation?.handoffStatus,
        'none',
      );
    },
  );

  testWidgets('handoff assign shows backend failure', (tester) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final repo =
        _RecordingRepo(
            isolatedTestApiClient(
              secureStore: _MemorySecureStore(),
              prefsStore: PrefsStore(prefs),
            ),
            InboxLocalStore(db),
          )
          ..item = _conv(handoff: 'queued')
          ..error = ApiException('تعذر تعيين المحادثة.');

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-assign-handoff')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('handoff-assign-target-9')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('handoff-assign-error')), findsOneWidget);
    expect(find.text('تعذر تعيين المحادثة.'), findsOneWidget);
  });

  testWidgets(
    'independent worker does not get classification or handoff writes',
    (tester) async {
      setPhone(tester);
      SharedPreferences.setMockInitialValues({});
      final prefs = await SharedPreferences.getInstance();
      final db = InboxDatabase.memory();
      addTearDown(db.close);
      final repo = _RecordingRepo(
        isolatedTestApiClient(
          secureStore: _MemorySecureStore(),
          prefsStore: PrefsStore(prefs),
        ),
        InboxLocalStore(db),
      )..item = _conv(handoff: 'queued');

      await tester.pumpWidget(
        chatApp(repo: repo, prefs: prefs, auth: workerAuth),
      );
      await tester.pumpAndSettle();
      await openMore(tester);
      expect(find.byKey(const Key('chat-action-classify')), findsNothing);
      expect(
        find.byKey(const Key('chat-action-request-handoff')),
        findsNothing,
      );
      expect(find.byKey(const Key('chat-action-assign-handoff')), findsNothing);
      expect(find.text('تولي المحادثة'), findsNothing);
      expect(repo.classificationCalls, 0);
      expect(repo.handoffCalls, 0);
      expect(repo.assignCalls, 0);
    },
  );

  testWidgets('classification sheet shows backend failure', (tester) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final repo = _RecordingRepo(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
      InboxLocalStore(db),
    )..error = ApiException('تعذر تحديث التصنيف.');

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-classify')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('classification-save')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('classification-error')), findsOneWidget);
    expect(find.text('تعذر تحديث التصنيف.'), findsOneWidget);
  });
}
