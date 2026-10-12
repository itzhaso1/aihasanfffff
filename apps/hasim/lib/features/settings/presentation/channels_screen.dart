import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/models/models.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_channel_mark.dart';
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

class _ChannelsScreenState extends ConsumerState<ChannelsScreen> with WidgetsBindingObserver {
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
      await ref.read(channelsControllerProvider.notifier).connect(channel.key, botToken: token);
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
          return Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Text('ربط Telegram', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  const SizedBox(height: 8),
                  const Text(
                    'أدخل رمز البوت من BotFather. لا يُحفظ الرمز على الجهاز بعد الإرسال.',
                    style: TextStyle(color: HasimTokens.muted, height: 1.4),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: controller,
                    obscureText: true,
                    autofocus: false,
                    decoration: const InputDecoration(
                      labelText: 'Bot token',
                      hintText: '123456:ABC-DEF...',
                    ),
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: () => Navigator.pop(context, controller.text.trim()),
                    child: const Text('ربط'),
                  ),
                ],
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
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(l10n.channelNoManageUrl)));
      return;
    }
    final uri = Uri.tryParse(url);
    if (uri == null) {
      return;
    }
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(l10n.channelOpenFailed)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = AppLocalizations.of(context);
    final state = ref.watch(channelsControllerProvider);
    final compact = HasimLayout.isCompact(context);

    ref.listen<String?>(channelsControllerProvider.select((s) => s.notice), (previous, next) {
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
                const Text('اختر صفحة Facebook', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                const SizedBox(height: 8),
                for (final page in next)
                  ListTile(
                    title: Text(page.name),
                    onTap: () {
                      Navigator.pop(context);
                      ref.read(channelsControllerProvider.notifier).selectFacebookPage(page.id);
                    },
                  ),
              ],
            );
          },
        );
      },
    );

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
                child: ListView(
                  padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                  children: [
                    if (!widget.embedded)
                      const Padding(
                        padding: EdgeInsets.only(bottom: 14),
                        child: Text(
                          'اربط قنوات التواصل لإدارة رسائلك من مكان واحد',
                          style: TextStyle(
                            color: HasimTokens.muted,
                            fontWeight: FontWeight.w600,
                            height: 1.4,
                          ),
                        ),
                      ),
                    for (final channel in state.items) ...[
                      _ChannelCard(
                        channel: channel,
                        busy: state.busyChannel == channel.key || state.pendingChannel == channel.key,
                        onOpenInbox: () => _openInbox(channel),
                        onConnect: () => _connect(channel),
                        onDisconnect: () => ref.read(channelsControllerProvider.notifier).disconnect(channel.key),
                        onWebFallback: () => _openManageFallback(channel),
                      ),
                      const SizedBox(height: 12),
                    ],
                    const _WhyConnectCard(),
                  ],
                ),
              );

    if (widget.embedded) {
      return listBody;
    }

    if (compact) {
      return HasimMobileScaffold(title: l10n.channels, body: listBody);
    }

    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      appBar: AppBar(
        title: Text(l10n.channels),
        backgroundColor: HasimTokens.pageBg(context),
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
      final via = (name != null && name.isNotEmpty) ? 'متصل عبر $name' : (channel.hint ?? 'متصل');
      return '$via\nيمكنك الآن استقبال الرسائل وإدارتها.';
    }
    return channel.hint?.trim().isNotEmpty == true ? channel.hint!.trim() : 'القناة غير مربوطة بعد.';
  }

  @override
  Widget build(BuildContext context) {
    return Material(
      color: HasimTokens.cardBg(context),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(18),
        side: BorderSide(color: HasimTokens.lineColor(context)),
      ),
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 14, 14, 8),
        child: Column(
          children: [
            InkWell(
              onTap: _connected ? onOpenInbox : (channel.canConnectInApp ? onConnect : null),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  HasimChannelMark(channel: channel.key, size: 44),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          channel.name,
                          style: TextStyle(
                            fontWeight: FontWeight.w800,
                            fontSize: 16,
                            color: HasimTokens.inkColor(context),
                          ),
                        ),
                        const SizedBox(height: 4),
                        Text(
                          _description,
                          style: const TextStyle(
                            color: HasimTokens.muted,
                            height: 1.45,
                            fontSize: 13,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 8),
                  if (busy)
                    const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
                  else
                    _StatusPill(connected: _connected, label: _connected ? 'متصل' : 'غير متصل'),
                ],
              ),
            ),
            const SizedBox(height: 10),
            Divider(height: 1, color: HasimTokens.lineColor(context)),
            SizedBox(
              height: 46,
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
                    TextButton(
                      onPressed: onOpenInbox,
                      child: const Text('المحادثات'),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
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
    final color = connected ? const Color(0xFF16A34A) : const Color(0xFF94A3B8);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(20),
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
            style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12),
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
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.mintFill(context),
        borderRadius: BorderRadius.circular(18),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(
          children: [
            Icon(Icons.forum_outlined, color: HasimTokens.mutedColor(context)),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'لماذا تربط قنواتك؟',
                    style: TextStyle(
                      fontWeight: FontWeight.w800,
                      fontSize: 15,
                      color: HasimTokens.inkColor(context),
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'استقبل الرسائل وأدر محادثات عملائك من لوحة واحدة.',
                    style: TextStyle(color: HasimTokens.mutedColor(context), height: 1.4),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
