import 'package:flutter/material.dart';
import 'package:hasim/core/widgets/hasim_channel_mark.dart';

/// أيقونة دائرية حسب قناة المحادثة (واتساب، ماسنجر، …).
class ConversationChannelAvatar extends StatelessWidget {
  const ConversationChannelAvatar({
    super.key,
    required this.channel,
    this.radius = 18,
  });

  final String channel;
  final double radius;

  static Color colorFor(String channel) => HasimChannelMark.colorFor(channel);

  static ({Color color, IconData icon}) visualFor(String channel) {
    return (color: colorFor(channel), icon: Icons.circle);
  }

  @override
  Widget build(BuildContext context) {
    return HasimChannelMark(channel: channel, size: radius * 2);
  }
}

/// أيقونة صغيرة بجانب اسم المحادثة — توضّح المنصة (واتساب، ماسنجر، …).
class ConversationChannelIcon extends StatelessWidget {
  const ConversationChannelIcon({
    super.key,
    required this.channel,
    this.size = 17,
    this.tooltip,
  });

  final String channel;
  final double size;
  final String? tooltip;

  @override
  Widget build(BuildContext context) {
    final mark = HasimChannelMark(channel: channel, size: size);
    if (tooltip == null || tooltip!.isEmpty) return mark;
    return Tooltip(message: tooltip!, child: mark);
  }
}
