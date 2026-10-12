import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/network/api_exception.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

class NotificationPreferencesState {
  const NotificationPreferencesState({
    this.messages = true,
    this.email = true,
    this.marketing = false,
    this.loading = false,
    this.saving = false,
    this.error,
  });

  final bool messages;
  final bool email;
  final bool marketing;
  final bool loading;
  final bool saving;
  final String? error;

  NotificationPreferencesState copyWith({
    bool? messages,
    bool? email,
    bool? marketing,
    bool? loading,
    bool? saving,
    String? error,
    bool clearError = false,
  }) {
    return NotificationPreferencesState(
      messages: messages ?? this.messages,
      email: email ?? this.email,
      marketing: marketing ?? this.marketing,
      loading: loading ?? this.loading,
      saving: saving ?? this.saving,
      error: clearError ? null : (error ?? this.error),
    );
  }
}

final notificationPreferencesControllerProvider =
    StateNotifierProvider<NotificationPreferencesController, NotificationPreferencesState>((ref) {
  final controller = NotificationPreferencesController(ref);
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

class NotificationPreferencesController extends StateNotifier<NotificationPreferencesState> {
  NotificationPreferencesController(this._ref) : super(const NotificationPreferencesState()) {
    reload();
  }

  final Ref _ref;

  Future<void> reload() async {
    final workspaceId = _ref.read(authControllerProvider).workspace?.id;
    if (workspaceId == null) {
      state = const NotificationPreferencesState();
      return;
    }

    final cached = await _ref.read(notificationRepositoryProvider).cachedPreferences();
    if (cached.isNotEmpty) {
      state = NotificationPreferencesState(
        messages: cached['messages'] != false,
        email: cached['email'] != false,
        marketing: cached['marketing'] == true,
      );
    } else {
      state = state.copyWith(loading: true, clearError: true);
    }
    try {
      final prefs = await _ref.read(notificationRepositoryProvider).preferences();
      state = NotificationPreferencesState(
        messages: prefs['messages'] != false,
        email: prefs['email'] != false,
        marketing: prefs['marketing'] == true,
      );
    } on ApiException catch (e) {
      state = state.copyWith(loading: false, error: e.userMessage);
    } catch (_) {
      state = state.copyWith(loading: false, error: 'تعذر تحميل التفضيلات.');
    }
  }

  void setMessages(bool value) => state = state.copyWith(messages: value);
  void setEmail(bool value) => state = state.copyWith(email: value);
  void setMarketing(bool value) => state = state.copyWith(marketing: value);

  Future<bool> save() async {
    state = state.copyWith(saving: true, clearError: true);
    try {
      await _ref.read(notificationRepositoryProvider).updatePreferences({
        'messages': state.messages,
        'email': state.email,
        'marketing': state.marketing,
      });
      state = state.copyWith(saving: false);
      return true;
    } on ApiException catch (e) {
      state = state.copyWith(saving: false, error: e.userMessage);
      return false;
    } catch (_) {
      state = state.copyWith(saving: false, error: 'تعذر الحفظ.');
      return false;
    }
  }
}
