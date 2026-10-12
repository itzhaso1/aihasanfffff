import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/features/catalog/data/chat_catalog_models.dart';
import 'package:hasim/features/worker/data/worker_product.dart';

class WorkerSalesReferenceState {
  const WorkerSalesReferenceState({
    this.workspaceId,
    this.items = const [],
    this.loading = false,
    this.loaded = false,
    this.error,
    this.permissionDenied = false,
  });

  final int? workspaceId;
  final List<ChatCatalogItemModel> items;
  final bool loading;
  final bool loaded;
  final String? error;
  final bool permissionDenied;

  WorkerSalesReferenceState copyWith({
    int? workspaceId,
    bool clearWorkspaceId = false,
    List<ChatCatalogItemModel>? items,
    bool? loading,
    bool? loaded,
    String? error,
    bool clearError = false,
    bool? permissionDenied,
  }) {
    return WorkerSalesReferenceState(
      workspaceId: clearWorkspaceId ? null : (workspaceId ?? this.workspaceId),
      items: items ?? this.items,
      loading: loading ?? this.loading,
      loaded: loaded ?? this.loaded,
      error: clearError ? null : (error ?? this.error),
      permissionDenied: permissionDenied ?? this.permissionDenied,
    );
  }

  List<ChatCatalogItemModel> get products =>
      items.where((item) => item.itemKind == 'product').toList();

  List<ChatCatalogItemModel> get services =>
      items.where((item) => item.itemKind == 'service').toList();
}

final workerSalesReferenceControllerProvider =
    StateNotifierProvider.autoDispose<
      WorkerSalesReferenceController,
      WorkerSalesReferenceState
    >((ref) {
      final workspaceId = ref.watch(activeWorkspaceIdProvider);
      final controller = WorkerSalesReferenceController(ref, workspaceId);
      unawaited(controller.load());
      return controller;
    });

class WorkerSalesReferenceController
    extends StateNotifier<WorkerSalesReferenceState> {
  WorkerSalesReferenceController(this._ref, int? workspaceId)
    : _workspaceId = workspaceId,
      super(WorkerSalesReferenceState(workspaceId: workspaceId));

  final Ref _ref;
  final int? _workspaceId;
  Future<void>? _loadFuture;

  Future<void> load({bool force = false}) {
    final ongoing = _loadFuture;
    if (ongoing != null) return ongoing;
    if (_workspaceId == null) {
      state = state.copyWith(
        loading: false,
        loaded: true,
        items: const [],
        clearError: true,
        permissionDenied: false,
      );
      return Future<void>.value();
    }
    if (state.loaded && !force) return Future<void>.value();

    state = state.copyWith(
      loading: true,
      loaded: false,
      items: force ? const [] : null,
      clearError: true,
      permissionDenied: false,
    );
    final future = _load();
    _loadFuture = future.whenComplete(() => _loadFuture = null);
    return _loadFuture!;
  }

  Future<void> _load() async {
    try {
      final engagements = await _ref
          .read(workerRepositoryProvider)
          .engagements();
      final currentEngagements = engagements
          .where(
            (engagement) =>
                engagement.workspaceId == _workspaceId &&
                engagement.canViewAssignedProducts,
          )
          .toList();
      final itemsById = <int, WorkerProduct>{};
      var deniedRequests = 0;
      var successfulRequests = 0;

      for (final engagement in currentEngagements) {
        try {
          final items = await _ref
              .read(workerRepositoryProvider)
              .products(engagement.id);
          successfulRequests++;
          for (final item in items) {
            itemsById[item.id] = item;
          }
        } on ApiException catch (error) {
          if (error.statusCode == 403) {
            deniedRequests++;
            continue;
          }
          rethrow;
        }
      }
      if (!mounted) return;
      final items = itemsById.values.map(_toCatalogItem).toList();
      state = state.copyWith(
        items: items,
        loading: false,
        loaded: true,
        clearError: true,
        permissionDenied:
            currentEngagements.isNotEmpty &&
            successfulRequests == 0 &&
            deniedRequests == currentEngagements.length,
      );
    } on ApiException catch (error) {
      if (!mounted) return;
      state = state.copyWith(
        loading: false,
        loaded: true,
        error: error.userMessage,
        permissionDenied: false,
      );
    } catch (_) {
      if (!mounted) return;
      state = state.copyWith(
        loading: false,
        loaded: true,
        error: 'تعذر تحميل مرجع المبيعات.',
        permissionDenied: false,
      );
    }
  }

  ChatCatalogItemModel _toCatalogItem(WorkerProduct item) {
    return ChatCatalogItemModel(
      id: item.id,
      name: item.name,
      description: item.description,
      itemKind: item.itemKind,
      price: item.price ?? 0,
      workerMinimumPrice: item.minimumSellingPrice,
      workerMaximumPrice: item.maximumSellingPrice,
      currency: item.currency ?? 'SAR',
      imageUrl: item.imageUrl,
      category: item.categoryName == null
          ? null
          : ChatCatalogCategoryModel(id: 0, name: item.categoryName!),
      isActive: item.status != 'inactive',
    );
  }
}
