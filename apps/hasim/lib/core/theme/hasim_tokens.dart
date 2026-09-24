import 'package:flutter/material.dart';

/// رموز تصميم حاسم — مصدر واحد للألوان والمسافات والظلال.
abstract final class HasimTokens {
  static const Color accent = Color(0xFF00BFA6);
  static const Color accentDark = Color(0xFF067E6B);
  static const Color ink = Color(0xFF172024);
  static const Color muted = Color(0xFF7A8B87);
  static const Color mutedSoft = Color(0xFF6F7B7B);
  static const Color mintBg = Color(0xFFF3FCFA);
  static const Color mintSoft = Color(0xFFE6F7F2);
  static const Color mintLight = Color(0xFFDDF7F3);
  static const Color mintChip = Color(0xFFD8F3EC);
  static const Color line = Color(0xFFE7F0ED);
  static const Color fieldBorder = Color(0xFFD5EBE7);
  static const Color darkPage = Color(0xFF0B1412);
  static const Color darkCard = Color(0xFF15201D);
  static const Color darkLine = Color(0xFF1F2E2A);

  /// سطح دردشة سطح المكتب — بيج فاتح قريب من واتساب ويب.
  static const Color chatCanvasLight = Color(0xFFF3EEE6);
  static const Color chatCanvasDark = Color(0xFF0B141A);
  static const Color outgoingBubbleLight = Color(0xFFD9FDD3);
  static const Color outgoingBubbleDark = Color(0xFF005C4B);
  static const Color conversationSelectedLight = Color(0xFFE7F6F2);
  static const Color chatGreen = Color(0xFF00A884);
  static const Color readReceipt = Color(0xFF53BDEB);

  static const Color primary = accent;
  static const Color background = mintBg;
  static const Color surface = mintBg;
  static const Color card = Color(0xFFFFFFFF);
  static const Color text = ink;
  static const Color secondaryText = mutedSoft;
  static const Color border = fieldBorder;
  static const Color activeNavigation = accent;
  static const Color inactiveNavigation = muted;

  /// Semantic tokens (Design System)
  static const Color danger = Color(0xFFE5484D);
  static const Color dangerSoft = Color(0xFFFEF2F2);
  static const Color success = Color(0xFF12B76A);
  static const Color warning = Color(0xFFF59E0B);
  static const Color primaryLight = mintSoft;

  static const double radiusMd = 14;
  static const double radiusCard = 20;
  static const double radiusButton = 16;
  static const double radiusPill = 28;
  static const double radiusSearch = 22;
  static const double radiusNav = 28;

  static const double spaceXs = 6;
  static const double spaceSm = 10;
  static const double spaceMd = 16;
  static const double spaceLg = 24;
  static const double spaceXl = 32;
  static const double space2xl = 40;
  static const double pageBottom = 28;
  static const double settingsContentMaxWidth = 720;

  static bool isDark(BuildContext context) => Theme.of(context).brightness == Brightness.dark;

  static Color pageBg(BuildContext context) => isDark(context) ? darkPage : mintBg;

  static Color cardBg(BuildContext context) => isDark(context) ? darkCard : card;

  static Color inkColor(BuildContext context) => isDark(context) ? Colors.white : text;

  static Color mutedColor(BuildContext context) =>
      isDark(context) ? const Color(0xFF9AABA7) : secondaryText;

  static Color lineColor(BuildContext context) => isDark(context) ? darkLine : line;

  static Color mintFill(BuildContext context) =>
      isDark(context) ? accent.withValues(alpha: 0.18) : mintSoft;

  static Color chatCanvas(BuildContext context) => isDark(context) ? chatCanvasDark : chatCanvasLight;

  static Color outgoingBubble(BuildContext context) =>
      isDark(context) ? outgoingBubbleDark : outgoingBubbleLight;

  static Color conversationSelected(BuildContext context) =>
      isDark(context) ? accent.withValues(alpha: 0.16) : conversationSelectedLight;

  static List<BoxShadow> softShadow(BuildContext context) {
    if (isDark(context)) return const [];
    return const [
      BoxShadow(
        color: Color(0x0F0F2A24),
        blurRadius: 16,
        offset: Offset(0, 4),
      ),
    ];
  }

  static List<BoxShadow> navShadow(BuildContext context) {
    if (isDark(context)) return const [];
    return const [
      BoxShadow(
        color: Color(0x14000000),
        blurRadius: 18,
        offset: Offset(0, 6),
      ),
    ];
  }

  static BoxDecoration surfaceCardDecoration(BuildContext context, {double radius = radiusMd}) {
    return BoxDecoration(
      color: cardBg(context),
      borderRadius: BorderRadius.circular(radius),
      border: Border.all(color: lineColor(context)),
      boxShadow: softShadow(context),
    );
  }

  static List<BoxShadow> buttonShadow(BuildContext context) {
    if (isDark(context)) return const [];
    return [
      BoxShadow(
        color: accent.withValues(alpha: 0.28),
        blurRadius: 14,
        offset: const Offset(0, 6),
      ),
    ];
  }
}
