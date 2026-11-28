import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/features/settings/providers/notification_preferences_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';

class NotificationPreferencesScreen extends ConsumerWidget {
  const NotificationPreferencesScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final compact = HasimLayout.isCompact(context);
    final state = ref.watch(notificationPreferencesControllerProvider);
    final notifier = ref.read(
      notificationPreferencesControllerProvider.notifier,
    );

    Widget body;
    if (state.loading) {
      body = const Center(child: CircularProgressIndicator(strokeWidth: 2));
    } else if (state.error != null) {
      body = Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(state.error!),
            TextButton(onPressed: notifier.reload, child: Text(l10n.retry)),
          ],
        ),
      );
    } else {
      body = HasimSettingsBody(
        mobileLayout: compact && !embedded,
        children: [
          HasimCard(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const HasimSettingsSectionHeader(
                  icon: Icons.notifications_outlined,
                  title: 'قنوات التنبيه',
                  subtitle: 'اختر أنواع الإشعارات التي ترغب في استلامها',
                ),
                const SizedBox(height: 12),
                Divider(height: 1, color: HasimTokens.lineColor(context)),
                _PrefSwitch(
                  icon: Icons.chat_bubble_outline_rounded,
                  title: l10n.notificationPrefMessages,
                  subtitle: 'إشعارات الرسائل الجديدة وردود العملاء الفورية',
                  value: state.messages,
                  onChanged: notifier.setMessages,
                ),
                Divider(height: 1, color: HasimTokens.lineColor(context)),
                _PrefSwitch(
                  icon: Icons.mail_outline_rounded,
                  title: l10n.notificationPrefEmail,
                  subtitle: 'تنبيهات رسائل البريد الإلكتروني الواردة',
                  value: state.email,
                  onChanged: notifier.setEmail,
                ),
                Divider(height: 1, color: HasimTokens.lineColor(context)),
                _PrefSwitch(
                  icon: Icons.campaign_outlined,
                  title: l10n.notificationPrefMarketing,
                  subtitle: 'تحديثات المنصة والميزات الجديدة والعروض',
                  value: state.marketing,
                  onChanged: notifier.setMarketing,
                ),
              ],
            ),
          ),
          const SizedBox(height: HasimTokens.spaceMd),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: HasimPrimaryButton(
              label: state.saving ? l10n.loading : l10n.save,
              loading: state.saving,
              onPressed: state.saving
                  ? null
                  : () async {
                      final ok = await notifier.save();
                      if (context.mounted && ok) {
                        ScaffoldMessenger.of(context).showSnackBar(
                          SnackBar(content: Text(l10n.settingsSaved)),
                        );
                      }
                    },
            ),
          ),
        ],
      );
    }

    if (embedded) return body;

    if (compact) {
      return HasimMobileScaffold(
        title: l10n.notificationPreferencesTitle,
        body: body,
      );
    }

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(l10n.notificationPreferencesTitle),
        backgroundColor: HasimTokens.pageBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: body,
    );
  }
}

class _PrefSwitch extends StatelessWidget {
  const _PrefSwitch({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.value,
    required this.onChanged,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 10),
      child: Row(
        children: [
          Container(
            width: 36,
            height: 36,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: HasimTokens.primary.withValues(alpha: dark ? 0.16 : 0.09),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, size: 18, color: HasimTokens.primary),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: HasimTokens.inkColor(context),
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  subtitle,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: HasimTokens.mutedColor(context),
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          Switch.adaptive(
            value: value,
            onChanged: onChanged,
            activeTrackColor: AppTheme.brand.withValues(alpha: 0.45),
            activeThumbColor: AppTheme.brand,
          ),
        ],
      ),
    );
  }
}
