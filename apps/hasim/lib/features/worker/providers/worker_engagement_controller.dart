import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';

class WorkerEngagementListState {
  const WorkerEngagementListState({
    this.loading = false,
    this.error,
    this.items = const [],
  });

  final bool loading;
  final String? error;
  final List<WorkerEngagement> items;

  WorkerEngagementListState copyWith({
    bool? loading,
    String? error,
    bool clearError = false,
    List<WorkerEngagement>? items,
  }) {
    return WorkerEngagementListState(
      loading: loading ?? this.loading,
      error: clearError ? null : error ?? this.error,
      items: items ?? this.items,
    );
  }
}

class WorkerEngagementListController
    extends StateNotifier<WorkerEngagementListState> {
  WorkerEngagementListController(this._ref)
    : super(const WorkerEngagementListState(loading: true));

  final Ref _ref;
  Future<void>? _loadFuture;
  bool _loaded = false;

  Future<void> load({bool force = false}) {
    if (_loadFuture != null) return _loadFuture!;
    if (_loaded && !force) return Future<void>.value();

    final future = _loadFromServer(force: force);
    _loadFuture = future.whenComplete(() {
      _loadFuture = null;
      _loaded = true;
    });
    return _loadFuture!;
  }

  Future<void> _loadFromServer({required bool force}) async {
    final repository = _ref.read(workerRepositoryProvider);
    final cached = await repository.cachedEngagements();
    if (cached.isNotEmpty) {
      state = state.copyWith(loading: false, items: cached, clearError: true);
    } else {
      state = state.copyWith(loading: true, clearError: true);
    }
    try {
      if (force) repository.clearEngagementsCache();
      final items = await repository.engagements();
      state = state.copyWith(loading: false, items: items, clearError: true);
    } on ApiException catch (error) {
      state = state.copyWith(
        loading: false,
        error: state.items.isEmpty ? error.userMessage : null,
      );
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: state.items.isEmpty ? 'تعذر تحميل الارتباطات.' : null,
      );
    }
  }

  void upsert(WorkerEngagement engagement) {
    _loaded = true;
    final exists = state.items.any((item) => item.id == engagement.id);
    final items = [
      for (final item in state.items)
        if (item.id == engagement.id) engagement else item,
      if (!exists) engagement,
    ];
    state = state.copyWith(items: items);
  }
}

final workerEngagementListProvider =
    StateNotifierProvider.autoDispose<
      WorkerEngagementListController,
      WorkerEngagementListState
    >((ref) {
      final keepAlive = ref.keepAlive();
      final controller = WorkerEngagementListController(ref);
      ref.listen<AuthState>(authControllerProvider, (previous, next) {
        if (previous?.isolationKey == next.isolationKey) return;
        ref.read(workerRepositoryProvider).clearEngagementsCache();
        if (!next.isAuthenticated || !next.isIndependentWorker) {
          keepAlive.close();
        }
      });
      controller.load();
      return controller;
    });

class WorkerEngagementDetailState {
  const WorkerEngagementDetailState({
    this.loading = false,
    this.busy = false,
    this.error,
    this.actionError,
    this.engagement,
  });

  final bool loading;
  final bool busy;
  final String? error;
  final String? actionError;
  final WorkerEngagement? engagement;

  WorkerEngagementDetailState copyWith({
    bool? loading,
    bool? busy,
    String? error,
    bool clearError = false,
    String? actionError,
    bool clearActionError = false,
    WorkerEngagement? engagement,
  }) {
    return WorkerEngagementDetailState(
      loading: loading ?? this.loading,
      busy: busy ?? this.busy,
      error: clearError ? null : error ?? this.error,
      actionError: clearActionError ? null : actionError ?? this.actionError,
      engagement: engagement ?? this.engagement,
    );
  }
}

class WorkerEngagementDetailController
    extends StateNotifier<WorkerEngagementDetailState> {
  WorkerEngagementDetailController(this._ref, this.engagementId)
    : super(
        WorkerEngagementDetailState(
          loading: true,
          engagement: _cachedEngagement(_ref, engagementId),
        ),
      );

  final Ref _ref;
  final int? engagementId;

  Future<void> load() async {
    final id = engagementId;
    if (id == null) {
      state = const WorkerEngagementDetailState(error: 'غير موجود.');
      return;
    }
    state = state.copyWith(loading: true, clearError: true);
    try {
      final engagement = await _ref
          .read(workerRepositoryProvider)
          .engagement(id);
      state = state.copyWith(
        loading: false,
        engagement: engagement,
        clearError: true,
      );
      _publish(engagement);
    } on ApiException catch (error) {
      state = state.copyWith(loading: false, error: error.userMessage);
    } catch (_) {
      state = state.copyWith(loading: false, error: 'تعذر تحميل الارتباط.');
    }
  }

  Future<bool> accept() {
    return _respond(
      (id) => _ref.read(workerRepositoryProvider).acceptOffer(id),
    );
  }

  Future<bool> reject() {
    return _respond(
      (id) => _ref.read(workerRepositoryProvider).rejectOffer(id),
    );
  }

  Future<bool> _respond(
    Future<WorkerEngagement> Function(int id) action,
  ) async {
    final current = state.engagement;
    final id = engagementId;
    if (id == null || current == null || !current.isOffer || state.busy) {
      return false;
    }
    state = state.copyWith(busy: true, clearActionError: true);
    try {
      final updated = await action(id);
      state = state.copyWith(
        busy: false,
        engagement: updated,
        clearActionError: true,
      );
      _publish(updated);
      return true;
    } on ApiException catch (error) {
      state = state.copyWith(busy: false, actionError: error.userMessage);
      await _refreshAfterFailure(id);
      return false;
    } catch (_) {
      state = state.copyWith(busy: false, actionError: 'تعذر تحديث العرض.');
      await _refreshAfterFailure(id);
      return false;
    }
  }

  Future<void> _refreshAfterFailure(int id) async {
    try {
      final engagement = await _ref
          .read(workerRepositoryProvider)
          .engagement(id);
      state = state.copyWith(engagement: engagement);
      _publish(engagement);
    } catch (_) {
      // Keep the action error. The list stays unchanged when refresh fails.
    }
  }

  void _publish(WorkerEngagement engagement) {
    if (!_ref.exists(workerEngagementListProvider)) return;
    _ref.read(workerEngagementListProvider.notifier).upsert(engagement);
  }
}

WorkerEngagement? _cachedEngagement(Ref ref, int? id) {
  if (id == null || !ref.exists(workerEngagementListProvider)) return null;
  for (final engagement in ref.read(workerEngagementListProvider).items) {
    if (engagement.id == id) return engagement;
  }
  return null;
}

final workerEngagementDetailProvider = StateNotifierProvider.autoDispose
    .family<
      WorkerEngagementDetailController,
      WorkerEngagementDetailState,
      int?
    >((ref, id) {
      final controller = WorkerEngagementDetailController(ref, id);
      controller.load();
      return controller;
    });
