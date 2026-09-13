import 'package:flutter/material.dart';
import 'package:hasim_finance/core/config/app_config.dart';

/// Central Finance visual language. Screens must not invent local colors.
class FinanceTokens {
  FinanceTokens._();

  static const Color brand = Color(AppConfig.brandPrimary);
  static const Color brandDark = Color(AppConfig.brandDark);
  static const Color brandSoft = Color(0xFFE7F8F4);
  static const Color brandIconBg = Color(0xFFD8F4EE);
  static const Color brandLight = Color(0xFF56E3CC);

  static const Color canvas = Color(0xFFF3F6F8);
  static const Color canvasAlt = Color(0xFFF7FAFC);
  static const Color surface = Color(0xFFFFFFFF);
  static const Color sidebar = Color(0xFFFFFFFF);
  static const Color header = Color(0xFFFFFFFF);

  static const Color border = Color(0xFFE6EDF2);
  static const Color borderStrong = Color(0xFFD7E0E8);
  static const Color divider = Color(0xFFEEF3F6);

  static const Color text = Color(0xFF152033);
  static const Color textMuted = Color(0xFF6B7A8D);
  static const Color textFaint = Color(0xFF93A0B0);

  static const Color success = Color(0xFF0F9F7A);
  static const Color successSoft = Color(0xFFE6F7F1);
  static const Color warning = Color(0xFFD97706);
  static const Color warningSoft = Color(0xFFFEF3C7);
  static const Color danger = Color(0xFFDC2626);
  static const Color dangerSoft = Color(0xFFFEE2E2);
  static const Color info = Color(0xFF2563EB);
  static const Color infoSoft = Color(0xFFDBEAFE);
  static const Color pink = Color(0xFFDB2777);
  static const Color pinkSoft = Color(0xFFFCE7F3);
  static const Color orange = Color(0xFFEA580C);
  static const Color orangeSoft = Color(0xFFFFEDD5);
  static const Color indigo = Color(0xFF4F46E5);
  static const Color indigoSoft = Color(0xFFE0E7FF);
  static const Color amber = Color(0xFFF59E0B);
  static const Color amberSoft = Color(0xFFFEF3C7);

  static const double radiusSm = 8;
  static const double radius = 12;
  static const double radiusLg = 16;
  static const double radiusXl = 20;
  static const double radiusPill = 999;

  static const double space4 = 4;
  static const double space8 = 8;
  static const double space12 = 12;
  static const double space16 = 16;
  static const double space20 = 20;
  static const double space24 = 24;

  static const double sidebarWidth = 248;
  static const double sidebarWidthWide = 268;
  static const double headerHeight = 64;
  static const double controlHeight = 42;

  static FinancePalette of(BuildContext context) => context.hs;

  static List<BoxShadow> cardShadowFor(BuildContext context) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return [
      BoxShadow(
        color: Color(0xFF000000).withValues(alpha: dark ? 0.28 : 0.04),
        blurRadius: dark ? 14 : 18,
        offset: const Offset(0, 8),
      ),
    ];
  }

  static List<BoxShadow> get cardShadow => [
        BoxShadow(
          color: const Color(0xFF152033).withValues(alpha: 0.04),
          blurRadius: 18,
          offset: const Offset(0, 8),
        ),
      ];

  static List<BoxShadow> get softShadow => [
        BoxShadow(
          color: const Color(0xFF152033).withValues(alpha: 0.035),
          blurRadius: 12,
          offset: const Offset(0, 4),
        ),
      ];

  static BoxDecoration card(BuildContext context, {Color? color, double radius = radiusLg}) {
    final p = of(context);
    return BoxDecoration(
      color: color ?? p.card,
      borderRadius: BorderRadius.circular(radius),
      border: Border.all(color: p.border),
      boxShadow: cardShadowFor(context),
    );
  }

  static BoxDecoration sidebarItem({required bool selected, BuildContext? context}) {
    final soft = context == null ? brandSoft : of(context).brandSoft;
    return BoxDecoration(
      color: selected ? soft : Colors.transparent,
      borderRadius: BorderRadius.circular(10),
    );
  }
}

class FinancePalette extends ThemeExtension<FinancePalette> {
  const FinancePalette({
    required this.canvas,
    required this.canvasAlt,
    required this.surface,
    required this.card,
    required this.input,
    required this.sidebar,
    required this.header,
    required this.border,
    required this.borderStrong,
    required this.text,
    required this.textMuted,
    required this.textFaint,
    required this.placeholder,
    required this.brandSoft,
    required this.brandIconBg,
    required this.success,
    required this.successSoft,
    required this.warning,
    required this.warningSoft,
    required this.danger,
    required this.dangerSoft,
    required this.info,
    required this.infoSoft,
    required this.pink,
    required this.pinkSoft,
    required this.orange,
    required this.orangeSoft,
    required this.indigo,
    required this.indigoSoft,
    required this.brandAccent,
  });

  final Color canvas;
  final Color canvasAlt;
  final Color surface;
  final Color card;
  final Color input;
  final Color sidebar;
  final Color header;
  final Color border;
  final Color borderStrong;
  final Color text;
  final Color textMuted;
  final Color textFaint;
  final Color placeholder;
  final Color brandSoft;
  final Color brandIconBg;
  final Color success;
  final Color successSoft;
  final Color warning;
  final Color warningSoft;
  final Color danger;
  final Color dangerSoft;
  final Color info;
  final Color infoSoft;
  final Color pink;
  final Color pinkSoft;
  final Color orange;
  final Color orangeSoft;
  final Color indigo;
  final Color indigoSoft;
  final Color brandAccent;

  static const light = FinancePalette(
    canvas: FinanceTokens.canvas,
    canvasAlt: FinanceTokens.canvasAlt,
    surface: FinanceTokens.surface,
    card: FinanceTokens.surface,
    input: FinanceTokens.surface,
    sidebar: FinanceTokens.sidebar,
    header: FinanceTokens.header,
    border: FinanceTokens.border,
    borderStrong: FinanceTokens.borderStrong,
    text: FinanceTokens.text,
    textMuted: FinanceTokens.textMuted,
    textFaint: FinanceTokens.textFaint,
    placeholder: FinanceTokens.textFaint,
    brandSoft: FinanceTokens.brandSoft,
    brandIconBg: FinanceTokens.brandIconBg,
    success: FinanceTokens.success,
    successSoft: FinanceTokens.successSoft,
    warning: FinanceTokens.warning,
    warningSoft: FinanceTokens.warningSoft,
    danger: FinanceTokens.danger,
    dangerSoft: FinanceTokens.dangerSoft,
    info: FinanceTokens.info,
    infoSoft: FinanceTokens.infoSoft,
    pink: FinanceTokens.pink,
    pinkSoft: FinanceTokens.pinkSoft,
    orange: FinanceTokens.orange,
    orangeSoft: FinanceTokens.orangeSoft,
    indigo: FinanceTokens.indigo,
    indigoSoft: FinanceTokens.indigoSoft,
    brandAccent: FinanceTokens.brandDark,
  );

  static const dark = FinancePalette(
    canvas: Color(0xFF071216),
    canvasAlt: Color(0xFF0B171C),
    surface: Color(0xFF0B171C),
    card: Color(0xFF102126),
    input: Color(0xFF14262D),
    sidebar: Color(0xFF0B171C),
    header: Color(0xFF0B171C),
    border: Color(0xFF284047),
    borderStrong: Color(0xFF35535B),
    text: Color(0xFFF4FAFA),
    textMuted: Color(0xFFA9BEC2),
    textFaint: Color(0xFF7F969B),
    placeholder: Color(0xFF789096),
    brandSoft: Color(0xFF123832),
    brandIconBg: Color(0xFF16443C),
    success: Color(0xFF32D583),
    successSoft: Color(0xFF12382C),
    warning: Color(0xFFF5B84B),
    warningSoft: Color(0xFF3A2E12),
    danger: Color(0xFFFF6B6B),
    dangerSoft: Color(0xFF3A1C1C),
    info: Color(0xFF56B0FF),
    infoSoft: Color(0xFF132A3A),
    pink: Color(0xFFF472B6),
    pinkSoft: Color(0xFF3A1C2C),
    orange: Color(0xFFFF8A4C),
    orangeSoft: Color(0xFF3A2214),
    indigo: Color(0xFF8B8CFF),
    indigoSoft: Color(0xFF1C1F3A),
    brandAccent: FinanceTokens.brandLight,
  );

  @override
  FinancePalette copyWith({
    Color? canvas,
    Color? canvasAlt,
    Color? surface,
    Color? card,
    Color? input,
    Color? sidebar,
    Color? header,
    Color? border,
    Color? borderStrong,
    Color? text,
    Color? textMuted,
    Color? textFaint,
    Color? placeholder,
    Color? brandSoft,
    Color? brandIconBg,
    Color? success,
    Color? successSoft,
    Color? warning,
    Color? warningSoft,
    Color? danger,
    Color? dangerSoft,
    Color? info,
    Color? infoSoft,
    Color? pink,
    Color? pinkSoft,
    Color? orange,
    Color? orangeSoft,
    Color? indigo,
    Color? indigoSoft,
    Color? brandAccent,
  }) {
    return FinancePalette(
      canvas: canvas ?? this.canvas,
      canvasAlt: canvasAlt ?? this.canvasAlt,
      surface: surface ?? this.surface,
      card: card ?? this.card,
      input: input ?? this.input,
      sidebar: sidebar ?? this.sidebar,
      header: header ?? this.header,
      border: border ?? this.border,
      borderStrong: borderStrong ?? this.borderStrong,
      text: text ?? this.text,
      textMuted: textMuted ?? this.textMuted,
      textFaint: textFaint ?? this.textFaint,
      placeholder: placeholder ?? this.placeholder,
      brandSoft: brandSoft ?? this.brandSoft,
      brandIconBg: brandIconBg ?? this.brandIconBg,
      success: success ?? this.success,
      successSoft: successSoft ?? this.successSoft,
      warning: warning ?? this.warning,
      warningSoft: warningSoft ?? this.warningSoft,
      danger: danger ?? this.danger,
      dangerSoft: dangerSoft ?? this.dangerSoft,
      info: info ?? this.info,
      infoSoft: infoSoft ?? this.infoSoft,
      pink: pink ?? this.pink,
      pinkSoft: pinkSoft ?? this.pinkSoft,
      orange: orange ?? this.orange,
      orangeSoft: orangeSoft ?? this.orangeSoft,
      indigo: indigo ?? this.indigo,
      indigoSoft: indigoSoft ?? this.indigoSoft,
      brandAccent: brandAccent ?? this.brandAccent,
    );
  }

  @override
  FinancePalette lerp(ThemeExtension<FinancePalette>? other, double t) {
    if (other is! FinancePalette) return this;
    Color mix(Color a, Color b) => Color.lerp(a, b, t) ?? a;
    return FinancePalette(
      canvas: mix(canvas, other.canvas),
      canvasAlt: mix(canvasAlt, other.canvasAlt),
      surface: mix(surface, other.surface),
      card: mix(card, other.card),
      input: mix(input, other.input),
      sidebar: mix(sidebar, other.sidebar),
      header: mix(header, other.header),
      border: mix(border, other.border),
      borderStrong: mix(borderStrong, other.borderStrong),
      text: mix(text, other.text),
      textMuted: mix(textMuted, other.textMuted),
      textFaint: mix(textFaint, other.textFaint),
      placeholder: mix(placeholder, other.placeholder),
      brandSoft: mix(brandSoft, other.brandSoft),
      brandIconBg: mix(brandIconBg, other.brandIconBg),
      success: mix(success, other.success),
      successSoft: mix(successSoft, other.successSoft),
      warning: mix(warning, other.warning),
      warningSoft: mix(warningSoft, other.warningSoft),
      danger: mix(danger, other.danger),
      dangerSoft: mix(dangerSoft, other.dangerSoft),
      info: mix(info, other.info),
      infoSoft: mix(infoSoft, other.infoSoft),
      pink: mix(pink, other.pink),
      pinkSoft: mix(pinkSoft, other.pinkSoft),
      orange: mix(orange, other.orange),
      orangeSoft: mix(orangeSoft, other.orangeSoft),
      indigo: mix(indigo, other.indigo),
      indigoSoft: mix(indigoSoft, other.indigoSoft),
      brandAccent: mix(brandAccent, other.brandAccent),
    );
  }
}

extension FinanceThemeX on BuildContext {
  FinancePalette get hs {
    final extension = Theme.of(this).extension<FinancePalette>();
    if (extension != null) return extension;
    return Theme.of(this).brightness == Brightness.dark ? FinancePalette.dark : FinancePalette.light;
  }
}

class FinanceIconTone {
  const FinanceIconTone(this.foreground, this.background);
  final Color foreground;
  final Color background;

  static const teal = FinanceIconTone(FinanceTokens.brandDark, FinanceTokens.brandIconBg);
  static const rose = FinanceIconTone(FinanceTokens.pink, FinanceTokens.pinkSoft);
  static const orange = FinanceIconTone(FinanceTokens.orange, FinanceTokens.orangeSoft);
  static const amber = FinanceIconTone(Color(0xFFB45309), FinanceTokens.amberSoft);
  static const indigo = FinanceIconTone(FinanceTokens.indigo, FinanceTokens.indigoSoft);
  static const blue = FinanceIconTone(FinanceTokens.info, FinanceTokens.infoSoft);
  static const red = FinanceIconTone(FinanceTokens.danger, FinanceTokens.dangerSoft);
  static const green = FinanceIconTone(FinanceTokens.success, FinanceTokens.successSoft);

  FinanceIconTone themed(BuildContext context) {
    final p = context.hs;
    if (Theme.of(context).brightness == Brightness.light) return this;
    if (background == FinanceTokens.brandIconBg) return FinanceIconTone(FinanceTokens.brandLight, p.brandIconBg);
    if (background == FinanceTokens.pinkSoft) return FinanceIconTone(p.pink, p.pinkSoft);
    if (background == FinanceTokens.orangeSoft) return FinanceIconTone(p.orange, p.orangeSoft);
    if (background == FinanceTokens.amberSoft) return FinanceIconTone(p.warning, p.warningSoft);
    if (background == FinanceTokens.indigoSoft) return FinanceIconTone(p.indigo, p.indigoSoft);
    if (background == FinanceTokens.infoSoft) return FinanceIconTone(p.info, p.infoSoft);
    if (background == FinanceTokens.dangerSoft) return FinanceIconTone(p.danger, p.dangerSoft);
    if (background == FinanceTokens.successSoft) return FinanceIconTone(p.success, p.successSoft);
    return FinanceIconTone(foreground, p.brandSoft);
  }
}
