import 'package:flutter/material.dart';

/// رموز تصميم حاسم — مصدر واحد للألوان والمسافات والظلال.
abstract final class HasimTokens {
  static const Color accent = Color(0xFF00BFAE);
  static const Color accentDark = Color(0xFF067E6B);
  static const Color ink = Color(0xFF17212B);
  static const Color muted = Color(0xFF7A858A);
  static const Color mutedSoft = Color(0xFF7A858A);
  static const Color mintBg = Color(0xFFFFFFFF);
  static const Color mintSoft = Color(0xFFE8F8F5);
  static const Color mintLight = Color(0xFFE8F8F5);
  static const Color mintChip = Color(0xFFE8F8F5);
  static const Color line = Color(0xFFE5EAEA);
  static const Color fieldBorder = Color(0xFFE5EAEA);
  static const Color darkPage = Color(0xFF000000);
  static const Color darkCard = Color(0xFF111111);
  static const Color darkLine = Color(0xFF292929);

  /// سطح دردشة سطح المكتب — بيج فاتح قريب من واتساب ويب.
  static const Color chatCanvasLight = Color(0xFFF3EEE6);
  static const Color chatCanvasDark = Color(0xFF000000);
  static const Color outgoingBubbleLight = Color(0xFFD9FDD3);
  static const Color outgoingBubbleDark = Color(0xFF005C4B);
  static const Color conversationSelectedLight = Color(0xFFE8F8F5);
  static const Color chatGreen = Color(0xFF00A884);
  static const Color readReceipt = Color(0xFF53BDEB);

  static const Color primary = accent;
  static const Color background = Color(0xFFFFFFFF);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color card = Color(0xFFFFFFFF);
  static const Color text = ink;
  static const Color secondaryText = mutedSoft;
  static const Color border = fieldBorder;
  static const Color activeNavigation = accent;
  static const Color inactiveNavigation = muted;

  /// Semantic tokens (Design System)
  static const Color danger = Color(0xFFEF4444);
  static const Color dangerSoft = Color(0xFFFFF1F1);
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

  static bool isDark(BuildContext context) =>
      Theme.of(context).brightness == Brightness.dark;

  static Color pageBg(BuildContext context) =>
      isDark(context) ? darkPage : background;

  static Color dangerSoftColor(BuildContext context) =>
      isDark(context) ? const Color(0xFF3A1C1C) : dangerSoft;

  static Color cardBg(BuildContext context) =>
      isDark(context) ? darkCard : card;

  static Color inkColor(BuildContext context) =>
      isDark(context) ? Colors.white : text;

  static Color mutedColor(BuildContext context) =>
      isDark(context) ? const Color(0xFF9E9E9E) : secondaryText;

  static Color lineColor(BuildContext context) =>
      isDark(context) ? darkLine : line;

  static Color mintFill(BuildContext context) =>
      isDark(context) ? const Color(0xFF1D1D1D) : mintSoft;

  static Color chatCanvas(BuildContext context) =>
      isDark(context) ? chatCanvasDark : chatCanvasLight;

  static Color outgoingBubble(BuildContext context) =>
      isDark(context) ? outgoingBubbleDark : outgoingBubbleLight;

  static Color conversationSelected(BuildContext context) =>
      isDark(context) ? const Color(0xFF1D1D1D) : conversationSelectedLight;

  static List<BoxShadow> softShadow(BuildContext context) {
    if (isDark(context)) return const [];
    return const [
      BoxShadow(color: Color(0x08000000), blurRadius: 10, offset: Offset(0, 2)),
    ];
  }

  static List<BoxShadow> navShadow(BuildContext context) {
    if (isDark(context)) return const [];
    return const [
      BoxShadow(color: Color(0x14000000), blurRadius: 18, offset: Offset(0, 6)),
    ];
  }

  static BoxDecoration surfaceCardDecoration(
    BuildContext context, {
    double radius = radiusMd,
  }) {
    return BoxDecoration(
      color: Colors.transparent,
      borderRadius: BorderRadius.circular(radius),
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
