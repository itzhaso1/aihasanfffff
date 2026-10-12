import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/worker/data/worker_earning.dart';

class WorkerEarningsState {
  const WorkerEarningsState({
    this.loading = false,
    this.busy = false,
    this.error,
    this.actionError,
    this.periods = const [],
    this.expandedPeriodId,
  });

  final bool loading;
  final bool busy;
  final String? error;
  final String? actionError;
  final List<WorkerEarningPeriod> periods;
  final int? expandedPeriodId;

  WorkerEarningsState copyWith({
    bool? loading,
    bool? busy,
    String? error,
    bool clearError = false,
    String? actionError,
    bool clearActionError = false,
    List<WorkerEarningPeriod>? periods,
    int? expandedPeriodId,
    bool clearExpanded = false,
  }) {
    return WorkerEarningsState(
      loading: loading ?? this.loading,
      busy: busy ?? this.busy,
      error: clearError ? null : error ?? this.error,
      actionError: clearActionError ? null : actionError ?? this.actionError,
      periods: periods ?? this.periods,
      expandedPeriodId: clearExpanded
          ? null
          : expandedPeriodId ?? this.expandedPeriodId,
    );
  }
}

class WorkerEarningsController extends StateNotifier<WorkerEarningsState> {
  WorkerEarningsController(this._ref, this.engagementId)
    : super(const WorkerEarningsState(loading: true));

  final Ref _ref;
  final int engagementId;

  Future<void> load() async {
    if (state.busy) return;
    final repository = _ref.read(workerRepositoryProvider);
    final cached = await repository.cachedEarnings(engagementId);
    if (cached.isNotEmpty) {
      state = state.copyWith(
        loading: false,
        periods: [for (final period in cached) if (!period.isDraft) period],
        clearError: true,
      );
    } else {
      state = state.copyWith(loading: true, clearError: true);
    }
    try {
      final periods = [
        for (final period in await repository.getWorkerEarnings(engagementId))
          if (!period.isDraft) period,
      ];
      state = state.copyWith(
        loading: false,
        periods: periods,
        clearError: true,
        clearActionError: true,
      );
    } on ApiException catch (error) {
      state = state.copyWith(loading: false, error: state.periods.isEmpty ? _error(error) : null);
    } catch (_) {
      state = state.copyWith(loading: false, error: state.periods.isEmpty ? 'تعذر تحميل الأرباح.' : null);
    }
  }

  void togglePeriod(int periodId) {
    if (state.expandedPeriodId == periodId) {
      state = state.copyWith(clearExpanded: true);
      return;
    }
    state = state.copyWith(expandedPeriodId: periodId);
  }

  Future<bool> disputePeriod(int periodId, {String? reason}) async {
    if (state.busy) return false;
    state = state.copyWith(busy: true, clearActionError: true);
    try {
      final updated = await _ref
          .read(workerRepositoryProvider)
          .disputeWorkerEarningPeriod(engagementId, periodId, reason: reason);
      state = state.copyWith(
        busy: false,
        periods: [
          for (final period in state.periods)
            if (period.id == updated.id) updated else period,
        ],
        expandedPeriodId: updated.id,
        clearActionError: true,
      );
      return true;
    } on ApiException catch (error) {
      state = state.copyWith(busy: false, actionError: _error(error));
      return false;
    } catch (_) {
      state = state.copyWith(
        busy: false,
        actionError: 'تعذر تسجيل الاعتراض على فترة الأرباح.',
      );
      return false;
    }
  }

  String _error(ApiException error) {
    if (error.statusCode == 422 && error.message.trim().isNotEmpty) {
      return error.message.trim();
    }
    return error.userMessage;
  }
}

final workerEarningsProvider = StateNotifierProvider.autoDispose
    .family<WorkerEarningsController, WorkerEarningsState, int>((
      ref,
      engagementId,
    ) {
      final controller = WorkerEarningsController(ref, engagementId);
      controller.load();
      return controller;
    });
