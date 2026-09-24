import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/catalog/data/chat_catalog_settings_model.dart';

class ChatCatalogSettingsState {
  const ChatCatalogSettingsState({
    this.settings,
    this.loading = false,
    this.saving = false,
    this.error,
  });

  final ChatCatalogSettingsModel? settings;
  final bool loading;
  final bool saving;
  final String? error;

  ChatCatalogSettingsState copyWith({
    ChatCatalogSettingsModel? settings,
    bool? loading,
    bool? saving,
    String? error,
    bool clearError = false,
  }) {
    return ChatCatalogSettingsState(
      settings: settings ?? this.settings,
      loading: loading ?? this.loading,
      saving: saving ?? this.saving,
      error: clearError ? null : (error ?? this.error),
    );
  }
}

final chatCatalogSettingsControllerProvider =
    StateNotifierProvider<ChatCatalogSettingsController, ChatCatalogSettingsState>((ref) {
  final controller = ChatCatalogSettingsController(ref);
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

class ChatCatalogSettingsController extends StateNotifier<ChatCatalogSettingsState> {
  ChatCatalogSettingsController(this._ref) : super(const ChatCatalogSettingsState()) {
    reload();
  }

  final Ref _ref;

  Future<void> reload() async {
    final workspaceId = _ref.read(authControllerProvider).workspace?.id;
    if (workspaceId == null) {
      state = const ChatCatalogSettingsState();
      return;
    }

    state = state.copyWith(loading: true, clearError: true);
    try {
      final settings = await _ref.read(chatCatalogRepositoryProvider).fetchSettings();
      state = ChatCatalogSettingsState(settings: settings);
    } on ApiException catch (e) {
      state = ChatCatalogSettingsState(error: e.message);
    } catch (_) {
      state = const ChatCatalogSettingsState(error: 'تعذر تحميل إعدادات الكتالوج.');
    }
  }

  Future<bool> saveGeneral(ChatCatalogSettingsModel draft) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      final updated = await _ref.read(chatCatalogRepositoryProvider).updateSettings(draft.toGeneralPatch());
      state = ChatCatalogSettingsState(settings: updated);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.message);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر حفظ الإعدادات.');
      return false;
    }
  }

  Future<bool> saveCommunication(ChatCatalogCommunicationProfile profile) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      final updated =
          await _ref.read(chatCatalogRepositoryProvider).updateCommunication(profile.toJson());
      state = ChatCatalogSettingsState(settings: updated);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.message);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر حفظ إعدادات التواصل.');
      return false;
    }
  }

  Future<bool> transition(String action) async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      final updated = await _ref.read(chatCatalogRepositoryProvider).transitionSettings(action);
      state = ChatCatalogSettingsState(settings: updated);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.message);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر تحديث حالة الكتالوج.');
      return false;
    }
  }
}
