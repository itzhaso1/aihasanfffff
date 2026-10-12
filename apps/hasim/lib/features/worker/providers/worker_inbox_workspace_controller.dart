import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/providers/desktop_conversation_selection.dart';
import 'package:hasim/features/worker/presentation/worker_chat_screen.dart';

class WorkerInboxWorkspaceState {
  const WorkerInboxWorkspaceState({
    this.workspaces = const [],
    this.loading = false,
    this.changing = false,
    this.error,
  });

  final List<WorkerInboxWorkspace> workspaces;
  final bool loading;
  final bool changing;
  final String? error;

  WorkerInboxWorkspaceState copyWith({
    List<WorkerInboxWorkspace>? workspaces,
    bool? loading,
    bool? changing,
    String? error,
    bool clearError = false,
  }) {
    return WorkerInboxWorkspaceState(
      workspaces: workspaces ?? this.workspaces,
      loading: loading ?? this.loading,
      changing: changing ?? this.changing,
      error: clearError ? null : (error ?? this.error),
    );
  }
}

class WorkerInboxWorkspaceController
    extends StateNotifier<WorkerInboxWorkspaceState> {
  WorkerInboxWorkspaceController(this._ref)
    : super(const WorkerInboxWorkspaceState()) {
    _authSub = _ref.listen<AuthState>(authControllerProvider, (previous, next) {
      if (previous?.isolationKey != next.isolationKey) {
        _ref.read(workerRepositoryProvider).clearEngagementsCache();
      }
      if (!next.isAuthenticated || !next.isIndependentWorker) return;
      if (previous?.isIndependentWorker == true) return;
      _hasLoaded = false;
      unawaited(load());
    });
    if (_isWorkerAuthenticated) {
      unawaited(load());
    }
  }

  final Ref _ref;
  ProviderSubscription<AuthState>? _authSub;
  bool _hasLoaded = false;
  bool _loadInProgress = false;

  bool get _isWorkerAuthenticated {
    final auth = _ref.read(authControllerProvider);
    return auth.isAuthenticated && auth.isIndependentWorker;
  }

  Future<void> load({bool force = false}) async {
    if (!_ref.read(authControllerProvider).isIndependentWorker) return;
    if (_loadInProgress || (_hasLoaded && !force)) return;

    _hasLoaded = true;
    _loadInProgress = true;
    state = state.copyWith(loading: true, clearError: true);

    try {
      final repository = _ref.read(workerRepositoryProvider);
      if (force) repository.clearEngagementsCache();
      final engagements = await repository.engagements();
      final workspaces = workerInboxWorkspaces(engagements);
      state = state.copyWith(
        workspaces: workspaces,
        loading: false,
        clearError: true,
      );

      final currentWorkspaceId = _ref.read(activeWorkspaceIdProvider);
      final selectedWorkspaceId =
          workspaces.any((workspace) => workspace.id == currentWorkspaceId)
          ? currentWorkspaceId
          : (workspaces.isEmpty ? null : workspaces.first.id);

      if (selectedWorkspaceId != currentWorkspaceId ||
          _ref.read(inboxSessionProvider).workspaceId != selectedWorkspaceId) {
        await _setWorkspace(selectedWorkspaceId);
      }
    } on ApiException catch (error) {
      state = state.copyWith(loading: false, error: error.userMessage);
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر تحميل شركات المحادثات.',
      );
    } finally {
      _loadInProgress = false;
    }
  }

  Future<void> select(int workspaceId) async {
    if (state.changing || _ref.read(activeWorkspaceIdProvider) == workspaceId) {
      return;
    }

    state = state.copyWith(changing: true, clearError: true);

    try {
      final realtime = _ref.read(realtimeServiceProvider);
      await realtime.stop();
      await _setWorkspace(workspaceId);
      _ref.read(desktopSelectedConversationIdProvider.notifier).state = null;
      await realtime.start();
      _ref.read(outboxWorkerProvider).start();
    } on ApiException catch (error) {
      state = state.copyWith(changing: false, error: error.userMessage);
      return;
    } catch (_) {
      state = state.copyWith(changing: false, error: 'تعذر تبديل الشركة.');
      return;
    }

    state = state.copyWith(changing: false);
  }

  Future<void> _setWorkspace(int? workspaceId) async {
    final realtime = _ref.read(realtimeServiceProvider);
    realtime.watchConversation(null);

    if (workspaceId == null) {
      _ref.read(inboxSessionProvider).clear();
    } else {
      _ref.read(inboxSessionProvider).beginWorkspace(workspaceId);
    }

    await _ref.read(prefsStoreProvider).setWorkspaceId(workspaceId);
    _ref.invalidate(activeWorkspaceIdProvider);
  }

  @override
  void dispose() {
    _authSub?.close();
    super.dispose();
  }
}

final workerInboxWorkspaceControllerProvider =
    StateNotifierProvider<
      WorkerInboxWorkspaceController,
      WorkerInboxWorkspaceState
    >((ref) => WorkerInboxWorkspaceController(ref));
