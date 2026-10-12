import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

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
  }) => EmailState(
    tab: tab ?? this.tab,
    query: query ?? this.query,
    loading: loading ?? this.loading,
    items: items ?? this.items,
    nextCursor: clearCursor ? nextCursor : (nextCursor ?? this.nextCursor),
    error: error,
  );
}

class EmailController extends StateNotifier<EmailState> {
  EmailController(this._ref) : super(const EmailState(loading: true)) {
    _bind();
    refresh();
  }
  final Ref _ref;
  StreamSubscription<List<EmailMessageModel>>? _mailSub;
  int _epoch = 0;

  void _bind() {
    _mailSub?.cancel();
    final search = state.query.trim().isEmpty ? null : state.query.trim();
    _mailSub = _ref
        .read(emailRepositoryProvider)
        .watch(folder: state.tab, search: search)
        .listen((items) {
          if (!mounted) return;
          state = state.copyWith(
            items: items,
            loading: items.isEmpty && state.loading,
          );
        });
  }

  @override
  void dispose() {
    _mailSub?.cancel();
    super.dispose();
  }

  void onIsolationChanged() {
    state = const EmailState(loading: true);
    _bind();
    unawaited(refresh());
  }

  Future<void> setTab(String tab) async {
    state = state.copyWith(tab: tab);
    _bind();
    await refresh();
  }

  Future<void> setSearch(String query) async {
    state = state.copyWith(query: query);
    _bind();
    await refresh();
  }

  Future<void> toggleStar(EmailMessageModel message) async {
    try {
      await _ref.read(emailRepositoryProvider).star(message.id);
      state = state.copyWith(
        items: [
          for (final item in state.items)
            if (item.id == message.id)
              item.copyWith(isStarred: !item.isStarred)
            else
              item,
        ],
      );
    } catch (_) {
      // الإبقاء على الحالة الحالية إذا فشل التمييز.
    }
  }

  Future<void> refresh() async {
    final epoch = ++_epoch;
    final repo = _ref.read(emailRepositoryProvider);
    if (state.items.isEmpty) {
      state = state.copyWith(
        loading: true,
        error: null,
        clearCursor: true,
        nextCursor: null,
      );
    }
    try {
      final search = state.query.trim().isEmpty ? null : state.query.trim();
      final page = switch (state.tab) {
        'sent' => await repo.sent(search: search),
        'drafts' => await repo.drafts(search: search),
        _ => await repo.inbox(search: search),
      };
      if (!mounted || epoch != _epoch) return;
      state = state.copyWith(
        loading: false,
        items: page.items,
        nextCursor: page.nextCursor,
        clearCursor: true,
        error: repo.lastReadFromCache
            ? 'تعذر التحديث. تُعرض البيانات المحفوظة على الجهاز.'
            : null,
      );
    } on ApiException catch (e) {
      if (!mounted || epoch != _epoch) return;
      if (e.statusCode == 403) {
        await repo.clearLocalWorkspaceData();
        if (!mounted || epoch != _epoch) return;
        state = state.copyWith(
          loading: false,
          items: const [],
          error: e.userMessage,
        );
        return;
      }
      state = state.copyWith(loading: false, error: e.userMessage);
    } catch (_) {
      if (!mounted || epoch != _epoch) return;
      state = state.copyWith(loading: false, error: 'تعذر تحميل البريد.');
    }
  }
}

final emailControllerProvider =
    StateNotifierProvider<EmailController, EmailState>((ref) {
      final controller = EmailController(ref);
      ref.listen<String>(authControllerProvider.select((s) => s.isolationKey), (
        previous,
        next,
      ) {
        if (previous != null && previous != next) {
          controller.onIsolationChanged();
        }
      });
      return controller;
    });
