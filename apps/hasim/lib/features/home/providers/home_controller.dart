import 'dart:async';
import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

class HomeState {
  const HomeState({
    this.loading = false,
    this.snapshot,
    this.recentConversations = const [],
    this.error,
  });

  final bool loading;
  final HomeSnapshot? snapshot;
  final List<ConversationModel> recentConversations;
  final String? error;

  HomeState copyWith({
    bool? loading,
    HomeSnapshot? snapshot,
    List<ConversationModel>? recentConversations,
    String? error,
  }) => HomeState(
    loading: loading ?? this.loading,
    snapshot: snapshot ?? this.snapshot,
    recentConversations: recentConversations ?? this.recentConversations,
    error: error,
  );
}

class HomeController extends StateNotifier<HomeState> {
  HomeController(this._ref) : super(const HomeState()) {
    _workspaceSub = _ref.listen<int?>(activeWorkspaceIdProvider, (
      previous,
      next,
    ) {
      if (previous == next) return;
      _baseOverlaidSnapshot = null;
      _baseLocalUnreadCount = 0;
      _refreshDebounce?.cancel();
      state = const HomeState();
      unawaited(refresh());
    });
    _revisionSub = _ref.listen<int>(inboxLocalRevisionProvider, (
      previous,
      next,
    ) {
      if (previous == next) return;
      unawaited(_applyLocalUnread());
    });
    final realtime = _ref.read(realtimeServiceProvider);
    _msgSub = realtime.messageCreated.listen((_) {
      unawaited(_applyLocalUnread());
    });
    _convSub = realtime.conversationUpdated.listen((_) {
      unawaited(_applyLocalUnread());
    });
    _convChangedSub = realtime.conversationsChanged.listen((_) {
      if (!realtime.isWebSocketConnected) {
        _scheduleRefresh();
      } else {
        unawaited(_applyLocalUnread());
      }
    });
    _reconnectSub = realtime.reconnectSyncRequested.listen((_) {
      _scheduleRefresh();
    });
    refresh();
  }

  final Ref _ref;
  ProviderSubscription<int?>? _workspaceSub;
  ProviderSubscription<int>? _revisionSub;
  StreamSubscription<MessageModel>? _msgSub;
  StreamSubscription<ConversationModel>? _convSub;
  StreamSubscription<void>? _convChangedSub;
  StreamSubscription<void>? _reconnectSub;
  Timer? _refreshDebounce;
  HomeSnapshot? _baseOverlaidSnapshot;
  int _baseLocalUnreadCount = 0;

  @override
  void dispose() {
    _workspaceSub?.close();
    _revisionSub?.close();
    _msgSub?.cancel();
    _convSub?.cancel();
    _convChangedSub?.cancel();
    _reconnectSub?.cancel();
    _refreshDebounce?.cancel();
    super.dispose();
  }

  void _scheduleRefresh() {
    _refreshDebounce?.cancel();
    _refreshDebounce = Timer(const Duration(milliseconds: 250), () {
      if (mounted) unawaited(refresh());
    });
  }

  Future<void> refresh() async {
    if (!_ref.read(authControllerProvider).isAuthenticated) return;
    final scope = captureInboxScope(_ref);
    final workspaceId = scope.workspaceId;
    if (workspaceId != null) {
      final cached = await _ref
          .read(conversationRepositoryProvider)
          .cachedConversations(workspaceId);
      final unread = await _ref
          .read(inboxLocalStoreProvider)
          .unreadConversationCount(workspaceId);
      if (!_canCommit(scope)) return;
      if (cached.isNotEmpty || state.snapshot != null) {
        state = state.copyWith(
          loading: false,
          recentConversations: cached.take(5).toList(),
          snapshot: HomeSnapshot(
            unreadConversations: unread,
            unreadEmail: state.snapshot?.unreadEmail ?? 0,
            unreadNotifications: state.snapshot?.unreadNotifications ?? 0,
          ),
        );
      } else {
        state = state.copyWith(loading: true, error: null);
      }
    } else {
      state = state.copyWith(loading: true, error: null);
    }
    try {
      final snapFuture = _ref.read(homeRepositoryProvider).home();
      final pageFuture = _ref
          .read(conversationRepositoryProvider)
          .list(workspaceId: scope.workspaceId)
          .then<List<ConversationModel>?>((page) => page.items.take(5).toList())
          .catchError((_) => null);
      final snap = await snapFuture;
      if (!_canCommit(scope)) return;
      var recent = await pageFuture;
      if (recent == null) {
        if (!_canCommit(scope)) return;
        recent = workspaceId == null
            ? const []
            : (await _ref
                      .read(conversationRepositoryProvider)
                      .cachedConversations(workspaceId))
                  .take(5)
                  .toList();
      }
      if (!_canCommit(scope)) return;
      final overlaid = scope.workspaceId == null
          ? snap
          : await _ref
                .read(conversationRepositoryProvider)
                .overlayHomeUnread(scope.workspaceId!, snap);
      final localUnread = scope.workspaceId == null
          ? 0
          : await _ref
                .read(inboxLocalStoreProvider)
                .unreadConversationCount(scope.workspaceId!);
      if (!_canCommit(scope)) return;
      _baseOverlaidSnapshot = overlaid;
      _baseLocalUnreadCount = localUnread;
      state = HomeState(
        loading: false,
        snapshot: overlaid,
        recentConversations: recent,
      );
    } on ApiException catch (e) {
      if (!_canCommit(scope)) return;
      state = HomeState(
        loading: false,
        snapshot: state.snapshot,
        recentConversations: state.recentConversations,
        error: e.userMessage,
      );
    } catch (_) {
      if (!_canCommit(scope)) return;
      state = HomeState(
        loading: false,
        snapshot: state.snapshot,
        recentConversations: state.recentConversations,
        error: 'تعذر تحديث الرئيسية.',
      );
    }
  }

  Future<void> _applyLocalUnread() async {
    final scope = captureInboxScope(_ref);
    final base = _baseOverlaidSnapshot ?? state.snapshot;
    final workspaceId = scope.workspaceId;
    if (base == null || workspaceId == null) return;
    final store = _ref.read(inboxLocalStoreProvider);
    final overlaid = _baseOverlaidSnapshot != null
        ? base.copyWith(
            unreadConversations: math.max(
              0,
              base.unreadConversations +
                  ((await store.unreadConversationCount(workspaceId)) -
                      _baseLocalUnreadCount),
            ),
          )
        : await _ref
              .read(conversationRepositoryProvider)
              .overlayHomeUnread(workspaceId, base);
    if (!_canCommit(scope)) return;
    List<ConversationModel> recent = state.recentConversations;
    try {
      recent = await _ref
          .read(conversationRepositoryProvider)
          .cachedConversations(workspaceId);
      recent = recent.take(5).toList();
    } catch (_) {
      // Keep prior recent list if local cache read fails.
    }
    if (!_canCommit(scope)) return;
    state = state.copyWith(snapshot: overlaid, recentConversations: recent);
  }

  bool _canCommit(InboxScope scope) {
    if (!mounted) return false;
    return inboxScopeIsCurrent(_ref, scope);
  }
}

final homeControllerProvider = StateNotifierProvider<HomeController, HomeState>(
  (ref) => HomeController(ref),
);
