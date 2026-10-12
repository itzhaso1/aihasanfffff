import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

class SearchState {
  const SearchState({
    this.query = '',
    this.appliedQuery = '',
    this.loading = false,
    this.error,
    this.conversations = const [],
    this.customers = const [],
    this.emails = const [],
  });

  final String query;
  final String appliedQuery;
  final bool loading;
  final String? error;
  final List<ConversationModel> conversations;
  final List<CustomerModel> customers;
  final List<EmailMessageModel> emails;

  bool get hasQuery => query.trim().isNotEmpty;

  bool get isEmpty =>
      conversations.isEmpty && customers.isEmpty && emails.isEmpty;

  bool get isIdle =>
      !loading && error == null && appliedQuery.isEmpty && query.trim().isEmpty;

  SearchState copyWith({
    String? query,
    String? appliedQuery,
    bool? loading,
    String? error,
    bool clearError = false,
    List<ConversationModel>? conversations,
    List<CustomerModel>? customers,
    List<EmailMessageModel>? emails,
  }) {
    return SearchState(
      query: query ?? this.query,
      appliedQuery: appliedQuery ?? this.appliedQuery,
      loading: loading ?? this.loading,
      error: clearError ? null : (error ?? this.error),
      conversations: conversations ?? this.conversations,
      customers: customers ?? this.customers,
      emails: emails ?? this.emails,
    );
  }
}

final searchControllerProvider =
    StateNotifierProvider<GlobalSearchController, SearchState>((ref) {
      final controller = GlobalSearchController(ref);
      ref.listen<String>(
        authControllerProvider.select((s) => s.isolationKey),
        (previous, next) {
          if (previous != next) {
            controller.reset();
          }
        },
      );
      return controller;
    });

class GlobalSearchController extends StateNotifier<SearchState> {
  GlobalSearchController(this._ref) : super(const SearchState());

  static const debounceDuration = Duration(milliseconds: 350);

  final Ref _ref;
  Timer? _debounce;
  int _generation = 0;
  String? _inFlightQuery;

  void onQueryChanged(String raw) {
    final q = raw.trim();
    state = state.copyWith(query: raw);
    _debounce?.cancel();
    if (q.isEmpty) {
      reset();
      return;
    }
    _debounce = Timer(debounceDuration, () {
      unawaited(_run(q));
    });
  }

  Future<void> submit(String raw) {
    _debounce?.cancel();
    final q = raw.trim();
    state = state.copyWith(query: raw);
    if (q.isEmpty) {
      reset();
      return Future.value();
    }
    return _run(q);
  }

  Future<void> retry() {
    return submit(state.query);
  }

  void reset() {
    _debounce?.cancel();
    _generation++;
    _inFlightQuery = null;
    state = const SearchState();
  }

  Future<void> _run(String query) async {
    final q = query.length > 255 ? query.substring(0, 255) : query;
    if (_inFlightQuery == q && state.loading) {
      return;
    }
    if (q == state.appliedQuery &&
        !state.loading &&
        state.error == null &&
        !state.isIdle) {
      return;
    }

    final gen = ++_generation;
    _inFlightQuery = q;
    state = SearchState(query: q, loading: true);

    try {
      final payload = await _ref
          .read(searchRepositoryProvider)
          .search(q);
      if (!mounted || gen != _generation) {
        return;
      }
      _inFlightQuery = null;
      state = SearchState(
        query: q,
        appliedQuery: q,
        conversations: payload.conversations,
        customers: payload.customers,
        emails: payload.emails,
      );
    } on ApiException catch (e) {
      if (!mounted || gen != _generation) {
        return;
      }
      _inFlightQuery = null;
      state = SearchState(query: q, appliedQuery: q, error: e.userMessage);
    } catch (_) {
      if (!mounted || gen != _generation) {
        return;
      }
      _inFlightQuery = null;
      state = SearchState(
        query: q,
        appliedQuery: q,
        error: 'تعذر تنفيذ البحث.',
      );
    }
  }

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }
}
