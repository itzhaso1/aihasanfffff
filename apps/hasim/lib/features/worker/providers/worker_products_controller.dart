import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/worker/data/worker_product.dart';

class WorkerProductsState {
  const WorkerProductsState({
    this.loading = true,
    this.items = const [],
    this.error,
    this.permissionDenied = false,
  });

  final bool loading;
  final List<WorkerProduct> items;
  final String? error;
  final bool permissionDenied;

  WorkerProductsState copyWith({
    bool? loading,
    List<WorkerProduct>? items,
    String? error,
    bool? permissionDenied,
    bool clearError = false,
  }) {
    return WorkerProductsState(
      loading: loading ?? this.loading,
      items: items ?? this.items,
      error: clearError ? null : error ?? this.error,
      permissionDenied: permissionDenied ?? this.permissionDenied,
    );
  }
}

class WorkerProductsController extends StateNotifier<WorkerProductsState> {
  WorkerProductsController(this._ref, this.engagementId)
    : super(const WorkerProductsState()) {
    load();
  }

  final Ref _ref;
  final int engagementId;

  Future<void> load() async {
    final repository = _ref.read(workerRepositoryProvider);
    final cached = await repository.cachedProducts(engagementId);
    if (cached.isNotEmpty) {
      state = state.copyWith(loading: false, items: cached, permissionDenied: false, clearError: true);
    } else {
      state = state.copyWith(loading: true, permissionDenied: false, clearError: true);
    }
    try {
      final items = await repository.products(engagementId);
      state = state.copyWith(
        loading: false,
        items: items,
        permissionDenied: false,
        clearError: true,
      );
    } on ApiException catch (error) {
      state = state.copyWith(
        loading: false,
        permissionDenied: error.statusCode == 403,
        error: state.items.isEmpty && error.statusCode != 403 ? error.userMessage : null,
      );
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: state.items.isEmpty ? 'تعذر تحميل المنتجات.' : null,
      );
    }
  }
}

final workerProductsProvider = StateNotifierProvider.autoDispose
    .family<WorkerProductsController, WorkerProductsState, int>(
      (ref, engagementId) => WorkerProductsController(ref, engagementId),
    );
