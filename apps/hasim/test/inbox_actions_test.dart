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
  String status = 'open',
  bool aiEnabled = false,
  bool archived = false,
  int unread = 3,
  int? assignedUserId,
}) {
  return ConversationModel(
    id: 7,
    channel: 'whatsapp',
    status: status,
    unreadCount: unread,
    customerPriority: priority,
    paymentStatus: payment,
    reviewStatus: review,
    handoffStatus: handoff,
    assignedUserId: assignedUserId,
    aiEnabled: aiEnabled,
    archived: archived,
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
  int resolveCalls = 0;
  int resumeAiCalls = 0;
  int setAiEnabledCalls = 0;
  int takeoverCalls = 0;
  bool? lastAiEnabled;
  Map<String, dynamic>? lastClassification;
  String? lastHandoffReason;
  int? lastAssignUserId;
  int? lastAssignWorkerEngagementId;
  List<MessageModel> thread = const [];

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
    if (workspaceId != null && thread.isNotEmpty) {
      await _store.upsertMessages(workspaceId, conversationId, thread);
    }
    return (items: thread.reversed.toList(), nextCursor: null);
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
    String? handoff,
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

  @override
  Future<ConversationModel> takeoverHandoff(
    int conversationId, {
    int? workspaceId,
  }) async {
    takeoverCalls++;
    await _hold();
    item = item.copyWith(
      handoffStatus: 'active',
      assignedUserId: 9,
      assignedUserName: 'موظف الشركة',
      aiEnabled: false,
    );
    return ConversationModel(
      id: item.id,
      channel: item.channel,
      status: item.status,
      handoffStatus: 'active',
      assignedUserId: 9,
      assignedUserName: 'موظف الشركة',
      aiEnabled: false,
      customer: item.customer,
    );
  }

  @override
  Future<ConversationModel> resolveHandoff(
    int conversationId, {
    int? workspaceId,
  }) async {
    resolveCalls++;
    await _hold();
    item = item.copyWith(handoffStatus: 'resolved', aiEnabled: false);
    return ConversationModel(
      id: item.id,
      channel: item.channel,
      status: item.status,
      handoffStatus: 'resolved',
      aiEnabled: false,
      customer: item.customer,
    );
  }

  @override
  Future<ConversationModel> resumeAi(
    int conversationId, {
    int? workspaceId,
  }) async {
    resumeAiCalls++;
    await _hold();
    item = item.copyWith(handoffStatus: 'none', aiEnabled: true);
    return ConversationModel(
      id: item.id,
      channel: item.channel,
      status: item.status,
      handoffStatus: 'none',
      aiEnabled: true,
      customer: item.customer,
    );
  }

  @override
  Future<ConversationModel> setAiEnabled(
    int conversationId, {
    required bool enabled,
    int? workspaceId,
  }) async {
    setAiEnabledCalls++;
    lastAiEnabled = enabled;
    await _hold();
    item = item.copyWith(aiEnabled: enabled);
    return ConversationModel(
      id: item.id,
      channel: item.channel,
      status: item.status,
      handoffStatus: item.handoffStatus,
      aiEnabled: enabled,
      customer: item.customer,
    );
  }
}

class _FakeHomeRepository extends HomeRepository {
  _FakeHomeRepository(super.api);
  @override
  Future<HomeSnapshot> home() async => const HomeSnapshot();
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

  test(
    'open conversations can be assigned without a prior handoff request',
    () {
      expect(
        ConversationHandoffValues.canAssign(_conv(handoff: 'none')),
        isTrue,
      );
      expect(
        ConversationHandoffValues.canRequest(_conv(handoff: 'none')),
        isTrue,
      );
      expect(
        ConversationHandoffValues.canAssign(_conv(handoff: 'assigned')),
        isFalse,
      );
    },
  );

  test('closed conversations cannot request assign resolve or resume AI', () {
    final closed = _conv(handoff: 'queued', status: 'closed');
    expect(ConversationHandoffValues.isOpen(closed), isFalse);
    expect(ConversationHandoffValues.canRequest(closed), isFalse);
    expect(ConversationHandoffValues.canAssign(closed), isFalse);
    expect(ConversationHandoffValues.canTakeover(closed), isFalse);
    expect(ConversationHandoffValues.canResolve(closed), isFalse);
    expect(ConversationHandoffValues.canResumeAi(closed), isFalse);
  });

  test('archived conversations cannot takeover or resume AI', () {
    final archived = _conv(
      handoff: 'resolved',
      status: 'open',
      archived: true,
      aiEnabled: false,
    );
    expect(ConversationHandoffValues.isOpen(archived), isFalse);
    expect(ConversationHandoffValues.canTakeover(archived), isFalse);
    expect(ConversationHandoffValues.canResumeAi(archived), isFalse);
  });

  test('resolve and resume AI follow open conversation handoff states', () {
    expect(
      ConversationHandoffValues.canResolve(_conv(handoff: 'queued')),
      isTrue,
    );
    expect(
      ConversationHandoffValues.canResolve(_conv(handoff: 'none')),
      isFalse,
    );
    expect(
      ConversationHandoffValues.canResumeAi(
        _conv(handoff: 'resolved', aiEnabled: false),
      ),
      isTrue,
    );
    expect(
      ConversationHandoffValues.canResumeAi(
        _conv(handoff: 'queued', aiEnabled: false),
      ),
      isFalse,
    );
    expect(
      ConversationHandoffValues.canEndHandoff(_conv(handoff: 'queued')),
      isTrue,
    );
    expect(
      ConversationHandoffValues.canEndHandoff(
        _conv(handoff: 'none', aiEnabled: true),
      ),
      isTrue,
    );
    expect(
      ConversationHandoffValues.canEndHandoff(
        _conv(handoff: 'resolved', aiEnabled: true),
      ),
      isFalse,
    );
    expect(
      ConversationHandoffValues.canResumeAi(
        _conv(handoff: 'none', aiEnabled: true),
      ),
      isFalse,
    );
    expect(
      ConversationHandoffValues.isAiReplying(
        _conv(handoff: 'none', aiEnabled: true),
      ),
      isTrue,
    );
    expect(
      ConversationHandoffValues.isAiReplying(
        _conv(handoff: 'queued', aiEnabled: true),
      ),
      isFalse,
    );
    expect(
      ConversationHandoffValues.isAiReplying(
        _conv(handoff: 'none', status: 'closed', aiEnabled: true),
      ),
      isFalse,
    );
    expect(
      ConversationHandoffValues.isAiReplying(
        _conv(handoff: 'resolved', aiEnabled: true),
      ),
      isFalse,
    );
    expect(
      ConversationHandoffValues.canResumeAi(
        _conv(handoff: 'resolved', aiEnabled: true),
      ),
      isTrue,
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
        h.container
            .read(chatControllerProvider(7))
            .conversation
            ?.assignedUserId,
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

  test('resolve handoff updates status from the server', () async {
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
        .resolveHandoff();
    expect(error, isNull);
    expect(h.repo.resolveCalls, 1);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'resolved',
    );
  });

  test('ending handoff from an AI reply resolves in one action', () async {
    final h = await _openController(
      item: _conv(handoff: 'none', aiEnabled: true),
    );
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
        .endHandoff();
    expect(error, isNull);
    expect(h.repo.takeoverCalls, 1);
    expect(h.repo.resolveCalls, 1);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'resolved',
    );
  });

  test('ending an open review resolves without taking over again', () async {
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
        .endHandoff();
    expect(error, isNull);
    expect(h.repo.takeoverCalls, 0);
    expect(h.repo.resolveCalls, 1);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'resolved',
    );
  });

  test('ending handoff stops when takeover fails', () async {
    final h = await _openController(
      item: _conv(handoff: 'none', aiEnabled: true),
    );
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.repo.error = ApiException(
      'Handoff is only allowed on open conversations.',
    );
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .endHandoff();
    expect(error, isNotNull);
    expect(h.repo.takeoverCalls, 1);
    expect(h.repo.resolveCalls, 0);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'none',
    );
  });

  test('resolve handoff failure keeps previous status', () async {
    final h = await _openController(item: _conv(handoff: 'queued'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.repo.error = ApiException(
      'Handoff is only allowed on open conversations.',
    );
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .resolveHandoff();
    expect(error, 'التحويل مسموح فقط في المحادثات المفتوحة.');
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'queued',
    );
  });

  test('rejected handoff refreshes stale conversation status', () async {
    final h = await _openController(item: _conv(handoff: 'none'));
    addTearDown(() async {
      h.container.dispose();
      await h.db.close();
    });
    h.container.read(chatControllerProvider(7));
    await waitUntil(
      () => h.container.read(chatControllerProvider(7)).conversation?.id == 7,
    );
    h.repo.item = _conv(status: 'closed');
    h.repo.error = ApiException(
      'Handoff is only allowed on open conversations.',
    );

    final error = await h.container
        .read(chatControllerProvider(7).notifier)
        .takeoverHandoff();

    expect(error, 'التحويل مسموح فقط في المحادثات المفتوحة.');
    await waitUntil(
      () =>
          h.container.read(chatControllerProvider(7)).conversation?.status ==
          'closed',
    );
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'none',
    );
  });

  test('resume AI updates enabled flag from the server', () async {
    final h = await _openController(
      item: _conv(handoff: 'resolved', aiEnabled: false),
    );
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
        .resumeAi();
    expect(error, isNull);
    expect(h.repo.resumeAiCalls, 1);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.aiEnabled,
      isTrue,
    );
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'none',
    );
  });

  test('setAiEnabled updates the enabled flag from the server', () async {
    final h = await _openController(
      item: _conv(handoff: 'none', aiEnabled: true),
    );
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
        .setAiEnabled(enabled: false);
    expect(error, isNull);
    expect(h.repo.setAiEnabledCalls, 1);
    expect(h.repo.lastAiEnabled, isFalse);
    expect(
      h.container.read(chatControllerProvider(7)).conversation?.aiEnabled,
      isFalse,
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
      final resolve = await h.container
          .read(chatControllerProvider(7).notifier)
          .resolveHandoff();
      final resume = await h.container
          .read(chatControllerProvider(7).notifier)
          .resumeAi();
      final toggleAi = await h.container
          .read(chatControllerProvider(7).notifier)
          .setAiEnabled(enabled: true);
      final takeover = await h.container
          .read(chatControllerProvider(7).notifier)
          .takeoverHandoff();
      expect(classify, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(request, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(assign, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(resolve, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(resume, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(toggleAi, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(takeover, 'لا تملك صلاحية تنفيذ هذا الإجراء.');
      expect(h.repo.classificationCalls, 0);
      expect(h.repo.handoffCalls, 0);
      expect(h.repo.assignCalls, 0);
      expect(h.repo.resolveCalls, 0);
      expect(h.repo.resumeAiCalls, 0);
      expect(h.repo.setAiEnabledCalls, 0);
      expect(h.repo.takeoverCalls, 0);
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
    ConversationModel? initialConversation,
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
        realtimeServiceProvider.overrideWithValue(NoopRealtimeService()),
      ],
      child: MaterialApp(
        theme: AppTheme.light(),
        locale: const Locale('ar'),
        supportedLocales: AppLocalizations.supportedLocales,
        localizationsDelegates: AppLocalizations.localizationsDelegates,
        home: ChatScreen(
          conversationId: 7,
          initialConversation: initialConversation,
        ),
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

  testWidgets('chat screen shows the customer message and the AI reply together', (
    tester,
  ) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    const customer = MessageModel(
      id: 21,
      conversationId: 7,
      direction: 'inbound',
      messageType: 'text',
      content: 'مرحبا من العميل',
      clientId: 'same-key',
    );
    const reply = MessageModel(
      id: 22,
      conversationId: 7,
      direction: 'outbound',
      messageType: 'text',
      content: 'رد الذكاء',
      aiGenerated: true,
      clientId: 'same-key',
    );
    final initial = _conv(unread: 1);
    final repo = _RecordingRepo(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
      InboxLocalStore(db),
    )
      ..item = initial
      ..thread = const [customer, reply];

    await tester.pumpWidget(
      chatApp(
        repo: repo,
        prefs: prefs,
        auth: companyAuth,
        initialConversation: initial,
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('مرحبا من العميل'), findsOneWidget);
    expect(find.text('رد الذكاء'), findsOneWidget);
    final customerBox = tester.getRect(find.text('مرحبا من العميل'));
    final replyBox = tester.getRect(find.text('رد الذكاء'));
    expect(customerBox.top, lessThan(replyBox.top));
  });

  testWidgets('opening chat with a list seed does not throw during build', (
    tester,
  ) async {
    setPhone(tester);
    SharedPreferences.setMockInitialValues({'workspace_id': 1});
    final prefs = await SharedPreferences.getInstance();
    final db = InboxDatabase.memory();
    addTearDown(db.close);
    final initial = _conv(unread: 2);
    final repo = _RecordingRepo(
      isolatedTestApiClient(
        secureStore: _MemorySecureStore(),
        prefsStore: PrefsStore(prefs),
      ),
      InboxLocalStore(db),
    )..item = initial;

    await tester.pumpWidget(
      chatApp(
        repo: repo,
        prefs: prefs,
        auth: companyAuth,
        initialConversation: initial,
      ),
    );
    await tester.pump();
    expect(tester.takeException(), isNull);
    await tester.pumpAndSettle();
    expect(find.byType(ChatScreen), findsOneWidget);
  });

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

  testWidgets('inbox hides independent worker conversion actions', (
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
    expect(find.byKey(const Key('chat-action-assign-handoff')), findsNothing);
    expect(find.byKey(const Key('chat-action-request-handoff')), findsNothing);
    expect(find.byKey(const Key('chat-action-takeover')), findsNothing);
    expect(find.text('تعيين عامل مستقل'), findsNothing);
    expect(find.text('تعيين المحادثة'), findsNothing);
    expect(find.text('تولي المحادثة'), findsNothing);
    expect(find.text('تحويل إلى AI'), findsOneWidget);
    expect(find.byKey(const Key('chat-action-resume-ai')), findsOneWidget);
    expect(find.byKey(const Key('chat-action-toggle-ai')), findsNothing);
  });

  testWidgets('company chat converts an open conversation to AI', (
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
    )..item = _conv(handoff: 'resolved', aiEnabled: false);

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    expect(find.byKey(const Key('chat-action-resume-ai')), findsOneWidget);
    expect(find.text('تحويل إلى AI'), findsOneWidget);
    expect(find.byKey(const Key('chat-action-end-handoff')), findsNothing);
    expect(find.byKey(const Key('chat-action-toggle-ai')), findsNothing);
    await tester.tap(find.byKey(const Key('chat-action-resume-ai')));
    await tester.pumpAndSettle();
    expect(repo.resumeAiCalls, 1);
    expect(find.text('تم تحديث حالة التحويل'), findsOneWidget);
    final container = ProviderScope.containerOf(
      tester.element(find.byType(ChatScreen)),
    );
    expect(
      container.read(chatControllerProvider(7)).conversation?.aiEnabled,
      isTrue,
    );
    expect(
      container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'none',
    );
  });

  testWidgets('convert to AI does not show success before backend confirms', (
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
          ..item = _conv(handoff: 'resolved', aiEnabled: false)
          ..error = ApiException(
            'Handoff is only allowed on open conversations.',
          );

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-resume-ai')));
    await tester.pumpAndSettle();
    expect(repo.resumeAiCalls, 1);
    expect(find.text('تم تحديث حالة التحويل'), findsNothing);
    expect(
      find.text('التحويل مسموح فقط في المحادثات المفتوحة.'),
      findsOneWidget,
    );
    final container = ProviderScope.containerOf(
      tester.element(find.byType(ChatScreen)),
    );
    expect(
      container.read(chatControllerProvider(7)).conversation?.aiEnabled,
      isFalse,
    );
  });

  testWidgets(
    'resolved conversation with stale AI flag hides badge and offers convert to AI',
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
      )..item = _conv(handoff: 'resolved', aiEnabled: true);

      await tester.pumpWidget(
        chatApp(repo: repo, prefs: prefs, auth: companyAuth),
      );
      await tester.pumpAndSettle();
      await openMore(tester);
      expect(find.text('رد AI'), findsNothing);
      expect(find.byKey(const Key('chat-action-resume-ai')), findsOneWidget);
      expect(find.text('تحويل إلى AI'), findsOneWidget);
      expect(find.byKey(const Key('chat-action-end-handoff')), findsNothing);
      expect(find.byKey(const Key('chat-action-toggle-ai')), findsNothing);
      expect(find.text('تعيين عامل مستقل'), findsNothing);
      expect(repo.resumeAiCalls, 0);
      expect(repo.takeoverCalls, 0);
    },
  );

  testWidgets('company chat ends an active handoff', (tester) async {
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
    expect(find.byKey(const Key('chat-team-review')), findsOneWidget);
    expect(find.text('تحتاج مراجعة من الفريق'), findsOneWidget);
    await openMore(tester);
    expect(find.text('إنهاء التحويل'), findsOneWidget);
    expect(find.byKey(const Key('chat-action-resume-ai')), findsNothing);
    expect(find.byKey(const Key('chat-action-toggle-ai')), findsNothing);
    expect(find.text('تعيين عامل مستقل'), findsNothing);
    await tester.tap(find.byKey(const Key('chat-action-end-handoff')));
    await tester.pumpAndSettle();
    expect(repo.resolveCalls, 1);
    expect(repo.takeoverCalls, 0);
    expect(find.text('تم تحديث حالة التحويل'), findsOneWidget);
    final container = ProviderScope.containerOf(
      tester.element(find.byType(ChatScreen)),
    );
    expect(
      container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'resolved',
    );
  });

  testWidgets('company chat ends an AI reply in one press', (
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
    )..item = _conv(handoff: 'none', aiEnabled: true);

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('chat-team-review')), findsNothing);
    await openMore(tester);
    expect(find.byKey(const Key('chat-action-resume-ai')), findsNothing);
    expect(find.byKey(const Key('chat-action-toggle-ai')), findsNothing);
    expect(find.text('إنهاء التحويل'), findsOneWidget);
    await tester.tap(find.byKey(const Key('chat-action-end-handoff')));
    await tester.pumpAndSettle();
    expect(repo.takeoverCalls, 1);
    expect(repo.resolveCalls, 1);
    expect(find.text('تم تحديث حالة التحويل'), findsOneWidget);
    final container = ProviderScope.containerOf(
      tester.element(find.byType(ChatScreen)),
    );
    expect(
      container.read(chatControllerProvider(7)).conversation?.aiEnabled,
      isFalse,
    );
    expect(
      container.read(chatControllerProvider(7)).conversation?.handoffStatus,
      'resolved',
    );
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
      expect(find.byKey(const Key('chat-action-end-handoff')), findsNothing);
      expect(find.byKey(const Key('chat-action-resume-ai')), findsNothing);
      expect(find.byKey(const Key('chat-action-toggle-ai')), findsNothing);
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

  testWidgets('closed conversation hides resolve and resume AI actions', (
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
    )..item = _conv(handoff: 'queued', status: 'closed', aiEnabled: false);

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    expect(find.byKey(const Key('chat-action-end-handoff')), findsNothing);
    expect(find.byKey(const Key('chat-action-resume-ai')), findsNothing);
    expect(find.byKey(const Key('chat-action-takeover')), findsNothing);
    expect(find.byKey(const Key('chat-action-toggle-ai')), findsNothing);
    expect(repo.resolveCalls, 0);
    expect(repo.resumeAiCalls, 0);
    expect(repo.setAiEnabledCalls, 0);
  });

  testWidgets('archived conversation hides convert to AI and end handoff', (
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
            handoff: 'resolved',
            status: 'open',
            archived: true,
            aiEnabled: false,
          );

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    expect(find.byKey(const Key('chat-action-end-handoff')), findsNothing);
    expect(find.byKey(const Key('chat-action-resume-ai')), findsNothing);
    expect(find.byKey(const Key('chat-action-toggle-ai')), findsNothing);
  });

  testWidgets('resolve handoff shows backend failure', (tester) async {
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
          ..error = ApiException(
            'Handoff is only allowed on open conversations.',
          );

    await tester.pumpWidget(
      chatApp(repo: repo, prefs: prefs, auth: companyAuth),
    );
    await tester.pumpAndSettle();
    await openMore(tester);
    await tester.tap(find.byKey(const Key('chat-action-end-handoff')));
    await tester.pumpAndSettle();
    expect(repo.resolveCalls, 1);
    expect(find.text('تم تحديث حالة التحويل'), findsNothing);
    expect(
      find.text('التحويل مسموح فقط في المحادثات المفتوحة.'),
      findsOneWidget,
    );
  });
}
