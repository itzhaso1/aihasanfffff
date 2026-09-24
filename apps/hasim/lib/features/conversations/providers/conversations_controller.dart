import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/local_db/conversation_reconciler.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/core/utils/idempotency.dart';
import 'package:hasim/features/conversations/data/chat_attachment_policy.dart';
import 'package:hasim/features/conversations/data/conversation_repository.dart';
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
    this.mutatingConversationId,
  });

  final bool loading;
  final bool loadingMore;
  final List<ConversationModel> items;
  final String? nextCursor;
  final String filter;
  final String? channel;
  final String search;
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
      error: error,
      mutatingConversationId:
          clearMutating ? null : (mutatingConversationId ?? this.mutatingConversationId),
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

  bool _matchesCurrentFilter(ConversationModel conversation) {
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
      update: (conversation) => conversation.copyWith(archived: archived),
    );
  }

  Future<String?> setMuted(int conversationId, {required bool muted}) {
    return _mutateConversation(
      conversationId,
      apply: (repo, workspaceId) => repo.mute(
        conversationId,
        muted: muted,
        workspaceId: workspaceId,
      ),
      update: (conversation) => conversation.copyWith(muted: muted),
    );
  }

  Future<String?> _mutateConversation(
    int conversationId, {
    required Future<void> Function(ConversationRepository repo, int? workspaceId) apply,
    required ConversationModel Function(ConversationModel conversation) update,
  }) async {
    if (state.mutatingConversationId != null) return null;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(mutatingConversationId: conversationId, error: null);
    try {
      await apply(_ref.read(conversationRepositoryProvider), scope.workspaceId);
      if (!_canCommit(scope)) return null;
      final next = [
        for (final item in state.items)
          if (item.id == conversationId) update(item) else item,
      ].where(_matchesCurrentFilter).toList();
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
    final workspaceId = _boundWorkspaceId;
    var cached = const <MessageModel>[];
    if (workspaceId != null) {
      cached = await _ref.read(conversationRepositoryProvider).cachedMessages(workspaceId, conversationId);
    }
    if (!mounted || _boundWorkspaceId != workspaceId) return;
    state = cached.isNotEmpty
        ? ChatState(messages: cached, loading: true)
        : const ChatState(loading: true);
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
    if (message.localAttachmentPath != null) {
      await _retryAttachment(message);
      return;
    }
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
          ? state.messages.map((m) => m.clientId == key ? optimistic : m).toList()
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
      return withAttach;
    } catch (_) {
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
        message.copyWith(localPending: true, localUploading: true, localFailed: false),
      );
      state = state.copyWith(sending: true, error: null);
      try {
        final attachment = await _ref.read(conversationRepositoryProvider).uploadAttachment(message.id, file);
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
          await _ref.read(conversationRepositoryProvider).persistMessages(
                workspaceId,
                conversationId,
                state.messages,
              );
        }
      } catch (_) {
        if (!_canCommit(scope)) return;
        _replaceLocalMessage(
          clientId,
          message.copyWith(localPending: false, localUploading: false, localFailed: true),
        );
        state = state.copyWith(sending: false);
      }
      return;
    }
    await sendThenAttach(file, caption: message.content == '📎' ? '' : message.content, clientId: clientId);
  }

  void _replaceLocalMessage(String clientId, MessageModel next) {
    state = state.copyWith(
      messages: state.messages.map((m) => m.clientId == clientId ? next : m).toList(),
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

  Future<String?> setArchived({required bool archived}) {
    return _mutateConversation(
      apply: (repo, workspaceId) => repo.archive(
        conversationId,
        archived: archived,
        workspaceId: workspaceId,
      ),
      update: (conversation) => conversation.copyWith(archived: archived),
    );
  }

  Future<String?> setMuted({required bool muted}) {
    return _mutateConversation(
      apply: (repo, workspaceId) => repo.mute(
        conversationId,
        muted: muted,
        workspaceId: workspaceId,
      ),
      update: (conversation) => conversation.copyWith(muted: muted),
    );
  }

  Future<String?> _mutateConversation({
    required Future<void> Function(ConversationRepository repo, int? workspaceId) apply,
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
      unawaited(_ref.read(conversationsControllerProvider.notifier).refresh(silent: true));
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
