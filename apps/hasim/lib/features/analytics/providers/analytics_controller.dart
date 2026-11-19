import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/features/analytics/data/analytics_models.dart';
import 'package:hasim/features/analytics/data/analytics_repository.dart';

class AnalyticsState {
  const AnalyticsState({
    this.analytics,
    this.range = 'month',
    this.loading = false,
    this.error,
  });

  final WorkspaceAnalyticsModel? analytics;
  final String range;
  final bool loading;
  final String? error;

  AnalyticsState copyWith({
    WorkspaceAnalyticsModel? analytics,
    String? range,
    bool? loading,
    String? error,
  }) {
    return AnalyticsState(
      analytics: analytics ?? this.analytics,
      range: range ?? this.range,
      loading: loading ?? this.loading,
      error: error,
    );
  }
}

final analyticsControllerProvider =
    StateNotifierProvider.autoDispose<AnalyticsController, AnalyticsState>((
      ref,
    ) {
      return AnalyticsController(ref.watch(analyticsRepositoryProvider));
    });

class AnalyticsController extends StateNotifier<AnalyticsState> {
  AnalyticsController(this._repo) : super(const AnalyticsState()) {
    load('month');
  }

  final AnalyticsRepository _repo;

  Future<void> load(String range) async {
    state = state.copyWith(range: range, loading: true, error: null);
    try {
      final res = await _repo.summary(range: range);
      state = state.copyWith(analytics: res, loading: false);
    } catch (e) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر تحميل بيانات التحليلات.',
      );
    }
  }
}
