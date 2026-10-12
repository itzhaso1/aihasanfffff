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
    final notifier = ref.read(notificationPreferencesControllerProvider.notifier);

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
              children: [
                _PrefSwitch(
                  title: l10n.notificationPrefMessages,
                  value: state.messages,
                  onChanged: notifier.setMessages,
                ),
                Divider(height: 1, color: HasimTokens.lineColor(context)),
                _PrefSwitch(
                  title: l10n.notificationPrefEmail,
                  value: state.email,
                  onChanged: notifier.setEmail,
                ),
                Divider(height: 1, color: HasimTokens.lineColor(context)),
                _PrefSwitch(
                  title: l10n.notificationPrefMarketing,
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
      return HasimMobileScaffold(title: l10n.notificationPreferencesTitle, body: body);
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
    required this.title,
    required this.value,
    required this.onChanged,
  });

  final String title;
  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          Expanded(
            child: Text(
              title,
              style: Theme.of(context).textTheme.bodyLarge?.copyWith(
                    fontWeight: FontWeight.w600,
                    color: HasimTokens.inkColor(context),
                  ),
            ),
          ),
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
