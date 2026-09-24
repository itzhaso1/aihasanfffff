import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/search/data/global_search_models.dart';

class GlobalSearchState {
  const GlobalSearchState({
    this.query = '',
    this.loading = false,
    this.offline = false,
    this.result,
    this.error,
    this.submittedQuery,
  });

  final String query;
  final bool loading;
  final bool offline;
  final GlobalSearchResult? result;
  final String? error;
  final String? submittedQuery;

  GlobalSearchState copyWith({
    String? query,
    bool? loading,
    bool? offline,
    GlobalSearchResult? result,
    String? error,
    String? submittedQuery,
    bool clearResult = false,
    bool clearError = false,
  }) {
    return GlobalSearchState(
      query: query ?? this.query,
      loading: loading ?? this.loading,
      offline: offline ?? this.offline,
      result: clearResult ? null : (result ?? this.result),
      error: clearError ? null : (error ?? this.error),
      submittedQuery: submittedQuery ?? this.submittedQuery,
    );
  }
}

typedef ConnectivityProbe = Future<bool> Function();

final globalSearchConnectivityProvider = Provider<ConnectivityProbe>((ref) {
  return () async {
    try {
      final results = await Connectivity().checkConnectivity();
      return results.any((r) => r != ConnectivityResult.none);
    } catch (_) {
      return true;
    }
  };
});

final globalSearchControllerProvider =
    StateNotifierProvider<GlobalSearchController, GlobalSearchState>((ref) {
  final controller = GlobalSearchController(ref);
  ref.listen<String>(
    authControllerProvider.select((s) => s.isolationKey),
    (previous, next) {
      if (previous != null && previous != next) {
        controller.resetForWorkspaceChange();
      }
    },
  );
  return controller;
});

class GlobalSearchController extends StateNotifier<GlobalSearchState> {
  GlobalSearchController(this._ref) : super(const GlobalSearchState());

  final Ref _ref;
  Timer? _debounce;
  int _requestSeq = 0;

  static const _debounceMs = 350;
  static const _defaultLimit = 10;

  void setQuery(String value) {
    final trimmed = value.trim();
    state = state.copyWith(query: value, clearError: true, offline: false);
    _debounce?.cancel();
    if (trimmed.isEmpty) {
      state = state.copyWith(clearResult: true, loading: false, submittedQuery: '');
      return;
    }
    _debounce = Timer(const Duration(milliseconds: _debounceMs), () {
      unawaited(runSearch(trimmed));
    });
  }

  void resetForWorkspaceChange() {
    _debounce?.cancel();
    _requestSeq++;
    state = const GlobalSearchState();
  }

  Future<void> retry() async {
    final q = state.submittedQuery?.trim();
    if (q == null || q.isEmpty) return;
    await runSearch(q);
  }

  Future<void> runSearch(String query) async {
    final q = query.trim();
    if (q.isEmpty) return;

    final isolationKey = _ref.read(authControllerProvider).isolationKey;
    final seq = ++_requestSeq;

    state = state.copyWith(
      loading: true,
      clearError: true,
      offline: false,
      submittedQuery: q,
    );

    if (!await _ref.read(globalSearchConnectivityProvider)()) {
      if (!_stillCurrent(isolationKey, seq)) return;
      state = state.copyWith(loading: false, offline: true);
      return;
    }

    try {
      final result = await _ref.read(searchRepositoryProvider).search(q, limit: _defaultLimit);
      if (!_stillCurrent(isolationKey, seq)) return;
      state = state.copyWith(loading: false, result: result, clearError: true);
    } on ApiException catch (e) {
      if (!_stillCurrent(isolationKey, seq)) return;
      state = state.copyWith(loading: false, error: e.message);
    } catch (_) {
      if (!_stillCurrent(isolationKey, seq)) return;
      state = state.copyWith(loading: false, error: 'search_failed');
    }
  }

  bool _stillCurrent(String isolationKey, int seq) {
    if (!mounted) return false;
    if (seq != _requestSeq) return false;
    return _ref.read(authControllerProvider).isolationKey == isolationKey;
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }
}
