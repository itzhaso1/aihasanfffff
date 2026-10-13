import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';

class ChatCatalogCategoriesState {
  const ChatCatalogCategoriesState({
    this.items = const [],
    this.loading = false,
    this.saving = false,
    this.error,
    this.nextCursor,
    this.loadingMore = false,
  });

  final List<ChatCatalogCategoryModel> items;
  final bool loading;
  final bool saving;
  final String? error;
  final String? nextCursor;
  final bool loadingMore;

  ChatCatalogCategoriesState copyWith({
    List<ChatCatalogCategoryModel>? items,
    bool? loading,
    bool? saving,
    String? error,
    bool clearError = false,
    String? nextCursor,
    bool clearCursor = false,
    bool? loadingMore,
  }) {
    return ChatCatalogCategoriesState(
      items: items ?? this.items,
      loading: loading ?? this.loading,
      saving: saving ?? this.saving,
      error: clearError ? null : (error ?? this.error),
      nextCursor: clearCursor ? nextCursor : (nextCursor ?? this.nextCursor),
      loadingMore: loadingMore ?? this.loadingMore,
    );
  }
}

final chatCatalogCategoriesControllerProvider =
    StateNotifierProvider<ChatCatalogCategoriesController, ChatCatalogCategoriesState>((ref) {
  final controller = ChatCatalogCategoriesController(ref);
  ref.listen<String>(
    authControllerProvider.select((s) => s.isolationKey),
    (previous, next) {
      if (previous != next) controller.reload();
    },
  );
  return controller;
});

class ChatCatalogCategoriesController extends StateNotifier<ChatCatalogCategoriesState> {
  ChatCatalogCategoriesController(this._ref) : super(const ChatCatalogCategoriesState()) {
    reload();
  }

  final Ref _ref;

  Future<void> reload() async {
    final cached = await _ref.read(chatCatalogRepositoryProvider).cachedCategories();
    if (cached.isNotEmpty) {
      state = state.copyWith(items: cached, loading: false, clearError: true);
    } else {
      state = const ChatCatalogCategoriesState(loading: true);
    }
    try {
      final page = await _ref.read(chatCatalogRepositoryProvider).listCategories();
      state = ChatCatalogCategoriesState(items: page.items, nextCursor: page.nextCursor);
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.message);
    } catch (_) {
      state = state.copyWith(loading: false, error: 'catalogCategoriesLoadFailed');
    }
  }

  Future<void> loadMore() async {
    if (state.loadingMore || state.nextCursor == null) return;
    state = state.copyWith(loadingMore: true, clearError: true);
    try {
      final page = await _ref.read(chatCatalogRepositoryProvider).listCategories(cursor: state.nextCursor);
      state = state.copyWith(
        items: [...state.items, ...page.items],
        nextCursor: page.nextCursor,
        loadingMore: false,
      );
    } on ApiException catch (e) {
      state = state.copyWith(loadingMore: false, error: e.message);
    } catch (_) {
      state = state.copyWith(loadingMore: false, error: 'catalogCategoriesLoadFailed');
    }
  }

  Future<bool> saveCategory(ChatCatalogCategoryModel draft, {int? id}) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      final repo = _ref.read(chatCatalogRepositoryProvider);
      if (id == null) {
        await repo.createCategory(draft);
      } else {
        await repo.updateCategory(id, draft);
      }
      await reload();
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.message);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'catalogCategorySaveFailed');
      return false;
    }
  }

  Future<bool> deleteCategory(int id) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      await _ref.read(chatCatalogRepositoryProvider).deleteCategory(id);
      await reload();
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.message);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'catalogCategoryDeleteFailed');
      return false;
    }
  }
}
