import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/settings/providers/channels_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:url_launcher/url_launcher.dart';

class ChannelsScreen extends ConsumerWidget {
  const ChannelsScreen({super.key});

  Future<void> _openManage(BuildContext context, ChannelModel channel) async {
    final l10n = AppLocalizations.of(context);
    final url = channel.manageUrl;
    if (url == null || url.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(l10n.channelNoManageUrl)));
      return;
    }
    final uri = Uri.tryParse(url);
    if (uri == null) return;
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(l10n.channelOpenFailed)));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = AppLocalizations.of(context);
    final state = ref.watch(channelsControllerProvider);
    final compact = HasimLayout.isCompact(context);

    final listBody = state.loading
          ? const SkeletonList()
          : state.error != null
              ? Center(
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(state.error!),
                      TextButton(
                        onPressed: () => ref.read(channelsControllerProvider.notifier).reload(),
                        child: Text(l10n.retry),
                      ),
                    ],
                  ),
                )
              : RefreshIndicator(
                  onRefresh: () => ref.read(channelsControllerProvider.notifier).reload(),
                  child: ListView.separated(
                    padding: const EdgeInsets.all(16),
                    itemCount: state.items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, index) {
                      final c = state.items[index];
                      final connected = c.connected || c.status == 'connected';
                      final details = [
                        c.statusLabel ?? c.status,
                        if (c.displayName != null && c.displayName!.isNotEmpty) c.displayName!,
                        if (c.phoneNumber != null && c.phoneNumber!.isNotEmpty) c.phoneNumber!,
                        if (c.hint != null) c.hint!,
                      ].join(' · ');
                      return ListTile(
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(14),
                          side: BorderSide(color: Colors.grey.shade300),
                        ),
                        leading: CircleAvatar(
                          backgroundColor: connected
                              ? Theme.of(context).colorScheme.primary.withValues(alpha: 0.15)
                              : Colors.grey.shade200,
                          child: Icon(
                            connected ? Icons.link : Icons.link_off,
                            color: connected ? Theme.of(context).colorScheme.primary : Colors.grey,
                          ),
                        ),
                        title: Text(c.name),
                        subtitle: Text(details),
                        trailing: TextButton(
                          onPressed: () => _openManage(context, c),
                          child: Text(l10n.manageChannel),
                        ),
                      );
                    },
                  ),
                );

    if (compact) {
      return HasimMobileScaffold(title: l10n.channels, body: listBody);
    }

    return Scaffold(
      appBar: AppBar(title: Text(l10n.channels)),
      body: listBody,
    );
  }
}
