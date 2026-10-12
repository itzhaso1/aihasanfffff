import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/worker/data/worker_payout.dart';

class WorkerPayoutsState {
  const WorkerPayoutsState({
    this.loading = false,
    this.error,
    this.items = const [],
  });

  final bool loading;
  final String? error;
  final List<WorkerPayout> items;

  List<WorkerPayout> forEngagement(int engagementId) {
    return [
      for (final item in items)
        if (item.workerEngagementId == engagementId) item,
    ];
  }

  WorkerPayoutsState copyWith({
    bool? loading,
    String? error,
    bool clearError = false,
    List<WorkerPayout>? items,
  }) {
    return WorkerPayoutsState(
      loading: loading ?? this.loading,
      error: clearError ? null : error ?? this.error,
      items: items ?? this.items,
    );
  }
}

class WorkerPayoutsController extends StateNotifier<WorkerPayoutsState> {
  WorkerPayoutsController(this._ref)
    : super(const WorkerPayoutsState(loading: true));

  final Ref _ref;

  Future<void> load() async {
    final repository = _ref.read(workerRepositoryProvider);
    final cached = await repository.cachedPayouts();
    if (cached.isNotEmpty) {
      state = state.copyWith(loading: false, items: cached, clearError: true);
    } else {
      state = state.copyWith(loading: true, clearError: true);
    }
    try {
      final items = await repository.getWorkerPayouts();
      state = state.copyWith(loading: false, items: items, clearError: true);
    } on ApiException catch (error) {
      state = state.copyWith(loading: false, error: state.items.isEmpty ? error.userMessage : null);
    } catch (_) {
      state = state.copyWith(loading: false, error: state.items.isEmpty ? 'تعذر تحميل الدفعات.' : null);
    }
  }
}

final workerPayoutsProvider =
    StateNotifierProvider.autoDispose<
      WorkerPayoutsController,
      WorkerPayoutsState
    >((ref) {
      final controller = WorkerPayoutsController(ref);
      controller.load();
      return controller;
    });
