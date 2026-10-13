import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/settings/data/company_profile_model.dart';

class CompanyProfileState {
  const CompanyProfileState({
    this.profile,
    this.loading = false,
    this.saving = false,
    this.forbidden = false,
    this.error,
  });

  final CompanyProfileModel? profile;
  final bool loading;
  final bool saving;
  final bool forbidden;
  final String? error;

  CompanyProfileState copyWith({
    CompanyProfileModel? profile,
    bool? loading,
    bool? saving,
    bool? forbidden,
    String? error,
    bool clearError = false,
  }) {
    return CompanyProfileState(
      profile: profile ?? this.profile,
      loading: loading ?? this.loading,
      saving: saving ?? this.saving,
      forbidden: forbidden ?? this.forbidden,
      error: clearError ? null : (error ?? this.error),
    );
  }
}

final companyProfileControllerProvider =
    StateNotifierProvider<CompanyProfileController, CompanyProfileState>((ref) {
  final controller = CompanyProfileController(ref);
  ref.listen<String>(
    authControllerProvider.select((s) => s.isolationKey),
    (previous, next) {
      if (previous != next) {
        controller.reload();
      }
    },
  );
  return controller;
});

class CompanyProfileController extends StateNotifier<CompanyProfileState> {
  CompanyProfileController(this._ref) : super(const CompanyProfileState()) {
    reload();
  }

  final Ref _ref;

  Future<void> reload() async {
    final workspaceId = _ref.read(authControllerProvider).workspace?.id;
    if (workspaceId == null) {
      state = const CompanyProfileState();
      return;
    }

    state = state.copyWith(loading: true, forbidden: false, clearError: true);
    try {
      final profile = await _ref.read(aiRepositoryProvider).fetchCompanyProfile();
      state = CompanyProfileState(profile: profile);
    } on ApiException catch (e) {
      state = CompanyProfileState(
        forbidden: e.statusCode == 403,
        error: e.userMessage,
      );
    } catch (_) {
      state = const CompanyProfileState(error: 'تعذر تحميل نبذة الشركة.');
    }
  }

  Future<bool> save(CompanyProfileModel draft) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      final updated = await _ref
          .read(aiRepositoryProvider)
          .updateCompanyProfile(draft.toUpdateJson());
      state = CompanyProfileState(profile: updated);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(
        saving: false,
        forbidden: e.statusCode == 403,
        error: e.userMessage,
      );
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر حفظ نبذة الشركة.');
      return false;
    }
  }
}
