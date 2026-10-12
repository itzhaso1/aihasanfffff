import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/app_design.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/privacy/presentation/pin_prompt.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';

class PrivacyLockSettings extends ConsumerWidget {
  const PrivacyLockSettings({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final state = ref.watch(chatLockControllerProvider);
    final notifier = ref.read(chatLockControllerProvider.notifier);
    final showChatLockSettings = ref
        .watch(authControllerProvider)
        .canSeeSpecialInboxSections;
    final biometricHint = state.biometricAvailable
        ? 'حسب ما يدعمه الجهاز: بصمة أو Face ID'
        : 'البصمة أو Face ID غير متاحة على هذا الجهاز';

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 28),
      children: [
        if (showChatLockSettings)
          Text(
            'الدردشات المقفلة لا تظهر في الوارد قبل إدخال الرمز أو البصمة.',
            style: AppTextStyles.secondary(
              color: HasimTokens.mutedColor(context),
            ),
          ),
        const SizedBox(height: 18),
        AppSettingsTile(
          icon: Icons.lock_outline,
          title: 'قفل التطبيق',
          subtitle: 'يطلب رمز PIN عند فتح حاسم',
          trailing: AppSwitch(
            key: const Key('privacy-app-lock'),
            value: state.appLockEnabled,
            onChanged: (value) => _setAppLock(context, ref, value),
          ),
        ),
        AppSettingsTile(
          icon: Icons.fingerprint_rounded,
          title: 'فتح التطبيق بالبصمة',
          subtitle: biometricHint,
          trailing: AppSwitch(
            key: const Key('privacy-app-biometric'),
            value: state.appBiometricEnabled,
            onChanged: !state.appLockEnabled || !state.biometricAvailable
                ? null
                : (value) =>
                      _setFlag(context, () => notifier.setAppBiometric(value)),
          ),
        ),
        if (showChatLockSettings)
          AppSettingsTile(
            icon: Icons.pin_outlined,
            title: 'فتح الدردشات المقفلة بالبصمة',
            subtitle: biometricHint,
            trailing: AppSwitch(
              key: const Key('privacy-chats-biometric'),
              value: state.lockedChatsBiometricEnabled,
              onChanged: !state.pinSet || !state.biometricAvailable
                  ? null
                  : (value) => _setFlag(
                      context,
                      () => notifier.setLockedChatsBiometric(value),
                    ),
            ),
          ),
        const SizedBox(height: 8),
        AppSettingsTile(
          icon: Icons.password_outlined,
          title: state.pinSet ? 'تغيير رمز PIN' : 'إنشاء رمز PIN',
          subtitle: '4 أرقام',
          onTap: () => _editPin(context, ref),
          trailing: Icon(
            Icons.chevron_left_rounded,
            color: HasimTokens.mutedColor(context),
          ),
        ),
      ],
    );
  }

  Future<void> _setAppLock(
    BuildContext context,
    WidgetRef ref,
    bool enabled,
  ) async {
    final notifier = ref.read(chatLockControllerProvider.notifier);
    if (enabled && !ref.read(chatLockControllerProvider).pinSet) {
      final pin = await showCreatePinDialog(context);
      if (pin == null || !context.mounted) return;
      final error = await notifier.createPin(pin);
      if (!context.mounted) return;
      if (error != null) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(error)));
        return;
      }
    }
    if (!enabled) {
      final ok = await showVerifyPinDialog(
        context,
        title: 'إيقاف قفل التطبيق',
        subtitle: 'أدخل رمز PIN للتأكيد',
        allowBiometric: false,
        verify: notifier.verifyPin,
        biometric: notifier.authenticateBiometric,
      );
      if (!ok || !context.mounted) return;
    }
    await notifier.setAppLock(enabled);
  }

  Future<void> _setFlag(
    BuildContext context,
    Future<String?> Function() apply,
  ) async {
    final error = await apply();
    if (error == null || !context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error)));
  }

  Future<void> _editPin(BuildContext context, WidgetRef ref) async {
    final notifier = ref.read(chatLockControllerProvider.notifier);
    if (!ref.read(chatLockControllerProvider).pinSet) {
      final pin = await showCreatePinDialog(context);
      if (pin == null || !context.mounted) return;
      final error = await notifier.createPin(pin);
      if (!context.mounted || error == null) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(error)));
      return;
    }
    final ok = await showChangePinDialog(
      context,
      verify: notifier.verifyPin,
      change: (current, next) =>
          notifier.changePin(current: current, next: next),
    );
    if (!ok || !context.mounted) return;
    ScaffoldMessenger.of(
      context,
    ).showSnackBar(const SnackBar(content: Text('تم تغيير رمز PIN')));
  }
}

class PrivacyLockScreen extends StatelessWidget {
  const PrivacyLockScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(title: const Text('القفل والخصوصية')),
      body: const PrivacyLockSettings(),
    );
  }
}
