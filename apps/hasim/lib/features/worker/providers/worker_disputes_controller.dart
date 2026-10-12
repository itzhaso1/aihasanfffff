import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/worker/data/worker_dispute.dart';

class WorkerDisputesState {
  const WorkerDisputesState({
    this.loading = false,
    this.loadingMore = false,
    this.creating = false,
    this.error,
    this.actionError,
    this.items = const [],
    this.total = 0,
    this.currentPage = 1,
    this.lastPage = 1,
    this.filters = const WorkerDisputeFilters(),
  });

  final bool loading;
  final bool loadingMore;
  final bool creating;
  final String? error;
  final String? actionError;
  final List<WorkerDispute> items;
  final int total;
  final int currentPage;
  final int lastPage;
  final WorkerDisputeFilters filters;

  bool get hasMore => currentPage < lastPage;

  WorkerDisputesState copyWith({
    bool? loading,
    bool? loadingMore,
    bool? creating,
    String? error,
    bool clearError = false,
    String? actionError,
    bool clearActionError = false,
    List<WorkerDispute>? items,
    int? total,
    int? currentPage,
    int? lastPage,
    WorkerDisputeFilters? filters,
  }) {
    return WorkerDisputesState(
      loading: loading ?? this.loading,
      loadingMore: loadingMore ?? this.loadingMore,
      creating: creating ?? this.creating,
      error: clearError ? null : error ?? this.error,
      actionError: clearActionError ? null : actionError ?? this.actionError,
      items: items ?? this.items,
      total: total ?? this.total,
      currentPage: currentPage ?? this.currentPage,
      lastPage: lastPage ?? this.lastPage,
      filters: filters ?? this.filters,
    );
  }
}

class WorkerDisputesController extends StateNotifier<WorkerDisputesState> {
  WorkerDisputesController(this._ref)
    : super(const WorkerDisputesState(loading: true));

  final Ref _ref;
  int _loadEpoch = 0;

  Future<void> load([WorkerDisputeFilters? filters]) async {
    final nextFilters = (filters ?? state.filters).copyWith(page: 1);
    final epoch = ++_loadEpoch;
    final repository = _ref.read(workerRepositoryProvider);
    if (nextFilters.page == 1 && (nextFilters.status == null || nextFilters.status!.isEmpty)) {
      final cached = await repository.cachedDisputes();
      if (epoch != _loadEpoch) return;
      if (cached.isNotEmpty) {
        state = state.copyWith(loading: false, filters: nextFilters, items: cached, clearError: true);
      } else {
        state = state.copyWith(loading: true, filters: nextFilters, clearError: true, clearActionError: true);
      }
    } else {
      state = state.copyWith(loading: true, filters: nextFilters, clearError: true, clearActionError: true);
    }
    if (epoch != _loadEpoch) return;
    try {
      final page = await repository.getWorkerDisputes(nextFilters);
      if (epoch != _loadEpoch) return;
      state = state.copyWith(
        loading: false,
        items: page.items,
        total: page.total,
        currentPage: page.currentPage,
        lastPage: page.lastPage,
        clearError: true,
      );
    } on ApiException catch (error) {
      state = state.copyWith(loading: false, error: state.items.isEmpty ? _error(error) : null);
    } catch (_) {
      state = state.copyWith(loading: false, error: 'تعذر تحميل النزاعات.');
    }
  }

  Future<void> loadMore() async {
    if (state.loading || state.loadingMore || !state.hasMore) return;
    final nextFilters = state.filters.copyWith(page: state.currentPage + 1);
    state = state.copyWith(loadingMore: true, clearError: true);
    try {
      final page = await _ref
          .read(workerRepositoryProvider)
          .getWorkerDisputes(nextFilters);
      state = state.copyWith(
        loadingMore: false,
        items: [...state.items, ...page.items],
        total: page.total,
        currentPage: page.currentPage,
        lastPage: page.lastPage,
        filters: nextFilters,
      );
    } on ApiException catch (error) {
      state = state.copyWith(loadingMore: false, error: _error(error));
    } catch (_) {
      state = state.copyWith(
        loadingMore: false,
        error: 'تعذر تحميل المزيد من النزاعات.',
      );
    }
  }

  Future<WorkerDispute?> create({
    required int engagementId,
    required String category,
    required String description,
  }) async {
    if (state.creating) return null;
    state = state.copyWith(creating: true, clearActionError: true);
    try {
      final created = await _ref
          .read(workerRepositoryProvider)
          .createWorkerDispute(
            engagementId,
            category: category,
            description: description,
          );
      state = state.copyWith(
        creating: false,
        items: [created, ...state.items],
        total: state.total + 1,
        clearActionError: true,
      );
      return created;
    } on ApiException catch (error) {
      state = state.copyWith(creating: false, actionError: _error(error));
      return null;
    } catch (_) {
      state = state.copyWith(
        creating: false,
        actionError: 'تعذر تسجيل النزاع.',
      );
      return null;
    }
  }

  String _error(ApiException error) {
    if (error.statusCode == 422 && error.message.trim().isNotEmpty) {
      return error.message.trim();
    }
    return error.userMessage;
  }
}

class WorkerDisputeDetailState {
  const WorkerDisputeDetailState({
    this.loading = false,
    this.error,
    this.dispute,
  });

  final bool loading;
  final String? error;
  final WorkerDispute? dispute;

  WorkerDisputeDetailState copyWith({
    bool? loading,
    String? error,
    bool clearError = false,
    WorkerDispute? dispute,
  }) {
    return WorkerDisputeDetailState(
      loading: loading ?? this.loading,
      error: clearError ? null : error ?? this.error,
      dispute: dispute ?? this.dispute,
    );
  }
}

class WorkerDisputeDetailController
    extends StateNotifier<WorkerDisputeDetailState> {
  WorkerDisputeDetailController(this._ref, this.disputeId)
    : super(const WorkerDisputeDetailState(loading: true));

  final Ref _ref;
  final int? disputeId;

  Future<void> load() async {
    final id = disputeId;
    if (id == null) {
      state = const WorkerDisputeDetailState(error: 'غير موجود.');
      return;
    }
    state = state.copyWith(loading: true, clearError: true);
    try {
      final dispute = await _ref
          .read(workerRepositoryProvider)
          .getWorkerDispute(id);
      state = state.copyWith(
        loading: false,
        dispute: dispute,
        clearError: true,
      );
    } on ApiException catch (error) {
      state = state.copyWith(loading: false, error: error.userMessage);
    } catch (_) {
      state = state.copyWith(loading: false, error: 'تعذر تحميل النزاع.');
    }
  }
}

final workerDisputesProvider =
    StateNotifierProvider.autoDispose<
      WorkerDisputesController,
      WorkerDisputesState
    >((ref) {
      final controller = WorkerDisputesController(ref);
      controller.load();
      return controller;
    });

final workerDisputeDetailProvider = StateNotifierProvider.autoDispose
    .family<WorkerDisputeDetailController, WorkerDisputeDetailState, int?>((
      ref,
      id,
    ) {
      final controller = WorkerDisputeDetailController(ref, id);
      controller.load();
      return controller;
    });
