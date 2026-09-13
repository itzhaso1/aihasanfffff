
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';

class EmailState {
  const EmailState({
    this.tab = 'inbox',
    this.loading = false,
    this.items = const [],
    this.nextCursor,
    this.error,
  });
  final String tab;
  final bool loading;
  final List<EmailMessageModel> items;
  final String? nextCursor;
  final String? error;

  EmailState copyWith({String? tab, bool? loading, List<EmailMessageModel>? items, String? nextCursor, String? error, bool clearCursor = false}) =>
      EmailState(tab: tab ?? this.tab, loading: loading ?? this.loading, items: items ?? this.items, nextCursor: clearCursor ? nextCursor : (nextCursor ?? this.nextCursor), error: error);
}

class EmailController extends StateNotifier<EmailState> {
  EmailController(this._ref) : super(const EmailState()) {
    refresh();
  }
  final Ref _ref;

  Future<void> setTab(String tab) async {
    state = state.copyWith(tab: tab);
    await refresh();
  }

  Future<void> refresh() async {
    state = state.copyWith(loading: true, error: null, clearCursor: true, nextCursor: null);
    try {
      final repo = _ref.read(emailRepositoryProvider);
      final page = switch (state.tab) {
        'sent' => await repo.sent(),
        'drafts' => await repo.drafts(),
        _ => await repo.inbox(),
      };
      state = state.copyWith(loading: false, items: page.items, nextCursor: page.nextCursor, clearCursor: true);
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.message);
    } catch (_) {
      state = state.copyWith(loading: false, error: 'تعذر تحميل البريد.');
    }
  }
}

final emailControllerProvider = StateNotifierProvider<EmailController, EmailState>((ref) => EmailController(ref));
