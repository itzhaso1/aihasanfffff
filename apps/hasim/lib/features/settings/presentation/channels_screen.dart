import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_buttons.dart';
import 'package:hasim/core/widgets/hasim_card.dart';
import 'package:hasim/core/widgets/hasim_channel_mark.dart';
import 'package:hasim/core/widgets/hasim_form_field.dart';
import 'package:hasim/core/widgets/hasim_settings_body.dart';
import 'package:hasim/core/widgets/mobile/hasim_mobile_scaffold.dart';
import 'package:hasim/core/widgets/skeleton_list.dart';
import 'package:hasim/features/settings/data/channel_connection.dart';
import 'package:hasim/features/settings/providers/channels_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:url_launcher/url_launcher.dart';

class ChannelsScreen extends ConsumerStatefulWidget {
  const ChannelsScreen({super.key, this.embedded = false});

  final bool embedded;

  @override
  ConsumerState<ChannelsScreen> createState() => _ChannelsScreenState();
}

class _ChannelsScreenState extends ConsumerState<ChannelsScreen>
    with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaitedResume();
    }
  }

  void unawaitedResume() {
    ref.read(channelsControllerProvider.notifier).handleResume();
  }

  Future<void> _openInbox(ChannelModel channel) async {
    final key = channel.key.trim();
    if (key.isEmpty) {
      return;
    }
    context.go('/conversations?channel=${Uri.encodeQueryComponent(key)}');
  }

  Future<void> _connect(ChannelModel channel) async {
    if (channel.connectionMode == 'bot_token') {
      final token = await _askTelegramToken();
      if (token == null || token.isEmpty || !mounted) {
        return;
      }
      await ref
          .read(channelsControllerProvider.notifier)
          .connect(channel.key, botToken: token);
      return;
    }
    await ref.read(channelsControllerProvider.notifier).connect(channel.key);
  }

  Future<String?> _askTelegramToken() async {
    final controller = TextEditingController();
    try {
      return await showModalBottomSheet<String>(
        context: context,
        isScrollControlled: true,
        builder: (context) {
          return SafeArea(
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                16,
                12,
                16,
                24 + MediaQuery.of(context).viewInsets.bottom,
              ),
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Center(
                      child: Container(
                        width: 40,
                        height: 4,
                        margin: const EdgeInsets.only(bottom: 14),
                        decoration: BoxDecoration(
                          color: HasimTokens.lineColor(context),
                          borderRadius: BorderRadius.circular(2),
                        ),
                      ),
                    ),
                    const HasimSettingsSectionHeader(
                      icon: Icons.send_rounded,
                      title: 'ربط Telegram',
                      subtitle:
                          'أدخل رمز البوت من BotFather. لا يُحفظ الرمز على الجهاز بعد الإرسال.',
                    ),
                    const SizedBox(height: 16),
                    HasimFormField(
                      controller: controller,
                      label: 'Bot token',
                      icon: Icons.vpn_key_outlined,
                      obscureText: true,
                      textDirection: TextDirection.ltr,
                      textAlign: TextAlign.left,
                    ),
                    const SizedBox(height: 16),
                    FilledButton(
                      onPressed: () =>
                          Navigator.pop(context, controller.text.trim()),
                      child: const Text('ربط'),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      );
    } finally {
      controller.dispose();
    }
  }

  Future<void> _openManageFallback(ChannelModel channel) async {
    final l10n = AppLocalizations.of(context);
    final url = channel.manageUrl;
    if (url == null || url.isEmpty) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.channelNoManageUrl)));
      return;
    }
    final uri = Uri.tryParse(url);
    if (uri == null) {
      return;
    }
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && mounted) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(l10n.channelOpenFailed)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final state = ref.watch(channelsControllerProvider);
    final compact = HasimLayout.isCompact(context);

    ref.listen<String?>(channelsControllerProvider.select((s) => s.notice), (
      previous,
      next,
    ) {
      if (next == null || next.isEmpty) {
        return;
      }
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(next)));
      ref.read(channelsControllerProvider.notifier).clearNotice();
    });

    ref.listen<List<FacebookPageChoice>>(
      channelsControllerProvider.select((s) => s.pageChoices),
      (previous, next) {
        if (next.isEmpty) {
          return;
        }
        showModalBottomSheet<void>(
          context: context,
          builder: (context) {
            return ListView(
              shrinkWrap: true,
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
              children: [
                const Text(
                  'اختر صفحة Facebook',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
                ),
                const SizedBox(height: 8),
                for (final page in next)
                  ListTile(
                    title: Text(page.name),
                    onTap: () {
                      Navigator.pop(context);
                      ref
                          .read(channelsControllerProvider.notifier)
                          .selectFacebookPage(page.id);
                    },
                  ),
              ],
            );
          },
        );
      },
    );

    final connectedCount = state.items
        .where((c) => c.connected || c.status == 'connected')
        .length;
    final mobile = compact && !widget.embedded;

    final listBody = state.loading
        ? const SkeletonList()
        : state.error != null
        ? Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(state.error!),
                TextButton(
                  onPressed: () =>
                      ref.read(channelsControllerProvider.notifier).reload(),
                  child: Text(l10n.retry),
                ),
              ],
            ),
          )
        : RefreshIndicator(
            onRefresh: () =>
                ref.read(channelsControllerProvider.notifier).reload(),
            child: ColoredBox(
              color: HasimTokens.settingsCanvasBg(context),
              child: Align(
                alignment: Alignment.topCenter,
                child: ConstrainedBox(
                  constraints: BoxConstraints(
                    maxWidth: mobile
                        ? double.infinity
                        : HasimTokens.settingsContentMaxWidth,
                  ),
                  child: ListView(
                    padding: EdgeInsets.fromLTRB(
                      mobile ? 16 : 24,
                      8,
                      mobile ? 16 : 24,
                      24,
                    ),
                    children: [
                      if (!widget.embedded)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Text(
                            'اربط قنوات التواصل لإدارة رسائلك من مكان واحد',
                            style: Theme.of(context).textTheme.bodyMedium
                                ?.copyWith(
                                  color: HasimTokens.mutedColor(context),
                                ),
                          ),
                        ),
                      HasimCard(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 14,
                          vertical: 10,
                        ),
                        margin: const EdgeInsets.only(bottom: 10),
                        child: Row(
                          children: [
                            const Icon(
                              Icons.hub_outlined,
                              size: 18,
                              color: HasimTokens.primary,
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                state.items.isEmpty
                                    ? 'لا توجد قنوات متاحة حالياً'
                                    : 'حالة الربط · $connectedCount من ${state.items.length} متصلة',
                                style: Theme.of(context).textTheme.bodyMedium
                                    ?.copyWith(
                                      fontWeight: FontWeight.w700,
                                      color: HasimTokens.inkColor(context),
                                    ),
                              ),
                            ),
                            HasimBadge(
                              label: '$connectedCount/${state.items.length}',
                            ),
                          ],
                        ),
                      ),
                      for (final channel in state.items) ...[
                        _ChannelCard(
                          channel: channel,
                          busy:
                              state.busyChannel == channel.key ||
                              state.pendingChannel == channel.key,
                          onOpenInbox: () => _openInbox(channel),
                          onConnect: () => _connect(channel),
                          onDisconnect: () => ref
                              .read(channelsControllerProvider.notifier)
                              .disconnect(channel.key),
                          onWebFallback: () => _openManageFallback(channel),
                        ),
                        const SizedBox(height: 8),
                      ],
                      const _WhyConnectCard(),
                    ],
                  ),
                ),
              ),
            ),
          );

    if (widget.embedded) {
      return listBody;
    }

    if (compact) {
      return HasimMobileScaffold(title: l10n.channels, body: listBody);
    }

    return Scaffold(
      backgroundColor: HasimTokens.settingsCanvasBg(context),
      appBar: AppBar(
        title: Text(l10n.channels),
        backgroundColor: HasimTokens.settingsCanvasBg(context),
        surfaceTintColor: Colors.transparent,
      ),
      body: listBody,
    );
  }
}

class _ChannelCard extends StatelessWidget {
  const _ChannelCard({
    required this.channel,
    required this.busy,
    required this.onOpenInbox,
    required this.onConnect,
    required this.onDisconnect,
    required this.onWebFallback,
  });

  final ChannelModel channel;
  final bool busy;
  final VoidCallback onOpenInbox;
  final VoidCallback onConnect;
  final VoidCallback onDisconnect;
  final VoidCallback onWebFallback;

  bool get _connected => channel.connected || channel.status == 'connected';

  String get _description {
    if (_connected) {
      final name = channel.displayName?.trim();
      final via = (name != null && name.isNotEmpty)
          ? 'متصل عبر $name'
          : (channel.hint ?? 'متصل');
      return '$via\nيمكنك الآن استقبال الرسائل وإدارتها.';
    }
    return channel.hint?.trim().isNotEmpty == true
        ? channel.hint!.trim()
        : 'القناة غير مربوطة بعد.';
  }

  @override
  Widget build(BuildContext context) {
    return HasimCard(
      padding: const EdgeInsets.fromLTRB(14, 10, 14, 2),
      radius: 14,
      borderColor: _connected
          ? HasimTokens.primary.withValues(alpha: 0.35)
          : HasimTokens.lineColor(context),
      child: Column(
        children: [
          InkWell(
            borderRadius: BorderRadius.circular(12),
            onTap: _connected
                ? onOpenInbox
                : (channel.canConnectInApp ? onConnect : null),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                HasimChannelMark(channel: channel.key, size: 40),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        channel.name,
                        style: Theme.of(context).textTheme.titleSmall
                            ?.copyWith(
                              fontSize: 15,
                              fontWeight: FontWeight.w800,
                              color: HasimTokens.inkColor(context),
                            ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        _description,
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                          color: HasimTokens.mutedColor(context),
                          height: 1.35,
                          fontSize: 12.5,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                if (busy)
                  const SizedBox(
                    width: 22,
                    height: 22,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                else
                  _StatusPill(
                    connected: _connected,
                    label: _connected ? 'متصل' : 'غير متصل',
                  ),
              ],
            ),
          ),
          const SizedBox(height: 8),
          Divider(height: 1, color: HasimTokens.lineColor(context)),
          SizedBox(
            height: 38,
            child: Row(
              children: [
                if (channel.canConnectInApp)
                  TextButton(
                    key: ValueKey('channel-connect-${channel.key}'),
                    onPressed: busy ? null : onConnect,
                    child: Text(_connected ? 'إعادة ربط' : 'ربط'),
                  ),
                if (channel.canDisconnect)
                  TextButton(
                    key: ValueKey('channel-disconnect-${channel.key}'),
                    style: TextButton.styleFrom(
                      foregroundColor: HasimTokens.danger,
                    ),
                    onPressed: busy ? null : onDisconnect,
                    child: const Text('قطع الاتصال'),
                  ),
                if (!channel.canConnectInApp && channel.manageUrl != null)
                  TextButton(
                    onPressed: onWebFallback,
                    child: const Text('إدارة عبر Web'),
                  ),
                if (_connected) ...[
                  const Spacer(),
                  TextButton.icon(
                    onPressed: onOpenInbox,
                    icon: const Icon(Icons.forum_outlined, size: 16),
                    label: const Text('المحادثات'),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _StatusPill extends StatelessWidget {
  const _StatusPill({required this.connected, required this.label});

  final bool connected;
  final String label;

  @override
  Widget build(BuildContext context) {
    final color = connected
        ? HasimTokens.success
        : HasimTokens.mutedColor(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 7,
            height: 7,
            decoration: BoxDecoration(color: color, shape: BoxShape.circle),
          ),
          const SizedBox(width: 6),
          Text(
            label,
            style: TextStyle(
              color: color,
              fontWeight: FontWeight.w700,
              fontSize: 12,
            ),
          ),
        ],
      ),
    );
  }
}

class _WhyConnectCard extends StatelessWidget {
  const _WhyConnectCard();

  @override
  Widget build(BuildContext context) {
    return HasimCard(
      backgroundColor: HasimTokens.mintFill(context),
      borderColor: HasimTokens.primary.withValues(alpha: 0.25),
      showShadow: false,
      child: const HasimSettingsSectionHeader(
        icon: Icons.forum_outlined,
        title: 'لماذا تربط قنواتك؟',
        subtitle: 'استقبل الرسائل وأدر محادثات عملائك من لوحة واحدة.',
      ),
    );
  }
}
