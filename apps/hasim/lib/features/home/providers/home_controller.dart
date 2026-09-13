import 'dart:async';

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
  }) =>
      HomeState(
        loading: loading ?? this.loading,
        snapshot: snapshot ?? this.snapshot,
        recentConversations: recentConversations ?? this.recentConversations,
        error: error,
      );
}

class HomeController extends StateNotifier<HomeState> {
  HomeController(this._ref) : super(const HomeState()) {
    _workspaceSub = _ref.listen<int?>(activeWorkspaceIdProvider, (previous, next) {
      if (previous == next) return;
      state = const HomeState();
      unawaited(refresh());
    });
    _revisionSub = _ref.listen<int>(inboxLocalRevisionProvider, (previous, next) {
      if (previous == next) return;
      unawaited(_applyLocalUnread());
    });
    refresh();
  }

  final Ref _ref;
  ProviderSubscription<int?>? _workspaceSub;
  ProviderSubscription<int>? _revisionSub;

  @override
  void dispose() {
    _workspaceSub?.close();
    _revisionSub?.close();
    super.dispose();
  }

  Future<void> refresh() async {
    if (!_ref.read(authControllerProvider).isAuthenticated) return;
    final scope = captureInboxScope(_ref);
    state = state.copyWith(loading: true, error: null);
    try {
      final snap = await _ref.read(homeRepositoryProvider).home();
      if (!_canCommit(scope)) return;
      List<ConversationModel> recent = const [];
      try {
        final page = await _ref.read(conversationRepositoryProvider).list(workspaceId: scope.workspaceId);
        if (!_canCommit(scope)) return;
        recent = page.items.take(5).toList();
      } catch (_) {
        if (!_canCommit(scope)) return;
        final workspaceId = scope.workspaceId;
        recent = workspaceId == null
            ? const []
            : (await _ref.read(conversationRepositoryProvider).cachedConversations(workspaceId))
                .take(5)
                .toList();
      }
      if (!_canCommit(scope)) return;
      final overlaid = scope.workspaceId == null
          ? snap
          : await _ref.read(conversationRepositoryProvider).overlayHomeUnread(scope.workspaceId!, snap);
      if (!_canCommit(scope)) return;
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
        error: e.message,
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
    final snap = state.snapshot;
    final workspaceId = scope.workspaceId;
    if (snap == null || workspaceId == null) return;
    final overlaid = await _ref.read(conversationRepositoryProvider).overlayHomeUnread(workspaceId, snap);
    if (!_canCommit(scope)) return;
    List<ConversationModel> recent = state.recentConversations;
    try {
      recent = await _ref.read(conversationRepositoryProvider).cachedConversations(workspaceId);
      recent = recent.take(5).toList();
    } catch (_) {}
    if (!_canCommit(scope)) return;
    state = state.copyWith(snapshot: overlaid, recentConversations: recent);
  }

  bool _canCommit(InboxScope scope) {
    if (!mounted) return false;
    return inboxScopeIsCurrent(_ref, scope);
  }
}

final homeControllerProvider = StateNotifierProvider<HomeController, HomeState>((ref) => HomeController(ref));
