import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/settings/data/company_worker_engagement_capabilities_repository.dart';

class CompanyWorkerEngagementCapabilitiesState {
  const CompanyWorkerEngagementCapabilitiesState({
    this.loading = false,
    this.capabilities = const [],
    this.status = '',
    this.error,
    this.actionError,
    this.savingKey,
  });

  final bool loading;
  final List<CompanyWorkerEngagementCapability> capabilities;
  final String status;
  final String? error;
  final String? actionError;
  final String? savingKey;

  bool get hasData => capabilities.isNotEmpty;

  CompanyWorkerEngagementCapabilitiesState copyWith({
    bool? loading,
    List<CompanyWorkerEngagementCapability>? capabilities,
    String? status,
    String? error,
    bool clearError = false,
    String? actionError,
    bool clearActionError = false,
    String? savingKey,
    bool clearSavingKey = false,
  }) {
    return CompanyWorkerEngagementCapabilitiesState(
      loading: loading ?? this.loading,
      capabilities: capabilities ?? this.capabilities,
      status: status ?? this.status,
      error: clearError ? null : error ?? this.error,
      actionError: clearActionError ? null : actionError ?? this.actionError,
      savingKey: clearSavingKey ? null : savingKey ?? this.savingKey,
    );
  }
}

final companyWorkerEngagementCapabilitiesRepositoryProvider = Provider(
  (ref) => CompanyWorkerEngagementCapabilitiesRepository(
    ref.watch(apiClientProvider),
  ),
);

final companyWorkerEngagementCapabilitiesControllerProvider =
    StateNotifierProvider.autoDispose.family<
      CompanyWorkerEngagementCapabilitiesController,
      CompanyWorkerEngagementCapabilitiesState,
      int
    >((ref, engagementId) {
      final controller = CompanyWorkerEngagementCapabilitiesController(
        ref,
        engagementId,
      );
      unawaited(controller.load());
      return controller;
    });

class CompanyWorkerEngagementCapabilitiesController
    extends StateNotifier<CompanyWorkerEngagementCapabilitiesState> {
  CompanyWorkerEngagementCapabilitiesController(this._ref, this.engagementId)
    : super(const CompanyWorkerEngagementCapabilitiesState(loading: true));

  final Ref _ref;
  final int engagementId;

  Future<void> load() async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      final result = await _ref
          .read(companyWorkerEngagementCapabilitiesRepositoryProvider)
          .fetch(engagementId);
      state = state.copyWith(
        loading: false,
        capabilities: result.items,
        status: result.status,
        clearError: true,
        clearActionError: true,
      );
    } on ApiException catch (error) {
      state = state.copyWith(loading: false, error: error.userMessage);
    } catch (_) {
      state = state.copyWith(
        loading: false,
        error: 'تعذر تحميل صلاحيات الموظف.',
      );
    }
  }

  Future<bool> toggle(
    CompanyWorkerEngagementCapability capability,
    bool allowed,
  ) async {
    if (state.savingKey != null || capability.allowed == allowed) {
      return false;
    }

    state = state.copyWith(savingKey: capability.key, clearActionError: true);

    try {
      final repository = _ref.read(
        companyWorkerEngagementCapabilitiesRepositoryProvider,
      );
      final result = allowed
          ? await repository.grant(engagementId, capability.key)
          : await repository.revoke(engagementId, capability.key);
      state = state.copyWith(
        capabilities: result.items,
        status: result.status,
        clearActionError: true,
        clearSavingKey: true,
      );
      return true;
    } on ApiException catch (error) {
      state = state.copyWith(
        actionError: error.userMessage,
        clearSavingKey: true,
      );
      return false;
    } catch (_) {
      state = state.copyWith(
        actionError: allowed ? 'تعذر منح الصلاحية.' : 'تعذر سحب الصلاحية.',
        clearSavingKey: true,
      );
      return false;
    }
  }
}
