import 'package:flutter/material.dart';

/// أيقونة دائرية حسب قناة المحادثة (واتساب، ماسنجر، …).
class ConversationChannelAvatar extends StatelessWidget {
  const ConversationChannelAvatar({
    super.key,
    required this.channel,
    this.radius = 18,
  });

  final String channel;
  final double radius;

  static ({Color color, IconData icon}) visualFor(String channel) {
    return switch (channel) {
      'whatsapp' => (color: const Color(0xFF25D366), icon: Icons.chat_rounded),
      'facebook_messenger' => (color: const Color(0xFF0084FF), icon: Icons.messenger_rounded),
      'instagram' => (color: const Color(0xFFE4405F), icon: Icons.camera_alt_rounded),
      'telegram' => (color: const Color(0xFF2AABEE), icon: Icons.send_rounded),
      'tiktok' => (color: const Color(0xFF111827), icon: Icons.music_note_rounded),
      'email' => (color: const Color(0xFFD97706), icon: Icons.mail_outline_rounded),
      'web' => (color: const Color(0xFF06C2A4), icon: Icons.language_rounded),
      'manual' => (color: const Color(0xFF64748B), icon: Icons.forum_outlined),
      _ => (color: const Color(0xFF64748B), icon: Icons.hub_outlined),
    };
  }

  @override
  Widget build(BuildContext context) {
    final visual = visualFor(channel);
    return CircleAvatar(
      radius: radius,
      backgroundColor: visual.color.withValues(alpha: 0.14),
      child: Icon(
        visual.icon,
        color: visual.color,
        size: (radius * 1.15).clamp(18, 24),
      ),
    );
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
    final visual = ConversationChannelAvatar.visualFor(channel);
    final icon = Icon(visual.icon, size: size, color: visual.color);
    if (tooltip == null || tooltip!.isEmpty) return icon;
    return Tooltip(message: tooltip!, child: icon);
  }
}
