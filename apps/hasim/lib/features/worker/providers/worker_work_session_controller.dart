import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';
import 'package:hasim/features/worker/data/worker_work_session.dart';

enum WorkerSessionAction { start, beginBreak, resume, end }

class WorkerWorkSessionState {
  const WorkerWorkSessionState({
    this.loading = false,
    this.action,
    this.error,
    this.actionError,
    this.sessions = const [],
  });

  final bool loading;
  final WorkerSessionAction? action;
  final String? error;
  final String? actionError;
  final List<WorkerWorkSession> sessions;

  bool get busy => action != null;

  WorkerWorkSession? get currentSession {
    for (final session in sessions) {
      if (session.isStarted) return session;
    }
    return null;
  }

  WorkerWorkSessionState copyWith({
    bool? loading,
    WorkerSessionAction? action,
    bool clearAction = false,
    String? error,
    bool clearError = false,
    String? actionError,
    bool clearActionError = false,
    List<WorkerWorkSession>? sessions,
  }) {
    return WorkerWorkSessionState(
      loading: loading ?? this.loading,
      action: clearAction ? null : action ?? this.action,
      error: clearError ? null : error ?? this.error,
      actionError: clearActionError ? null : actionError ?? this.actionError,
      sessions: sessions ?? this.sessions,
    );
  }
}

class WorkerWorkSessionController
    extends StateNotifier<WorkerWorkSessionState> {
  WorkerWorkSessionController(this._ref, this.engagementId)
    : super(const WorkerWorkSessionState(loading: true));

  final Ref _ref;
  final int engagementId;

  Future<void> load() async {
    if (state.busy) return;
    final repository = _ref.read(workerRepositoryProvider);
    final cached = await repository.cachedSessions(engagementId);
    if (cached.isNotEmpty) {
      state = state.copyWith(loading: false, sessions: cached, clearError: true);
    } else {
      state = state.copyWith(loading: true, clearError: true);
    }
    try {
      final sessions = await repository.getWorkSession(engagementId);
      state = state.copyWith(
        loading: false,
        sessions: sessions,
        clearError: true,
        clearActionError: true,
      );
    } on ApiException catch (error) {
      state = state.copyWith(
        loading: false,
        error: state.sessions.isEmpty ? _sessionError(error) : null,
      );
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: state.sessions.isEmpty ? 'تعذر تحميل جلسات العمل.' : null,
      );
    }
  }

  Future<bool> start() {
    return _mutate(
      WorkerSessionAction.start,
      (repo) => repo.startWorkSession(engagementId),
    );
  }

  Future<bool> beginBreak() {
    return _mutate(
      WorkerSessionAction.beginBreak,
      (repo) => repo.breakWorkSession(engagementId),
    );
  }

  Future<bool> resume() {
    return _mutate(
      WorkerSessionAction.resume,
      (repo) => repo.resumeWorkSession(engagementId),
    );
  }

  Future<bool> end() {
    return _mutate(
      WorkerSessionAction.end,
      (repo) => repo.endWorkSession(engagementId),
    );
  }

  Future<bool> _mutate(
    WorkerSessionAction action,
    Future<WorkerWorkSession> Function(WorkerRepository repo) run,
  ) async {
    if (state.busy) return false;
    state = state.copyWith(action: action, clearActionError: true);
    try {
      final session = await run(_ref.read(workerRepositoryProvider));
      state = state.copyWith(
        clearAction: true,
        sessions: _upsert(state.sessions, session),
        clearActionError: true,
      );
      return true;
    } on ApiException catch (error) {
      state = state.copyWith(
        clearAction: true,
        actionError: _sessionError(error),
      );
      await _refreshAfterFailure();
      return false;
    } catch (_) {
      state = state.copyWith(
        clearAction: true,
        actionError: 'تعذر تحديث جلسة العمل.',
      );
      await _refreshAfterFailure();
      return false;
    }
  }

  Future<void> _refreshAfterFailure() async {
    try {
      final sessions = await _ref
          .read(workerRepositoryProvider)
          .getWorkSession(engagementId);
      state = state.copyWith(sessions: sessions);
    } catch (_) {
      // Keep the action error. History stays unchanged when refresh fails.
    }
  }

  List<WorkerWorkSession> _upsert(
    List<WorkerWorkSession> current,
    WorkerWorkSession next,
  ) {
    final exists = current.any((session) => session.id == next.id);
    if (!exists) return [next, ...current];
    return [
      for (final session in current)
        if (session.id == next.id) next else session,
    ];
  }

  String _sessionError(ApiException error) {
    if (error.statusCode == 422 && error.message.trim().isNotEmpty) {
      return error.message.trim();
    }
    return error.userMessage;
  }
}

final workerWorkSessionProvider = StateNotifierProvider.autoDispose
    .family<WorkerWorkSessionController, WorkerWorkSessionState, int>((
      ref,
      engagementId,
    ) {
      final controller = WorkerWorkSessionController(ref, engagementId);
      controller.load();
      return controller;
    });
