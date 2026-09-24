import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/settings/data/agent_persona_model.dart';
import 'package:hasim/features/settings/data/ai_setting_model.dart';

class AiSettingsState {
  const AiSettingsState({
    this.settings,
    this.catalog = const AgentPersonaCatalog(),
    this.aiRoles = const [],
    this.loading = false,
    this.saving = false,
    this.error,
  });

  final AiSettingModel? settings;
  final AgentPersonaCatalog catalog;
  final List<String> aiRoles;
  final bool loading;
  final bool saving;
  final String? error;

  AiSettingsState copyWith({
    AiSettingModel? settings,
    AgentPersonaCatalog? catalog,
    List<String>? aiRoles,
    bool? loading,
    bool? saving,
    String? error,
    bool clearError = false,
  }) {
    return AiSettingsState(
      settings: settings ?? this.settings,
      catalog: catalog ?? this.catalog,
      aiRoles: aiRoles ?? this.aiRoles,
      loading: loading ?? this.loading,
      saving: saving ?? this.saving,
      error: clearError ? null : (error ?? this.error),
    );
  }
}

final aiSettingsControllerProvider =
    StateNotifierProvider<AiSettingsController, AiSettingsState>((ref) {
  final controller = AiSettingsController(ref);
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

class AiSettingsController extends StateNotifier<AiSettingsState> {
  AiSettingsController(this._ref) : super(const AiSettingsState()) {
    reload();
  }

  final Ref _ref;

  Future<void> reload() async {
    final workspaceId = _ref.read(authControllerProvider).workspace?.id;
    if (workspaceId == null) {
      state = const AiSettingsState();
      return;
    }

    state = state.copyWith(loading: true, clearError: true);
    try {
      final payload = await _ref.read(aiRepositoryProvider).fetchSettings();
      state = AiSettingsState(
        settings: payload.settings,
        catalog: payload.catalog,
        aiRoles: payload.aiRoles,
      );
    } on ApiException catch (e) {
      state = AiSettingsState(error: e.message);
    } catch (_) {
      state = const AiSettingsState(error: 'تعذر تحميل إعدادات الذكاء الاصطناعي.');
    }
  }

  Future<bool> save(AiSettingModel draft) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      final updated = await _ref.read(aiRepositoryProvider).updateSettings(draft.toUpdateJson());
      state = AiSettingsState(
        settings: updated,
        catalog: state.catalog,
        aiRoles: state.aiRoles,
      );
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.message);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر حفظ الإعدادات.');
      return false;
    }
  }
}
