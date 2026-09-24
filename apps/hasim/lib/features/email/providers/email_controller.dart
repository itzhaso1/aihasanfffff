
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';

class EmailState {
  const EmailState({
    this.tab = 'inbox',
    this.query = '',
    this.loading = false,
    this.items = const [],
    this.nextCursor,
    this.error,
  });
  final String tab;
  final String query;
  final bool loading;
  final List<EmailMessageModel> items;
  final String? nextCursor;
  final String? error;

  EmailState copyWith({
    String? tab,
    String? query,
    bool? loading,
    List<EmailMessageModel>? items,
    String? nextCursor,
    String? error,
    bool clearCursor = false,
  }) =>
      EmailState(
        tab: tab ?? this.tab,
        query: query ?? this.query,
        loading: loading ?? this.loading,
        items: items ?? this.items,
        nextCursor: clearCursor ? nextCursor : (nextCursor ?? this.nextCursor),
        error: error,
      );
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

  Future<void> setSearch(String query) async {
    state = state.copyWith(query: query);
    await refresh();
  }

  Future<void> toggleStar(EmailMessageModel message) async {
    try {
      await _ref.read(emailRepositoryProvider).star(message.id);
      state = state.copyWith(
        items: [
          for (final item in state.items)
            if (item.id == message.id) item.copyWith(isStarred: !item.isStarred) else item,
        ],
      );
    } catch (_) {
      // الإبقاء على الحالة الحالية إذا فشل التمييز.
    }
  }

  Future<void> refresh() async {
    state = state.copyWith(loading: true, error: null, clearCursor: true, nextCursor: null);
    try {
      final repo = _ref.read(emailRepositoryProvider);
      final search = state.query.trim().isEmpty ? null : state.query.trim();
      final page = switch (state.tab) {
        'sent' => await repo.sent(search: search),
        'drafts' => await repo.drafts(search: search),
        _ => await repo.inbox(search: search),
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
