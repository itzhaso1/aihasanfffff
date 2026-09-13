import 'dart:async';
import 'dart:io';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/conversation_reconciler.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/utils/idempotency.dart';
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
    this.error,
  });

  final bool loading;
  final bool loadingMore;
  final List<ConversationModel> items;
  final String? nextCursor;
  final String filter;
  final String? channel;
  final String search;
  final String? error;

  ConversationsState copyWith({
    bool? loading,
    bool? loadingMore,
    List<ConversationModel>? items,
    String? nextCursor,
    String? filter,
    String? channel,
    bool clearChannel = false,
    String? search,
    String? error,
    bool clearCursor = false,
  }) {
    return ConversationsState(
      loading: loading ?? this.loading,
      loadingMore: loadingMore ?? this.loadingMore,
      items: items ?? this.items,
      nextCursor: clearCursor ? nextCursor : (nextCursor ?? this.nextCursor),
      filter: filter ?? this.filter,
      channel: clearChannel ? channel : (channel ?? this.channel),
      search: search ?? this.search,
      error: error,
    );
  }
}

class ConversationsController extends StateNotifier<ConversationsState> {
  ConversationsController(this._ref) : super(const ConversationsState()) {
    _boundWorkspaceId = _ref.read(activeWorkspaceIdProvider);
    _sub = _ref.read(realtimeServiceProvider).conversationsChanged.listen((_) => refresh(silent: true));
    _workspaceSub = _ref.listen<int?>(activeWorkspaceIdProvider, (previous, next) {
      if (previous == next) return;
      _boundWorkspaceId = next;
      unawaited(_loadCachedThenRefresh());
    });
    _revisionSub = _ref.listen<int>(inboxLocalRevisionProvider, (previous, next) {
      if (previous == next) return;
      unawaited(_reloadFromCache());
    });
    unawaited(_loadCachedThenRefresh());
  }

  final Ref _ref;
  StreamSubscription? _sub;
  ProviderSubscription<int?>? _workspaceSub;
  ProviderSubscription<int>? _revisionSub;
  int? _boundWorkspaceId;

  Future<void> _loadCachedThenRefresh() async {
    state = const ConversationsState();
    final workspaceId = _boundWorkspaceId;
    if (workspaceId != null) {
      final cached = await _ref.read(conversationRepositoryProvider).cachedConversations(workspaceId);
      if (!mounted || _boundWorkspaceId != workspaceId) return;
      if (cached.isNotEmpty) {
        state = state.copyWith(items: cached);
      }
    }
    await refresh();
  }

  @override
  void dispose() {
    _sub?.cancel();
    _workspaceSub?.close();
    _revisionSub?.close();
    super.dispose();
  }

  Future<void> setFilter(String filter) async {
    state = state.copyWith(filter: filter);
    await refresh();
  }

  Future<void> setChannel(String? channel) async {
    state = state.copyWith(channel: channel, clearChannel: true);
    await refresh();
  }

  Future<void> setSearch(String search) async {
    state = state.copyWith(search: search);
    await refresh();
  }

  Future<void> refresh({bool silent = false}) async {
    final scope = captureInboxScope(_ref);
    if (!silent) state = state.copyWith(loading: true, error: null, clearCursor: true, nextCursor: null);
    try {
      final page = await _ref.read(conversationRepositoryProvider).list(
            filter: state.filter,
            channel: state.channel,
            search: state.search,
            workspaceId: scope.workspaceId,
          );
      if (!_canCommit(scope)) return;
      final items = await _visibleConversations(scope, page.items);
      if (!_canCommit(scope)) return;
      if (silent) {
        state = state.copyWith(loading: false, items: items);
      } else {
        state = state.copyWith(
          loading: false,
          items: items,
          nextCursor: page.nextCursor,
          clearCursor: true,
        );
      }
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loading: false, error: e.message);
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loading: false, error: 'تعذر تحميل المحادثات.');
    }
  }

  Future<void> loadMore() async {
    final cursor = state.nextCursor;
    if (cursor == null || state.loadingMore) return;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(loadingMore: true);
    try {
      final page = await _ref.read(conversationRepositoryProvider).list(
            filter: state.filter,
            channel: state.channel,
            search: state.search,
            cursor: cursor,
            workspaceId: scope.workspaceId,
          );
      if (!_canCommit(scope)) return;
      final items = await _visibleConversations(
        scope,
        ConversationReconciler.unionById(state.items, page.items),
      );
      if (!_canCommit(scope)) return;
      state = state.copyWith(
        loadingMore: false,
        items: items,
        nextCursor: page.nextCursor,
        clearCursor: true,
      );
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loadingMore: false);
    }
  }

  Future<void> _reloadFromCache() async {
    final workspaceId = _boundWorkspaceId;
    if (workspaceId == null) return;
    final scope = captureInboxScope(_ref);
    final cached = await _ref.read(conversationRepositoryProvider).cachedConversations(
          workspaceId,
          filter: state.filter,
          channel: state.channel,
          search: state.search,
        );
    if (!_canCommit(scope) || cached.isEmpty) return;
    state = state.copyWith(items: cached);
  }

  Future<List<ConversationModel>> _visibleConversations(
    InboxScope scope,
    List<ConversationModel> fetched,
  ) async {
    final workspaceId = scope.workspaceId;
    if (workspaceId == null) return fetched;
    final local = await _ref.read(conversationRepositoryProvider).cachedConversations(
          workspaceId,
          filter: state.filter,
          channel: state.channel,
          search: state.search,
        );
    return local.isNotEmpty ? local : fetched;
  }

  bool _canCommit(InboxScope scope) {
    if (!mounted) return false;
    return inboxScopeIsCurrent(_ref, scope);
  }
}

final conversationsControllerProvider =
    StateNotifierProvider<ConversationsController, ConversationsState>((ref) => ConversationsController(ref));

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
  });

  final bool loading;
  final bool sending;
  final bool loadingOlder;
  final ConversationModel? conversation;
  final List<MessageModel> messages;
  final String? nextCursor;
  final String? error;
  final bool aiBusy;

  ChatState copyWith({
    bool? loading,
    bool? sending,
    bool? loadingOlder,
    ConversationModel? conversation,
    List<MessageModel>? messages,
    String? nextCursor,
    String? error,
    bool? aiBusy,
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
    );
  }
}

class ChatController extends StateNotifier<ChatState> {
  ChatController(this._ref, this.conversationId) : super(const ChatState()) {
    _realtime = _ref.read(realtimeServiceProvider);
    _boundWorkspaceId = _ref.read(activeWorkspaceIdProvider);
    _workspaceSub = _ref.listen<int?>(activeWorkspaceIdProvider, (previous, next) {
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
  ProviderSubscription<int?>? _workspaceSub;
  int? _boundWorkspaceId;

  Future<void> _init() async {
    await _sub?.cancel();
    _sub = null;
    state = const ChatState();
    final workspaceId = _boundWorkspaceId;
    if (workspaceId != null) {
      final cached = await _ref.read(conversationRepositoryProvider).cachedMessages(workspaceId, conversationId);
      if (!mounted || _boundWorkspaceId != workspaceId) return;
      if (cached.isNotEmpty) {
        state = state.copyWith(messages: cached, loading: true);
      }
    }
    _realtime.watchConversation(conversationId);
    _sub = _realtime.messageCreated.listen((m) {
      if (m.conversationId != conversationId) return;
      unawaited(_ingestPolledMessage(m));
    });
    await refresh();
  }

  @override
  void dispose() {
    _sub?.cancel();
    _workspaceSub?.close();
    _realtime.watchConversation(null);
    super.dispose();
  }

  Future<void> refresh() async {
    final scope = captureInboxScope(_ref);
    state = state.copyWith(loading: true, error: null);
    try {
      final repo = _ref.read(conversationRepositoryProvider);
      final conversation = await repo.getById(conversationId, workspaceId: scope.workspaceId);
      if (!_canCommit(scope)) return;
      final page = await repo.messages(conversationId, workspaceId: scope.workspaceId);
      if (!_canCommit(scope)) return;
      final workspaceId = scope.workspaceId;
      final local = workspaceId == null
          ? page.items.reversed.toList()
          : await repo.cachedMessages(workspaceId, conversationId);
      if (!_canCommit(scope)) return;
      state = ChatState(
        loading: false,
        conversation: conversation,
        messages: local.isNotEmpty ? local : page.items.reversed.toList(),
        nextCursor: page.nextCursor,
      );
      try {
        await repo.markRead(conversationId, workspaceId: workspaceId);
        if (!_canCommit(scope)) return;
        bumpInboxLocalRevision(_ref);
        if (workspaceId != null) {
          final readConversation = (await repo.cachedConversation(workspaceId, conversationId)) ??
              conversation.copyWith(unreadCount: 0);
          if (!_canCommit(scope)) return;
          state = state.copyWith(conversation: readConversation);
        }
      } catch (_) {
        // API failure must not claim local read or unread=0.
      }
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loading: false, error: e.message);
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loading: false, error: 'تعذر فتح المحادثة.');
    }
  }

  Future<void> loadOlder() async {
    final cursor = state.nextCursor;
    if (cursor == null || state.loadingOlder) return;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(loadingOlder: true);
    try {
      final page = await _ref.read(conversationRepositoryProvider).messages(
            conversationId,
            cursor: cursor,
            workspaceId: scope.workspaceId,
          );
      if (!_canCommit(scope)) return;
      final workspaceId = scope.workspaceId;
      final local = workspaceId == null
          ? [...page.items.reversed, ...state.messages]
          : await _ref.read(conversationRepositoryProvider).cachedMessages(workspaceId, conversationId);
      if (!_canCommit(scope)) return;
      state = state.copyWith(
        loadingOlder: false,
        messages: local,
        nextCursor: page.nextCursor,
        clearCursor: true,
      );
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = state.copyWith(loadingOlder: false);
    }
  }

  Future<void> send(String content) async {
    final text = content.trim();
    if (text.isEmpty) return;
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
    if (workspaceId == null) return;
    final repo = _ref.read(conversationRepositoryProvider);
    await repo.enqueueOutboundText(
      workspaceId: workspaceId,
      conversationId: conversationId,
      message: optimistic,
    );
    if (!_canCommit(scope)) return;
    final queued = await repo.cachedMessages(workspaceId, conversationId);
    if (!_canCommit(scope)) return;
    state = state.copyWith(messages: queued, sending: true, error: null);
    await _ref.read(outboxWorkerProvider).flush(workspaceId: workspaceId);
    if (!_canCommit(scope)) return;
    final after = await repo.cachedMessages(workspaceId, conversationId);
    if (!_canCommit(scope)) return;
    final failed = after.any((m) => m.clientId == clientId && m.localFailed);
    state = state.copyWith(
      messages: after,
      sending: false,
      error: failed ? 'تعذر إرسال الرسالة.' : null,
    );
  }

  Future<void> retryFailed(MessageModel message) async {
    if (!message.localFailed || message.clientId == null) return;
    final clientId = message.clientId!;
    final updatedPending = state.messages.map((m) {
      if (m.clientId == clientId) return m.copyWith(localPending: true, localFailed: false);
      return m;
    }).toList();
    final scope = captureInboxScope(_ref);
    final workspaceId = scope.workspaceId;
    if (workspaceId == null) return;
    final repo = _ref.read(conversationRepositoryProvider);
    await repo.retryOutbound(workspaceId: workspaceId, clientId: clientId);
    if (!_canCommit(scope)) return;
    final queued = await repo.cachedMessages(workspaceId, conversationId);
    if (!_canCommit(scope)) return;
    state = state.copyWith(messages: queued, sending: true, error: null);
    await _ref.read(outboxWorkerProvider).flush(workspaceId: workspaceId);
    if (!_canCommit(scope)) return;
    final after = await repo.cachedMessages(workspaceId, conversationId);
    if (!_canCommit(scope)) return;
    state = state.copyWith(messages: after, sending: false);
  }

  Future<MessageModel?> sendThenAttach(File file, {String caption = ''}) async {
    final text = caption.trim().isEmpty ? '📎' : caption.trim();
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
    state = state.copyWith(messages: [...state.messages, optimistic], sending: true);
    try {
      final saved = await _ref.read(conversationRepositoryProvider).sendMessageWithKey(
            conversationId,
            text,
            clientId,
          );
      if (!_canCommit(scope)) return null;
      final attachment = await _ref.read(conversationRepositoryProvider).uploadAttachment(saved.id, file);
      if (!_canCommit(scope)) return null;
      final withAttach = saved.copyWith(
        localPending: false,
        clientId: clientId,
        attachments: [...saved.attachments, attachment],
      );
      final updated = state.messages.map((m) {
        if (m.clientId == clientId) return withAttach;
        return m;
      }).toList();
      state = state.copyWith(messages: updated, sending: false);
      final workspaceId = scope.workspaceId;
      if (workspaceId != null) {
        await _ref.read(conversationRepositoryProvider).persistMessages(
              workspaceId,
              conversationId,
              updated,
            );
      }
      return withAttach;
    } catch (_) {
      if (!_canCommit(scope)) return null;
      final updated = state.messages.map((m) {
        if (m.clientId == clientId) return m.copyWith(localPending: false, localFailed: true);
        return m;
      }).toList();
      state = state.copyWith(messages: updated, sending: false, error: 'تعذر إرسال المرفق.');
      return null;
    }
  }

  Future<String?> suggestReply() async {
    final scope = captureInboxScope(_ref);
    state = state.copyWith(aiBusy: true);
    try {
      final text = await _ref.read(aiRepositoryProvider).suggestReply(conversationId);
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false);
      return text;
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false, error: e.message);
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
      final text = await _ref.read(aiRepositoryProvider).summarize(conversationId);
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false);
      return text;
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false, error: e.message);
      return null;
    } catch (_) {
      if (!_canCommit(scope)) return null;
      state = state.copyWith(aiBusy: false, error: 'تعذر تلخيص المحادثة.');
      return null;
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

final chatControllerProvider = StateNotifierProvider.family<ChatController, ChatState, int>(
  (ref, id) => ChatController(ref, id),
);
