import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/app_design.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/privacy/presentation/pin_prompt.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';

class PrivacyLockSettings extends ConsumerStatefulWidget {
  const PrivacyLockSettings({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<PrivacyLockSettings> createState() =>
      _PrivacyLockSettingsState();
}

class _PrivacyLockSettingsState extends ConsumerState<PrivacyLockSettings> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref
          .read(chatLockControllerProvider.notifier)
          .refreshBiometricAvailability();
    });
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(chatLockControllerProvider);
    final notifier = ref.read(chatLockControllerProvider.notifier);
    final showChatLockSettings = ref
        .watch(authControllerProvider)
        .canSeeSpecialInboxSections;
    final biometricHint = state.biometricAvailable
        ? 'حسب ما يدعمه الجهاز: بصمة أو Face ID'
        : 'البصمة أو Face ID غير متاحة على هذا الجهاز';
    final compact = HasimLayout.isCompact(context);

    return HasimSettingsBody(
      mobileLayout: compact && !widget.embedded,
      children: [
        if (showChatLockSettings) ...[
          HasimCard(
            backgroundColor: HasimTokens.mintFill(context),
            borderColor: HasimTokens.primary.withValues(alpha: 0.25),
            showShadow: false,
            child: Row(
              children: [
                const Icon(
                  Icons.verified_user_outlined,
                  color: HasimTokens.primary,
                  size: 20,
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    'الدردشات المقفلة لا تظهر في الوارد قبل إدخال الرمز أو البصمة.',
                    style: AppTextStyles.secondary(
                      color: HasimTokens.inkColor(context),
                    ),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: HasimTokens.spaceMd),
        ],
        HasimCard(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const HasimSettingsSectionHeader(
                icon: Icons.lock_outline_rounded,
                title: 'حماية التطبيق والبصمة',
                subtitle: 'ضبط قفل الشاشة والمصادقة الحيوية',
              ),
              const SizedBox(height: 8),
              AppSettingsTile(
                icon: Icons.lock_outline,
                title: 'قفل التطبيق',
                subtitle: 'يطلب رمز PIN عند فتح حاسم',
                trailing: AppSwitch(
                  key: const Key('privacy-app-lock'),
                  value: state.appLockEnabled,
                  onChanged: (value) => _setAppLock(context, value),
                ),
              ),
              Divider(height: 1, color: HasimTokens.lineColor(context)),
              AppSettingsTile(
                icon: Icons.fingerprint_rounded,
                title: 'فتح التطبيق بالبصمة',
                subtitle: biometricHint,
                trailing: AppSwitch(
                  key: const Key('privacy-app-biometric'),
                  value: state.appBiometricEnabled,
                  onChanged: !state.appLockEnabled || !state.biometricAvailable
                      ? null
                      : (value) => _setFlag(
                          context,
                          () => notifier.setAppBiometric(value),
                        ),
                ),
              ),
              if (showChatLockSettings) ...[
                Divider(height: 1, color: HasimTokens.lineColor(context)),
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
              ],
            ],
          ),
        ),
        const SizedBox(height: HasimTokens.spaceMd),
        HasimCard(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
          child: AppSettingsTile(
            icon: Icons.password_outlined,
            title: state.pinSet ? 'تغيير رمز PIN' : 'إنشاء رمز PIN',
            subtitle: '4 أرقام',
            onTap: () => _editPin(context),
            trailing: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                HasimBadge(label: state.pinSet ? 'مفعّل' : 'غير مُعيّن'),
                const SizedBox(width: 6),
                Icon(
                  Icons.chevron_left_rounded,
                  color: HasimTokens.mutedColor(context),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Future<void> _setAppLock(BuildContext context, bool enabled) async {
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

  Future<void> _editPin(BuildContext context) async {
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
    if (HasimLayout.isCompact(context)) {
      return const HasimMobileScaffold(
        title: 'القفل والخصوصية',
        body: PrivacyLockSettings(),
      );
    }
    return Scaffold(
      backgroundColor: HasimTokens.settingsCanvasBg(context),
      appBar: AppBar(
        title: const Text('القفل والخصوصية'),
        backgroundColor: HasimTokens.settingsCanvasBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: const PrivacyLockSettings(),
    );
  }
}
