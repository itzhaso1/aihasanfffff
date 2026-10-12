import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// ألوان جاهزة لفقاعات الرسائل المرسلة (outbound).
class ChatBubbleColorOption {
  const ChatBubbleColorOption({
    required this.id,
    required this.label,
    this.light,
    this.dark,
  });

  final String id;
  final String label;

  /// `null` = لون النظام الافتراضي ([HasimTokens.outgoingBubble]).
  final Color? light;
  final Color? dark;

  Color resolve(BuildContext context) {
    if (light == null || dark == null) {
      return HasimTokens.outgoingBubble(context);
    }
    return HasimTokens.isDark(context) ? dark! : light!;
  }

  /// لون المعاينة في شاشة الإعدادات (دائرة).
  Color previewSwatch(BuildContext context) {
    if (light == null || dark == null) {
      return HasimTokens.outgoingBubble(context);
    }
    return HasimTokens.isDark(context) ? dark! : light!;
  }
}

class ChatBubbleColorCatalog {
  static const defaultId = 'default';

  static const options = <ChatBubbleColorOption>[
    ChatBubbleColorOption(id: defaultId, label: 'افتراضي'),
    ChatBubbleColorOption(
      id: 'whatsapp',
      label: 'أخضر',
      light: Color(0xFFD9FDD3),
      dark: Color(0xFF005C4B),
    ),
    ChatBubbleColorOption(
      id: 'mint',
      label: 'نعناع',
      light: Color(0xFFCCF2E8),
      dark: Color(0xFF0D6B5C),
    ),
    ChatBubbleColorOption(
      id: 'sky',
      label: 'سماوي',
      light: Color(0xFFD4F0FF),
      dark: Color(0xFF1A4D6B),
    ),
    ChatBubbleColorOption(
      id: 'lavender',
      label: 'بنفسجي',
      light: Color(0xFFE8DEFF),
      dark: Color(0xFF4A2D7A),
    ),
    ChatBubbleColorOption(
      id: 'rose',
      label: 'وردي',
      light: Color(0xFFFFE0EC),
      dark: Color(0xFF7A2D4A),
    ),
    ChatBubbleColorOption(
      id: 'sand',
      label: 'رملي',
      light: Color(0xFFF5E6D3),
      dark: Color(0xFF5C4A32),
    ),
    ChatBubbleColorOption(
      id: 'slate',
      label: 'رمادي',
      light: Color(0xFFE2E8F0),
      dark: Color(0xFF334155),
    ),
  ];

  static ChatBubbleColorOption? find(String? id) {
    final resolved = normalize(id);
    for (final option in options) {
      if (option.id == resolved) return option;
    }
    return null;
  }

  static String normalize(String? id) {
    if (id == null || id.isEmpty) return defaultId;
    for (final option in options) {
      if (option.id == id) return id;
    }
    return defaultId;
  }

  static Color outgoingBubble(BuildContext context, String? presetId) {
    return find(presetId)?.resolve(context) ?? HasimTokens.outgoingBubble(context);
  }
}
