import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/core/utils/idempotency.dart';
import 'package:hasim/features/conversations/data/chat_attachment_policy.dart';
import 'package:hasim/features/conversations/data/conversation_classification.dart';
import 'package:hasim/features/conversations/data/conversation_handoff.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
import 'package:hasim/features/privacy/chat_lock_view.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';
import 'package:hasim/realtime/realtime_service.dart';

class ConversationsState {
  const ConversationsState({
    this.loading = false,
    this.loadingMore = false,
    this.items = const [],
    this.nextCursor,
    this.filter = 'all',
    this.channel,
    this.search = '',
    this.customerPriority,
    this.paymentStatus,
    this.reviewStatus,
    this.error,
    this.mutatingConversationId,
  });

  final bool loading;
  final bool loadingMore;
  final List<ConversationModel> items;
  final String? nextCursor;
  final String filter;
  final String? channel;
  final String search;
  final String? customerPriority;
  final String? paymentStatus;
  final String? reviewStatus;
  final String? error;
  final int? mutatingConversationId;

  ConversationsState copyWith({
    bool? loading,
    bool? loadingMore,
    List<ConversationModel>? items,
    String? nextCursor,
    String? filter,
    String? channel,
    bool clearChannel = false,
    String? search,
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
    bool clearClassification = false,
    String? error,
    bool clearCursor = false,
    int? mutatingConversationId,
    bool clearMutating = false,
  }) {
    return ConversationsState(
      loading: loading ?? this.loading,
      loadingMore: loadingMore ?? this.loadingMore,
      items: items ?? this.items,
      nextCursor: clearCursor ? nextCursor : (nextCursor ?? this.nextCursor),
      filter: filter ?? this.filter,
      channel: clearChannel ? channel : (channel ?? this.channel),
      search: search ?? this.search,
      customerPriority: clearClassification
          ? customerPriority
          : (customerPriority ?? this.customerPriority),
      paymentStatus: clearClassification
          ? paymentStatus
          : (paymentStatus ?? this.paymentStatus),
      reviewStatus: clearClassification
          ? reviewStatus
          : (reviewStatus ?? this.reviewStatus),
      error: error,
      mutatingConversationId: clearMutating
          ? null
          : (mutatingConversationId ?? this.mutatingConversationId),
    );
  }
}

class ConversationsController extends StateNotifier<ConversationsState> {
  ConversationsController(this._ref, {this.fixedFilter})
    : super(ConversationsState(filter: fixedFilter ?? 'all', loading: true)) {
    _boundWorkspaceId = _ref.read(activeWorkspaceIdProvider);
    _sub = _ref
        .read(realtimeServiceProvider)
        .conversationsChanged
        .listen((_) => refresh(silent: true));
    _workspaceSub = _ref.listen<int?>(activeWorkspaceIdProvider, (
      previous,
      next,
    ) {
      if (previous == next) return;
      _boundWorkspaceId = next;
      _prefetchedIds.clear();
      state = ConversationsState(filter: fixedFilter ?? 'all', loading: true);
      _bindLocalQuery();
      unawaited(refresh());
    });
    _lockSub = _ref.listen<Set<int>>(lockedConversationIdsProvider, (
      previous,
      next,
    ) {
      if (previous == next) return;
      _bindLocalQuery();
    });
    _bindLocalQuery();
    unawaited(refresh());
  }

  final Ref _ref;

  /// صفحة مستقلة (المؤرشفة أو الدردشات المقفلة) لا تبدّل فلتر الوارد.
  final String? fixedFilter;

  StreamSubscription? _sub;
  StreamSubscription<List<ConversationModel>>? _localSub;
  ProviderSubscription<int?>? _workspaceSub;
  ProviderSubscription<Set<int>>? _lockSub;
  int? _boundWorkspaceId;
  final Set<int> _prefetchedIds = <int>{};

  void _bindLocalQuery() {
    _localSub?.cancel();
    final workspaceId = _boundWorkspaceId;
    if (workspaceId == null) return;
    _localSub = _ref
        .read(conversationRepositoryProvider)
        .watchConversations(
          workspaceId,
          filter: _remoteFilter,
          channel: state.channel,
          search: state.search,
          customerPriority: state.customerPriority,
          paymentStatus: state.paymentStatus,
          reviewStatus: state.reviewStatus,
        )
        .listen((rows) {
          if (!mounted || _boundWorkspaceId != workspaceId) return;
          _publishLocal(rows);
          if (rows.isNotEmpty) _prefetchOpenThreads(rows);
        });
  }

  Future<void> _publishFetchedPage(
    InboxScope scope,
    List<ConversationModel> fetched, {
    bool append = false,
  }) async {
    final workspaceId = scope.workspaceId;
    if (workspaceId != null) {
      final local = await _ref
          .read(conversationRepositoryProvider)
          .cachedConversations(
            workspaceId,
            filter: _remoteFilter,
            channel: state.channel,
            search: state.search,
            customerPriority: state.customerPriority,
            paymentStatus: state.paymentStatus,
            reviewStatus: state.reviewStatus,
          );
      if (!_canCommit(scope)) return;
      if (local.isNotEmpty) {
        _publishLocal(local);
        return;
      }
    }
    if (!_canCommit(scope)) return;
    if (!append) {
      _publishLocal(fetched);
      return;
    }
    final seen = <int>{};
    final merged = <ConversationModel>[];
    for (final item in [...state.items, ...fetched]) {
      if (seen.add(item.id)) merged.add(item);
    }
    _publishLocal(merged);
  }

  void _publishLocal(List<ConversationModel> rows) {
    final visible = fixedFilter == null
        ? _applyChatLock(rows)
        : _applyChatLock(rows).where(_matchesCurrentFilter).toList();
    state = state.copyWith(
      items: visible,
      loading: visible.isEmpty && state.loading,
    );
  }

  @override
  void dispose() {
    _sub?.cancel();
    _localSub?.cancel();
    _workspaceSub?.close();
    _lockSub?.close();
    super.dispose();
  }

  void _prefetchOpenThreads(List<ConversationModel> items) {
    final workspaceId = _boundWorkspaceId;
    if (workspaceId == null || items.isEmpty) return;
    final repo = _ref.read(conversationRepositoryProvider);
    var started = 0;
    for (final item in items) {
      if (started >= 4) break;
      if (!_prefetchedIds.add(item.id)) continue;
      started++;
      unawaited(_warmMessageCache(repo, workspaceId, item.id));
    }
  }

  Future<void> _warmMessageCache(
    ConversationRepository repo,
    int workspaceId,
    int conversationId,
  ) async {
    try {
      final cached = await repo.cachedMessages(workspaceId, conversationId);
      if (cached.isNotEmpty) return;
      await repo.messages(conversationId, workspaceId: workspaceId);
    } catch (_) {
      _prefetchedIds.remove(conversationId);
    }
  }

  Future<void> setFilter(String filter) async {
    if (fixedFilter != null) return;
    state = state.copyWith(filter: filter);
    _bindLocalQuery();
    await refresh();
  }

  Future<void> setChannel(String? channel, {bool resetFilter = false}) async {
    state = state.copyWith(
      channel: channel,
      clearChannel: true,
      filter: resetFilter ? 'all' : null,
    );
    _bindLocalQuery();
    await refresh();
  }

  /// يصفّر العداد في القائمة فور فتح المحادثة (قبل/بعد markRead من الخادم).
  void clearUnreadInList(int conversationId) {
    final items = state.items;
    final index = items.indexWhere((c) => c.id == conversationId);
    if (index < 0 || items[index].unreadCount == 0) return;
    final next = [...items];
    next[index] = next[index].copyWith(unreadCount: 0);
    state = state.copyWith(items: next);
  }

  Future<void> setSearch(String search) async {
    state = state.copyWith(search: search);
    _bindLocalQuery();
    await refresh();
  }

  Future<void> toggleClassification({
    String? customerPriority,
    String? paymentStatus,
    String? reviewStatus,
  }) async {
    final nextPriority = customerPriority == null
        ? state.customerPriority
        : (state.customerPriority == customerPriority
              ? null
              : customerPriority);
    final nextPayment = paymentStatus == null
        ? state.paymentStatus
        : (state.paymentStatus == paymentStatus ? null : paymentStatus);
    final nextReview = reviewStatus == null
        ? state.reviewStatus
        : (state.reviewStatus == reviewStatus ? null : reviewStatus);
    state = state.copyWith(
      clearClassification: true,
      customerPriority: nextPriority,
      paymentStatus: nextPayment,
      reviewStatus: nextReview,
    );
    _bindLocalQuery();
    await refresh();
  }

  Future<void> refresh({bool silent = false}) async {
    final scope = captureInboxScope(_ref);
    if (_ref.read(authControllerProvider).isIndependentWorker &&
        scope.workspaceId == null) {
      if (!silent) {
        state = state.copyWith(loading: true, error: null);
      }
      return;
    }
    if (!silent && state.items.isEmpty) {
      state = state.copyWith(
        loading: true,
        error: null,
        clearCursor: true,
        nextCursor: null,
      );
    }
    try {
      final repo = _ref.read(conversationRepositoryProvider);
      final page = await repo.list(
        filter: _remoteFilter,
        channel: state.channel,
        search: state.search,
        customerPriority: state.customerPriority,
        paymentStatus: state.paymentStatus,
        reviewStatus: state.reviewStatus,
        workspaceId: scope.workspaceId,
      );
      if (!_canCommit(scope)) return;
      state = state.copyWith(
        loading: false,
        nextCursor: silent ? null : page.nextCursor,
        clearCursor: !silent,
        error: repo.lastReadFromCache
            ? 'تعذر التحديث. تُعرض البيانات المحفوظة على الجهاز.'
            : null,
      );
      if (!_canCommit(scope)) return;
      await _publishFetchedPage(scope, page.items);
      _prefetchOpenThreads(state.items);
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(
        loading: false,
        error: state.items.isEmpty
            ? e.userMessage
            : 'تعذر التحديث. تُعرض البيانات المحفوظة على الجهاز.',
      );
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(
        loading: false,
        error: state.items.isEmpty
            ? 'تعذر تحميل المحادثات.'
            : 'تعذر التحديث. تُعرض البيانات المحفوظة على الجهاز.',
      );
    }
  }

  Future<void> loadMore() async {
    final cursor = state.nextCursor;
    if (cursor == null || state.loadingMore) return;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(loadingMore: true);
    try {
      final page = await _ref
          .read(conversationRepositoryProvider)
          .list(
            filter: _remoteFilter,
            channel: state.channel,
            search: state.search,
            customerPriority: state.customerPriority,
            paymentStatus: state.paymentStatus,
            reviewStatus: state.reviewStatus,
            cursor: cursor,
            workspaceId: scope.workspaceId,
          );
      if (!_canCommit(scope)) return;
      if (!_canCommit(scope)) return;
      await _publishFetchedPage(scope, page.items, append: true);
      state = state.copyWith(
        loadingMore: false,
        nextCursor: page.nextCursor,
        clearCursor: true,
      );
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loadingMore: false);
    }
  }

  bool _canCommit(InboxScope scope) {
    if (!mounted) return false;
    return inboxScopeIsCurrent(_ref, scope);
  }

  String get _remoteFilter => state.filter == 'locked' ? 'all' : state.filter;

  List<ConversationModel> _applyChatLock(List<ConversationModel> items) {
    return applyChatLockView(
      items: items,
      lockedIds: _ref.read(lockedConversationIdsProvider),
      filter: state.filter,
    );
  }

  bool _matchesCurrentFilter(ConversationModel conversation) {
    final lockedIds = _ref.read(lockedConversationIdsProvider);
    if (state.filter == 'locked') return lockedIds.contains(conversation.id);
    if (lockedIds.contains(conversation.id)) return false;
    if (state.filter == 'archived') return conversation.archived;
    if (conversation.archived) return false;
    if (state.filter == 'unread') return conversation.unreadCount > 0;
    return true;
  }

  Future<String?> setArchived(int conversationId, {required bool archived}) {
    return _mutateConversation(
      conversationId,
      apply: (repo, workspaceId) => repo.archive(
        conversationId,
        archived: archived,
        workspaceId: workspaceId,
      ),
      update: (conversation) => conversation.copyWith(
        archived: archived,
        status: archived ? 'archived' : 'open',
      ),
    );
  }

  Future<String?> setMuted(int conversationId, {required bool muted}) {
    return _mutateConversation(
      conversationId,
      apply: (repo, workspaceId) =>
          repo.mute(conversationId, muted: muted, workspaceId: workspaceId),
      update: (conversation) => conversation.copyWith(muted: muted),
    );
  }

  Future<String?> _mutateConversation(
    int conversationId, {
    required Future<void> Function(
      ConversationRepository repo,
      int? workspaceId,
    )
    apply,
    required ConversationModel Function(ConversationModel conversation) update,
  }) async {
    if (state.mutatingConversationId != null) return null;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(mutatingConversationId: conversationId, error: null);
    try {
      final repo = _ref.read(conversationRepositoryProvider);
      await apply(repo, scope.workspaceId);
      if (!_canCommit(scope)) return null;
      final current = state.items.where((item) => item.id == conversationId);
      if (scope.workspaceId != null && current.isNotEmpty) {
        await repo.persistConversations(scope.workspaceId!, [
          update(current.first),
        ]);
      }
      if (!_canCommit(scope)) return null;
      final next = [
        for (final item in state.items)
          if (item.id == conversationId) update(item) else item,
      ].where(_matchesCurrentFilter).toList();
      if (!_canCommit(scope)) return null;
      state = state.copyWith(items: next, clearMutating: true);
      bumpInboxLocalRevision(_ref);
      unawaited(refresh(silent: true));
      return null;
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return e.message;
      state = state.copyWith(clearMutating: true);
      return e.message;
    } catch (_) {
      if (!_canCommit(scope)) return 'تعذر تحديث المحادثة.';
      state = state.copyWith(clearMutating: true);
      return 'تعذر تحديث المحادثة.';
    }
  }

  void applyRemoteConversation(ConversationModel conversation) {
    final next = [
      for (final item in state.items)
        if (item.id == conversation.id)
          item.applyingServerWrites(conversation)
        else
          item,
    ].where(_matchesCurrentFilter).toList();
    state = state.copyWith(items: next);
  }
}

final conversationsControllerProvider =
    StateNotifierProvider<ConversationsController, ConversationsState>(
      (ref) => ConversationsController(ref),
    );

/// قائمة صفحة المؤرشفة أو الدردشات المقفلة. لا تغيّر فلتر صفحة المحادثات.
final inboxFolderControllerProvider = StateNotifierProvider.autoDispose
    .family<ConversationsController, ConversationsState, String>((ref, folder) {
      return ConversationsController(ref, fixedFilter: folder);
    });

class ChatState {
  const ChatState({
    this.loading = true,
    this.sending = false,
    this.loadingOlder = false,
    this.conversation,
    this.messages = const [],
    this.nextCursor,
    this.error,
    this.aiBusy = false,
    this.mutating = false,
  });

  final bool loading;
  final bool sending;
  final bool loadingOlder;
  final ConversationModel? conversation;
  final List<MessageModel> messages;
  final String? nextCursor;
  final String? error;
  final bool aiBusy;
  final bool mutating;

  ChatState copyWith({
    bool? loading,
    bool? sending,
    bool? loadingOlder,
    ConversationModel? conversation,
    List<MessageModel>? messages,
    String? nextCursor,
    String? error,
    bool? aiBusy,
    bool? mutating,
    bool clearCursor = false,
  }) {
    return ChatState(
      loading: loading ?? this.loading,
      sending: sending ?? this.sending,
      loadingOlder: loadingOlder ?? this.loadingOlder,
      conversation: conversation ?? this.conversation,
      messages: messages ?? this.messages,
      nextCursor: clearCursor ? nextCursor : (nextCursor ?? this.nextCursor),
      error: error,
      aiBusy: aiBusy ?? this.aiBusy,
      mutating: mutating ?? this.mutating,
    );
  }
}

class ChatController extends StateNotifier<ChatState> {
  ChatController(this._ref, this.conversationId) : super(const ChatState()) {
    _realtime = _ref.read(realtimeServiceProvider);
    _boundWorkspaceId = _ref.read(activeWorkspaceIdProvider);
    _workspaceSub = _ref.listen<int?>(activeWorkspaceIdProvider, (
      previous,
      next,
    ) {
      if (previous == next) return;
      _boundWorkspaceId = next;
      unawaited(_init());
    });
    unawaited(_init());
  }

  final Ref _ref;
  final int conversationId;
  late final RealtimeService _realtime;
  StreamSubscription? _sub;
  StreamSubscription<List<MessageModel>>? _messageSub;
  ProviderSubscription<int?>? _workspaceSub;
  int? _boundWorkspaceId;
  List<MessageModel> _olderMessages = const [];

  Future<void> _init() async {
    await _sub?.cancel();
    _sub = null;
    await _messageSub?.cancel();
    _messageSub = null;
    _olderMessages = const [];
    final workspaceId = _boundWorkspaceId;
    _realtime.watchConversation(conversationId);
    _sub = _realtime.messageCreated.listen((m) {
      if (m.conversationId != conversationId) return;
      unawaited(_ingestPolledMessage(m));
    });
    final repo = _ref.read(conversationRepositoryProvider);
    if (workspaceId != null) {
      _messageSub = repo
          .watchLatestMessages(
            workspaceId,
            conversationId,
            limit: InboxLocalStore.liveMessageWindow,
          )
          .listen((latest) {
            if (!mounted || _boundWorkspaceId != workspaceId) return;
            final messages = _mergeMessages(_olderMessages, latest);
            state = state.copyWith(
              messages: messages,
              loading: messages.isEmpty && state.loading,
            );
          });
    }
    final seeded = workspaceId == null
        ? null
        : await repo.cachedConversation(workspaceId, conversationId);
    if (!mounted || _boundWorkspaceId != workspaceId) return;
    if (seeded != null) {
      state = state.copyWith(
        conversation: seeded,
        loading: state.messages.isEmpty,
      );
    } else if (state.messages.isEmpty) {
      state = state.copyWith(loading: true);
    }
    await refresh(silent: state.messages.isNotEmpty || seeded != null);
  }

  void seedConversation(ConversationModel conversation) {
    if (state.conversation != null) return;
    state = state.copyWith(
      conversation: conversation,
      loading: state.messages.isEmpty,
    );
  }

  Future<void> _persistOpenedRead(
    InboxScope scope,
    int? workspaceId,
    ConversationModel conversation,
  ) async {
    await Future<void>.delayed(const Duration(milliseconds: 16));
    if (!mounted || !_canCommit(scope)) return;
    try {
      final repo = _ref.read(conversationRepositoryProvider);
      if (workspaceId != null) {
        await repo.markReadLocal(workspaceId, conversationId);
      }
      _clearUnreadAfterSend();
      bumpInboxLocalRevision(_ref);
      try {
        await repo.markRead(conversationId, workspaceId: workspaceId);
      } catch (_) {
        // Local unread is already cleared because the user opened the thread.
      }
      if (!mounted || !_canCommit(scope) || workspaceId == null) return;
      final cached = await repo.cachedConversation(workspaceId, conversationId);
      if (!mounted || !_canCommit(scope)) return;
      state = state.copyWith(
        conversation: cached == null
            ? conversation.copyWith(unreadCount: 0)
            : conversation.overlayingLocalInbox(cached),
      );
    } catch (_) {}
  }

  List<MessageModel> _mergeMessages(
    List<MessageModel> older,
    List<MessageModel> latest,
  ) {
    final byKey = <String, MessageModel>{};
    for (final message in [...older, ...latest]) {
      final clientId = message.clientId;
      final key = clientId != null && clientId.isNotEmpty
          ? 'c:$clientId'
          : 's:${message.id}';
      byKey[key] = message;
    }
    final merged = byKey.values.toList();
    merged.sort((a, b) {
      final at = a.createdAt;
      final bt = b.createdAt;
      if (at == null && bt == null) return a.id.compareTo(b.id);
      if (at == null) return 1;
      if (bt == null) return -1;
      final byTime = at.compareTo(bt);
      if (byTime != 0) return byTime;
      return a.id.compareTo(b.id);
    });
    return merged;
  }

  @override
  void dispose() {
    _sub?.cancel();
    _messageSub?.cancel();
    _workspaceSub?.close();
    _realtime.watchConversation(null);
    super.dispose();
  }

  Future<void> refresh({bool silent = false}) async {
    final scope = captureInboxScope(_ref);
    if (!silent) {
      state = state.copyWith(loading: true, error: null);
    } else {
      state = state.copyWith(error: null);
    }
    try {
      final repo = _ref.read(conversationRepositoryProvider);
      final conversationFuture = repo.getById(
        conversationId,
        workspaceId: scope.workspaceId,
      );
      final messagesFuture = repo.messages(
        conversationId,
        workspaceId: scope.workspaceId,
      );
      final conversation = await conversationFuture;
      final page = await messagesFuture;
      if (!_canCommit(scope)) return;
      final workspaceId = scope.workspaceId;
      final fetched = page.items.reversed.toList();
      var messages = fetched;
      if (workspaceId != null) {
        final cached = await repo.cachedMessages(workspaceId, conversationId);
        if (!_canCommit(scope)) return;
        messages = _mergeMessages(
          _olderMessages,
          _mergeMessages(cached, fetched),
        );
      }
      if (messages.isEmpty && state.messages.isNotEmpty) {
        messages = state.messages;
      }
      if (!_canCommit(scope)) return;
      state = state.copyWith(
        loading: false,
        conversation: conversation,
        messages: messages,
        nextCursor: page.nextCursor,
        clearCursor: true,
        error: repo.lastReadFromCache
            ? 'تعذر التحديث. تُعرض البيانات المحفوظة على الجهاز.'
            : null,
      );
      unawaited(_persistOpenedRead(scope, workspaceId, conversation));
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loading: false, error: e.userMessage);
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loading: false, error: 'تعذر فتح المحادثة.');
    }
  }

  Future<void> loadOlder() async {
    if (state.loadingOlder) return;
    final cursor = state.nextCursor;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(loadingOlder: true);
    try {
      final workspaceId = scope.workspaceId;
      final oldest = state.messages.isEmpty
          ? null
          : state.messages.first.createdAt;
      if (workspaceId != null && oldest != null) {
        final older = await _ref
            .read(conversationRepositoryProvider)
            .cachedOlderMessages(workspaceId, conversationId, before: oldest);
        if (!_canCommit(scope)) return;
        if (older.isNotEmpty) {
          _olderMessages = _mergeMessages(older, _olderMessages);
          state = state.copyWith(
            messages: _mergeMessages(_olderMessages, state.messages),
          );
        }
      }
      if (cursor == null) {
        if (!_canCommit(scope)) return;
        state = state.copyWith(loadingOlder: false);
        return;
      }
      final page = await _ref
          .read(conversationRepositoryProvider)
          .messages(
            conversationId,
            cursor: cursor,
            workspaceId: scope.workspaceId,
          );
      if (!_canCommit(scope)) return;
      if (workspaceId != null && oldest != null) {
        final older = await _ref
            .read(conversationRepositoryProvider)
            .cachedOlderMessages(workspaceId, conversationId, before: oldest);
        if (!_canCommit(scope)) return;
        _olderMessages = _mergeMessages(older, _olderMessages);
      }
      final latest = workspaceId == null
          ? <MessageModel>[]
          : await _ref
                .read(conversationRepositoryProvider)
                .cachedMessages(workspaceId, conversationId);
      if (!_canCommit(scope)) return;
      state = state.copyWith(
        loadingOlder: false,
        messages: workspaceId == null
            ? _mergeMessages(page.items.reversed.toList(), state.messages)
            : _mergeMessages(_olderMessages, latest),
        nextCursor: page.nextCursor,
        clearCursor: true,
      );
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loadingOlder: false);
    }
  }

  /// Returns false when the message was not accepted (for example no workspace).
  Future<bool> send(String content) async {
    final text = content.trim();
    if (text.isEmpty) return false;
    final clientId = newIdempotencyKey();
    final optimistic = MessageModel(
      id: -DateTime.now().millisecondsSinceEpoch,
      conversationId: conversationId,
      direction: 'outbound',
      messageType: 'text',
      content: text,
      createdAt: DateTime.now(),
      localPending: true,
      clientId: clientId,
    );
    final scope = captureInboxScope(_ref);
    final workspaceId = scope.workspaceId;
    if (workspaceId == null) {
      state = state.copyWith(
        sending: false,
        error: 'تعذر إرسال الرسالة. أعد اختيار مساحة العمل.',
      );
      return false;
    }

    state = state.copyWith(
      messages: _mergeMessages(_olderMessages, [...state.messages, optimistic]),
      sending: true,
      error: null,
    );

    final repo = _ref.read(conversationRepositoryProvider);
    try {
      await repo.enqueueOutboundText(
        workspaceId: workspaceId,
        conversationId: conversationId,
        message: optimistic,
      );
    } catch (_) {
      if (!_canCommit(scope)) return false;
      _replaceLocalMessage(
        clientId,
        optimistic.copyWith(localPending: false, localFailed: true),
      );
      state = state.copyWith(sending: false, error: 'تعذر إرسال الرسالة.');
      return false;
    }

    if (!_canCommit(scope)) return true;
    state = state.copyWith(sending: false, error: null);
    _clearUnreadAfterSend();
    unawaited(_persistReadAfterSend(workspaceId));
    unawaited(
      _flushTextSend(
        scope: scope,
        workspaceId: workspaceId,
        clientId: clientId,
      ),
    );
    return true;
  }

  void _clearUnreadAfterSend() {
    Future<void>.microtask(() {
      if (!mounted) return;
      _ref
          .read(conversationsControllerProvider.notifier)
          .clearUnreadInList(conversationId);
      final conversation = state.conversation;
      if (conversation != null && conversation.unreadCount != 0) {
        state = state.copyWith(
          conversation: conversation.copyWith(unreadCount: 0),
        );
      }
    });
  }

  Future<void> _persistReadAfterSend(int? workspaceId) async {
    if (workspaceId != null) {
      await _ref
          .read(conversationRepositoryProvider)
          .markReadLocal(workspaceId, conversationId);
    }
    bumpInboxLocalRevision(_ref);
    try {
      await _ref.read(conversationRepositoryProvider).markRead(
        conversationId,
        workspaceId: workspaceId,
      );
    } catch (_) {
      // Local unread is already cleared; the next open retries the server.
    }
  }

  Future<void> _flushTextSend({
    required InboxScope scope,
    required int workspaceId,
    required String clientId,
  }) async {
    try {
      await _ref.read(outboxWorkerProvider).flush(workspaceId: workspaceId);
      if (!_canCommit(scope)) return;
      final after = await _ref
          .read(conversationRepositoryProvider)
          .cachedMessages(workspaceId, conversationId);
      if (!_canCommit(scope)) return;
      final failed = after.any((m) => m.clientId == clientId && m.localFailed);
      state = state.copyWith(
        messages: after,
        error: failed ? 'تعذر إرسال الرسالة.' : null,
      );
    } catch (_) {
      // The outbox remains responsible for retrying transient send failures.
    }
  }

  Future<void> retryFailed(MessageModel message) async {
    if (!message.localFailed || message.clientId == null) return;
    if (message.localAttachmentPath != null) {
      await _retryAttachment(message);
      return;
    }
    final clientId = message.clientId!;
    final updatedPending = state.messages.map((m) {
      if (m.clientId == clientId) {
        return m.copyWith(localPending: true, localFailed: false);
      }
      return m;
    }).toList();
    final scope = captureInboxScope(_ref);
    final workspaceId = scope.workspaceId;
    if (workspaceId == null) return;
    final repo = _ref.read(conversationRepositoryProvider);
    state = state.copyWith(
      messages: updatedPending,
      sending: true,
      error: null,
    );
    await repo.retryOutbound(workspaceId: workspaceId, clientId: clientId);
    if (!_canCommit(scope)) return;
    final queued = await repo.cachedMessages(workspaceId, conversationId);
    if (!_canCommit(scope)) return;
    state = state.copyWith(messages: queued, sending: true);
    await _ref.read(outboxWorkerProvider).flush(workspaceId: workspaceId);
    if (!_canCommit(scope)) return;
    final after = await repo.cachedMessages(workspaceId, conversationId);
    if (!_canCommit(scope)) return;
    state = state.copyWith(messages: after, sending: false);
  }

  /// Returns an error token for localization, or null on success.
  Future<String?> sendAttachment(File file, {String caption = ''}) async {
    final validation = ChatAttachmentPolicy.validateFile(file);
    if (validation != null) return validation;
    if (!await _hasNetwork()) return 'offline';
    final sent = await sendThenAttach(
      file,
      caption: caption,
      clientId: newIdempotencyKey(),
    );
    if (sent == null) return 'uploadFailed';
    return null;
  }

  Future<String?> sendVoiceNote({
    required Uint8List bytes,
    required String filename,
    String? mime,
  }) async {
    final ext = filename.contains('.')
        ? filename.split('.').last.toLowerCase()
        : null;
    final validation = ChatAttachmentPolicy.validateBytes(bytes.length, ext);
    if (validation != null) return validation;
    if (!await _hasNetwork()) return 'offline';
    final sent = await sendThenAttachBytes(
      bytes,
      filename: filename,
      mime: mime,
      caption: '🎤',
    );
    if (sent == null) return 'uploadFailed';
    return null;
  }

  Future<MessageModel?> sendThenAttachBytes(
    Uint8List bytes, {
    required String filename,
    String? mime,
    String caption = '🎤',
  }) async {
    final text = caption.trim().isEmpty ? '🎤' : caption.trim();
    final key = newIdempotencyKey();
    final optimistic = MessageModel(
      id: -DateTime.now().millisecondsSinceEpoch,
      conversationId: conversationId,
      direction: 'outbound',
      messageType: 'text',
      content: text,
      createdAt: DateTime.now(),
      localPending: true,
      localUploading: false,
      clientId: key,
    );
    final scope = captureInboxScope(_ref);
    state = state.copyWith(
      messages: [...state.messages, optimistic],
      sending: true,
      error: null,
    );
    try {
      final repo = _ref.read(conversationRepositoryProvider);
      final saved = await repo.sendMessageWithKey(conversationId, text, key);
      if (!_canCommit(scope)) return null;
      _replaceLocalMessage(
        key,
        saved.copyWith(
          localPending: true,
          localUploading: true,
          localFailed: false,
          clientId: key,
        ),
      );
      final attachment = await repo.uploadAttachmentBytes(
        saved.id,
        bytes: bytes,
        filename: filename,
        mime: mime,
      );
      if (!_canCommit(scope)) return null;
      final withAttach = saved.copyWith(
        localPending: false,
        localUploading: false,
        localFailed: false,
        clientId: key,
        attachments: [...saved.attachments, attachment],
      );
      _replaceLocalMessage(key, withAttach);
      state = state.copyWith(sending: false);
      final workspaceId = scope.workspaceId;
      if (workspaceId != null) {
        await repo.persistMessages(workspaceId, conversationId, state.messages);
      }
      _clearUnreadAfterSend();
      unawaited(_persistReadAfterSend(workspaceId));
      return withAttach;
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[CHAT_VOICE_UPLOAD] failed error=$e');
      }
      if (!_canCommit(scope)) return null;
      final failed = state.messages.map((m) {
        if (m.clientId != key) return m;
        return m.copyWith(
          localPending: false,
          localUploading: false,
          localFailed: true,
        );
      }).toList();
      state = state.copyWith(messages: failed, sending: false);
      return null;
    }
  }

  Future<MessageModel?> sendThenAttach(
    File file, {
    String caption = '',
    String? clientId,
  }) async {
    final text = caption.trim().isEmpty ? '📎' : caption.trim();
    final key = clientId ?? newIdempotencyKey();
    final path = file.path;
    final optimistic = MessageModel(
      id: -DateTime.now().millisecondsSinceEpoch,
      conversationId: conversationId,
      direction: 'outbound',
      messageType: 'text',
      content: text,
      createdAt: DateTime.now(),
      localPending: true,
      localUploading: false,
      localAttachmentPath: path,
      clientId: key,
    );
    final scope = captureInboxScope(_ref);
    final existing = state.messages.any((m) => m.clientId == key);
    state = state.copyWith(
      messages: existing
          ? state.messages
                .map((m) => m.clientId == key ? optimistic : m)
                .toList()
          : [...state.messages, optimistic],
      sending: true,
      error: null,
    );
    try {
      final repo = _ref.read(conversationRepositoryProvider);
      final saved = await repo.sendMessageWithKey(conversationId, text, key);
      if (!_canCommit(scope)) return null;
      _replaceLocalMessage(
        key,
        saved.copyWith(
          localPending: true,
          localUploading: true,
          localFailed: false,
          localAttachmentPath: path,
          clientId: key,
        ),
      );
      final attachment = await repo.uploadAttachment(saved.id, file);
      if (!_canCommit(scope)) return null;
      final withAttach = saved.copyWith(
        localPending: false,
        localUploading: false,
        localFailed: false,
        clearLocalAttachmentPath: true,
        clientId: key,
        attachments: [...saved.attachments, attachment],
      );
      _replaceLocalMessage(key, withAttach);
      state = state.copyWith(sending: false);
      final workspaceId = scope.workspaceId;
      if (workspaceId != null) {
        await repo.persistMessages(workspaceId, conversationId, state.messages);
      }
      _clearUnreadAfterSend();
      unawaited(_persistReadAfterSend(workspaceId));
      return withAttach;
    } catch (e) {
      if (kDebugMode) {
        debugPrint('[CHAT_IMAGE_UPLOAD] failed error=$e');
      }
      if (!_canCommit(scope)) return null;
      final failed = state.messages.map((m) {
        if (m.clientId != key) return m;
        return m.copyWith(
          localPending: false,
          localUploading: false,
          localFailed: true,
          localAttachmentPath: path,
        );
      }).toList();
      state = state.copyWith(messages: failed, sending: false);
      return null;
    }
  }

  Future<void> _retryAttachment(MessageModel message) async {
    final path = message.localAttachmentPath;
    final clientId = message.clientId;
    if (path == null || clientId == null) return;
    final file = File(path);
    if (!file.existsSync()) {
      state = state.copyWith(error: 'missing');
      return;
    }
    if (!await _hasNetwork()) {
      state = state.copyWith(error: 'offline');
      return;
    }
    if (message.id > 0) {
      final scope = captureInboxScope(_ref);
      _replaceLocalMessage(
        clientId,
        message.copyWith(
          localPending: true,
          localUploading: true,
          localFailed: false,
        ),
      );
      state = state.copyWith(sending: true, error: null);
      try {
        final attachment = await _ref
            .read(conversationRepositoryProvider)
            .uploadAttachment(message.id, file);
        if (!_canCommit(scope)) return;
        final done = message.copyWith(
          localPending: false,
          localUploading: false,
          localFailed: false,
          clearLocalAttachmentPath: true,
          attachments: [...message.attachments, attachment],
        );
        _replaceLocalMessage(clientId, done);
        state = state.copyWith(sending: false);
        final workspaceId = scope.workspaceId;
        if (workspaceId != null) {
          await _ref
              .read(conversationRepositoryProvider)
              .persistMessages(workspaceId, conversationId, state.messages);
        }
      } catch (_) {
        if (!_canCommit(scope)) return;
        _replaceLocalMessage(
          clientId,
          message.copyWith(
            localPending: false,
            localUploading: false,
            localFailed: true,
          ),
        );
        state = state.copyWith(sending: false);
      }
      return;
    }
    await sendThenAttach(
      file,
      caption: message.content == '📎' ? '' : message.content,
      clientId: clientId,
    );
  }

  void _replaceLocalMessage(String clientId, MessageModel next) {
    state = state.copyWith(
      messages: state.messages
          .map((m) => m.clientId == clientId ? next : m)
          .toList(),
    );
  }

  Future<bool> _hasNetwork() async {
    try {
      final results = await Connectivity().checkConnectivity();
      return results.any((r) => r != ConnectivityResult.none);
    } catch (_) {
      return true;
    }
  }

  Future<String?> suggestReply() async {
    final scope = captureInboxScope(_ref);
    state = state.copyWith(aiBusy: true);
    try {
      final text = await _ref
          .read(aiRepositoryProvider)
          .suggestReply(conversationId);
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false);
      return text;
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false, error: e.userMessage);
      return null;
    } catch (_) {
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false, error: 'تعذر توليد اقتراح الرد.');
      return null;
    }
  }

  Future<String?> summarize() async {
    final scope = captureInboxScope(_ref);
    state = state.copyWith(aiBusy: true);
    try {
      final text = await _ref
          .read(aiRepositoryProvider)
          .summarize(conversationId);
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false);
      return text;
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false, error: e.userMessage);
      return null;
    } catch (_) {
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false, error: 'تعذر تلخيص المحادثة.');
      return null;
    }
  }

  Future<String?> setArchived({required bool archived}) {
    return _mutateConversation(
      apply: (repo, workspaceId) => repo.archive(
        conversationId,
        archived: archived,
        workspaceId: workspaceId,
      ),
      update: (conversation) => conversation.copyWith(
        archived: archived,
        status: archived ? 'archived' : 'open',
      ),
    );
  }

  Future<String?> setMuted({required bool muted}) {
    return _mutateConversation(
      apply: (repo, workspaceId) =>
          repo.mute(conversationId, muted: muted, workspaceId: workspaceId),
      update: (conversation) => conversation.copyWith(muted: muted),
    );
  }

  Future<String?> updateClassification({
    required String customerPriority,
    required String paymentStatus,
    required String reviewStatus,
  }) {
    if (!_companyInboxWriteAllowed()) {
      return Future.value('لا تملك صلاحية تنفيذ هذا الإجراء.');
    }
    if (!ConversationClassificationValues.isAllowedPriority(customerPriority) ||
        !ConversationClassificationValues.isAllowedPayment(paymentStatus) ||
        !ConversationClassificationValues.isAllowedReview(reviewStatus)) {
      return Future.value('قيمة التصنيف غير مسموحة.');
    }
    return _applyServerConversation(
      (repo, workspaceId) => repo.updateClassification(
        conversationId,
        customerPriority: customerPriority,
        paymentStatus: paymentStatus,
        reviewStatus: reviewStatus,
        workspaceId: workspaceId,
      ),
    );
  }

  Future<String?> requestHandoff({String? reason}) {
    if (!_companyInboxWriteAllowed()) {
      return Future.value('لا تملك صلاحية تنفيذ هذا الإجراء.');
    }
    if (reason != null &&
        reason.isNotEmpty &&
        !ConversationHandoffValues.isAllowedReason(reason)) {
      return Future.value('سبب التحويل غير مسموح.');
    }
    return _applyServerConversation(
      (repo, workspaceId) => repo.requestHandoff(
        conversationId,
        reason: reason,
        workspaceId: workspaceId,
      ),
    );
  }

  Future<String?> assignHandoff({int? userId, int? workerEngagementId}) {
    if (!_companyInboxWriteAllowed()) {
      return Future.value('لا تملك صلاحية تنفيذ هذا الإجراء.');
    }
    if ((userId == null) == (workerEngagementId == null)) {
      return Future.value('اختر موظفًا أو ارتباط عامل صالحًا.');
    }
    return _applyServerConversation(
      (repo, workspaceId) => repo.assignHandoff(
        conversationId,
        userId: userId,
        workerEngagementId: workerEngagementId,
        workspaceId: workspaceId,
      ),
    );
  }

  Future<String?> takeoverHandoff() {
    if (!_companyInboxWriteAllowed()) {
      return Future.value('لا تملك صلاحية تنفيذ هذا الإجراء.');
    }
    return _applyServerConversation(
      (repo, workspaceId) =>
          repo.takeoverHandoff(conversationId, workspaceId: workspaceId),
    );
  }

  Future<String?> resolveHandoff() {
    if (!_companyInboxWriteAllowed()) {
      return Future.value('لا تملك صلاحية تنفيذ هذا الإجراء.');
    }
    return _applyServerConversation(
      (repo, workspaceId) =>
          repo.resolveHandoff(conversationId, workspaceId: workspaceId),
    );
  }

  Future<String?> resumeAi() {
    if (!_companyInboxWriteAllowed()) {
      return Future.value('لا تملك صلاحية تنفيذ هذا الإجراء.');
    }
    return _applyServerConversation(
      (repo, workspaceId) =>
          repo.resumeAi(conversationId, workspaceId: workspaceId),
    );
  }

  bool _companyInboxWriteAllowed() {
    return _ref.read(authControllerProvider).isCompanyUser;
  }

  Future<String?> _applyServerConversation(
    Future<ConversationModel> Function(
      ConversationRepository repo,
      int? workspaceId,
    )
    apply,
  ) async {
    if (state.mutating) return null;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(mutating: true);
    try {
      final remote = await apply(
        _ref.read(conversationRepositoryProvider),
        scope.workspaceId,
      );
      if (!_canCommit(scope)) return null;
      final current = state.conversation;
      final next = current == null
          ? remote
          : current.applyingServerWrites(remote);
      if (scope.workspaceId != null) {
        await _ref.read(conversationRepositoryProvider).persistConversations(
          scope.workspaceId!,
          [next],
        );
      }
      if (!_canCommit(scope)) return null;
      state = state.copyWith(mutating: false, conversation: next);
      _ref
          .read(conversationsControllerProvider.notifier)
          .applyRemoteConversation(next);
      bumpInboxLocalRevision(_ref);
      unawaited(
        _ref
            .read(conversationsControllerProvider.notifier)
            .refresh(silent: true),
      );
      return null;
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return e.userMessage;
      state = state.copyWith(mutating: false);
      unawaited(_syncConversationFromServer(scope));
      return e.userMessage;
    } catch (_) {
      if (!_canCommit(scope)) return 'تعذر تحديث المحادثة.';
      state = state.copyWith(mutating: false);
      return 'تعذر تحديث المحادثة.';
    }
  }

  Future<void> _syncConversationFromServer(InboxScope scope) async {
    try {
      final remote = await _ref.read(conversationRepositoryProvider).getById(
        conversationId,
        workspaceId: scope.workspaceId,
      );
      if (!_canCommit(scope)) {
        return;
      }
      final current = state.conversation;
      final next = current == null
          ? remote
          : current.applyingServerWrites(remote);
      if (scope.workspaceId != null) {
        await _ref.read(conversationRepositoryProvider).persistConversations(
          scope.workspaceId!,
          [next],
        );
      }
      if (!_canCommit(scope)) {
        return;
      }
      state = state.copyWith(conversation: next);
      _ref
          .read(conversationsControllerProvider.notifier)
          .applyRemoteConversation(next);
    } catch (_) {}
  }

  Future<String?> _mutateConversation({
    required Future<void> Function(
      ConversationRepository repo,
      int? workspaceId,
    )
    apply,
    required ConversationModel Function(ConversationModel conversation) update,
  }) async {
    if (state.mutating) return null;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(mutating: true);
    try {
      await apply(_ref.read(conversationRepositoryProvider), scope.workspaceId);
      if (!_canCommit(scope)) return null;
      final conversation = state.conversation;
      state = state.copyWith(
        mutating: false,
        conversation: conversation == null ? null : update(conversation),
      );
      bumpInboxLocalRevision(_ref);
      unawaited(
        _ref
            .read(conversationsControllerProvider.notifier)
            .refresh(silent: true),
      );
      return null;
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return e.message;
      state = state.copyWith(mutating: false);
      return e.message;
    } catch (_) {
      if (!_canCommit(scope)) return 'تعذر تحديث المحادثة.';
      state = state.copyWith(mutating: false);
      return 'تعذر تحديث المحادثة.';
    }
  }

  Future<void> _ingestPolledMessage(MessageModel incoming) async {
    final scope = captureInboxScope(_ref);
    if (!inboxScopeIsCurrent(_ref, scope)) return;
    final workspaceId = scope.workspaceId;
    final repo = _ref.read(conversationRepositoryProvider);
    if (workspaceId != null) {
      await repo.persistMessages(workspaceId, conversationId, [incoming]);
      if (!_canCommit(scope)) return;
      final local = await repo.cachedMessages(workspaceId, conversationId);
      if (!_canCommit(scope)) return;
      state = state.copyWith(messages: local);
      if (!incoming.isOutbound) {
        _clearUnreadAfterSend();
        unawaited(_persistReadAfterSend(workspaceId));
      }
      return;
    }
    if (state.messages.any((x) {
      if (x.id == incoming.id) return true;
      return incoming.clientId != null && x.clientId == incoming.clientId;
    })) {
      return;
    }
    state = state.copyWith(messages: [...state.messages, incoming]);
  }

  bool _canCommit(InboxScope scope) {
    if (!mounted) return false;
    return inboxScopeIsCurrent(_ref, scope);
  }
}

final chatControllerProvider =
    StateNotifierProvider.family<ChatController, ChatState, int>(
      (ref, id) => ChatController(ref, id),
    );
