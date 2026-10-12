import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/config/app_config.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class AppTheme {
  static const Color brand = HasimTokens.primary;
  static const Color brandDark = HasimTokens.accentDark;

  static ColorScheme lightScheme() {
    final base = Color(AppConfig.brand.primary);
    return ColorScheme.fromSeed(
      seedColor: base,
      brightness: Brightness.light,
      primary: HasimTokens.primary,
      onPrimary: Colors.white,
      surface: HasimTokens.background,
      onSurface: HasimTokens.text,
      onSurfaceVariant: HasimTokens.secondaryText,
      surfaceContainerLowest: HasimTokens.card,
      surfaceContainerLow: HasimTokens.card,
      surfaceContainer: HasimTokens.card,
      surfaceContainerHigh: HasimTokens.card,
      surfaceContainerHighest: HasimTokens.mintSoft,
      outline: HasimTokens.border,
      outlineVariant: HasimTokens.line,
      error: HasimTokens.danger,
      onError: Colors.white,
      errorContainer: HasimTokens.dangerSoft,
      inverseSurface: HasimTokens.ink,
      onInverseSurface: Colors.white,
    );
  }

  static ColorScheme darkScheme() {
    final base = Color(AppConfig.brand.primary);
    return ColorScheme.fromSeed(
      seedColor: base,
      brightness: Brightness.dark,
      primary: HasimTokens.primary,
      onPrimary: Colors.white,
      surface: HasimTokens.darkPage,
      onSurface: Colors.white,
      onSurfaceVariant: const Color(0xFF9E9E9E),
      surfaceContainerLowest: HasimTokens.darkCard,
      surfaceContainerLow: HasimTokens.darkCard,
      surfaceContainer: HasimTokens.darkCard,
      surfaceContainerHigh: HasimTokens.darkCard,
      surfaceContainerHighest: HasimTokens.darkLine,
      outline: HasimTokens.darkLine,
      outlineVariant: HasimTokens.darkLine,
      error: HasimTokens.danger,
      onError: Colors.white,
      errorContainer: const Color(0xFF3A1C1C),
    );
  }

  static TextTheme cairoTheme({required Color color, required Color muted}) {
    TextStyle style({
      required double size,
      required FontWeight weight,
      double height = 1.45,
      Color? override,
    }) {
      return GoogleFonts.cairo(
        color: override ?? color,
        fontSize: size,
        fontWeight: weight,
        height: height,
        letterSpacing: 0,
      );
    }

    return TextTheme(
      displayLarge: style(size: 32, weight: FontWeight.w800, height: 1.25),
      displayMedium: style(size: 28, weight: FontWeight.w800, height: 1.25),
      displaySmall: style(size: 24, weight: FontWeight.w800, height: 1.3),
      headlineLarge: style(size: 26, weight: FontWeight.w800, height: 1.3),
      headlineMedium: style(size: 22, weight: FontWeight.w800, height: 1.3),
      headlineSmall: style(size: 20, weight: FontWeight.w800, height: 1.3),
      titleLarge: style(size: 18, weight: FontWeight.w700, height: 1.35),
      titleMedium: style(size: 16, weight: FontWeight.w700, height: 1.4),
      titleSmall: style(size: 14, weight: FontWeight.w700, height: 1.4),
      bodyLarge: style(size: 15, weight: FontWeight.w600, height: 1.5),
      bodyMedium: style(size: 14, weight: FontWeight.w500, height: 1.5),
      bodySmall: style(
        size: 12,
        weight: FontWeight.w500,
        height: 1.45,
        override: muted,
      ),
      labelLarge: style(size: 13, weight: FontWeight.w700, height: 1.35),
      labelMedium: style(size: 12, weight: FontWeight.w600, height: 1.35),
      labelSmall: style(
        size: 11,
        weight: FontWeight.w500,
        height: 1.35,
        override: muted,
      ),
    );
  }

  static ThemeData light() {
    final scheme = lightScheme();
    final textTheme = cairoTheme(
      color: HasimTokens.text,
      muted: HasimTokens.muted,
    );
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: scheme,
      textTheme: textTheme,
      primaryTextTheme: textTheme,
      iconTheme: const IconThemeData(color: HasimTokens.muted, size: 22),
      scaffoldBackgroundColor: HasimTokens.background,
      canvasColor: HasimTokens.background,
      cardColor: HasimTokens.card,
      dividerColor: HasimTokens.line,
      applyElevationOverlayColor: false,
      switchTheme: _switchTheme(),
      appBarTheme: AppBarTheme(
        centerTitle: true,
        backgroundColor: HasimTokens.background,
        foregroundColor: HasimTokens.text,
        elevation: 0,
        scrolledUnderElevation: 0,
        surfaceTintColor: Colors.transparent,
        systemOverlayStyle: const SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.dark,
          systemNavigationBarColor: HasimTokens.card,
          systemNavigationBarIconBrightness: Brightness.dark,
        ),
        titleTextStyle: textTheme.titleLarge?.copyWith(color: HasimTokens.text),
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: HasimTokens.card,
        surfaceTintColor: Colors.transparent,
        shadowColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        ),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: HasimTokens.card,
        surfaceTintColor: Colors.transparent,
        insetPadding: HasimLayout.dialogInset,
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: HasimTokens.card,
        surfaceTintColor: Colors.transparent,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: HasimTokens.card,
        labelStyle: GoogleFonts.cairo(
          fontWeight: FontWeight.w600,
          fontSize: 14,
        ),
        hintStyle: GoogleFonts.cairo(
          color: HasimTokens.mutedSoft,
          fontSize: 14,
        ),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
          borderSide: const BorderSide(color: HasimTokens.line),
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
          borderSide: const BorderSide(color: HasimTokens.line),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(HasimTokens.radiusMd),
          borderSide: const BorderSide(color: HasimTokens.primary, width: 1.4),
        ),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 14,
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: HasimTokens.primary,
          foregroundColor: Colors.white,
          minimumSize: const Size(64, 48),
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(HasimTokens.radiusButton),
          ),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: HasimTokens.primary,
          backgroundColor: Colors.white,
          side: const BorderSide(color: HasimTokens.primary),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(HasimTokens.radiusButton),
          ),
          minimumSize: const Size(64, 48),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: HasimTokens.primary,
          textStyle: GoogleFonts.cairo(fontWeight: FontWeight.w700),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: HasimTokens.card,
        labelStyle: GoogleFonts.cairo(
          color: HasimTokens.text,
          fontWeight: FontWeight.w600,
        ),
        side: const BorderSide(color: HasimTokens.line),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
      popupMenuTheme: PopupMenuThemeData(
        color: HasimTokens.card,
        surfaceTintColor: Colors.transparent,
        textStyle: GoogleFonts.cairo(
          color: HasimTokens.text,
          fontSize: 14,
          fontWeight: FontWeight.w600,
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: HasimTokens.card,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        shadowColor: Colors.transparent,
        indicatorColor: HasimTokens.mintChip,
        indicatorShape: const StadiumBorder(),
        iconTheme: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return IconThemeData(
            size: 24,
            color: selected
                ? HasimTokens.activeNavigation
                : HasimTokens.inactiveNavigation,
          );
        }),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return GoogleFonts.cairo(
            fontSize: 11,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            color: selected
                ? HasimTokens.activeNavigation
                : HasimTokens.inactiveNavigation,
          );
        }),
      ),
    );
  }

  static ThemeData dark() {
    final scheme = darkScheme();
    final textTheme = cairoTheme(
      color: Colors.white,
      muted: const Color(0xFF9E9E9E),
    );
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      colorScheme: scheme,
      textTheme: textTheme,
      primaryTextTheme: textTheme,
      scaffoldBackgroundColor: HasimTokens.darkPage,
      canvasColor: HasimTokens.darkPage,
      cardColor: HasimTokens.darkCard,
      dividerColor: HasimTokens.darkLine,
      applyElevationOverlayColor: false,
      switchTheme: _switchTheme(dark: true),
      appBarTheme: AppBarTheme(
        centerTitle: true,
        backgroundColor: HasimTokens.darkPage,
        foregroundColor: Colors.white,
        elevation: 0,
        scrolledUnderElevation: 0,
        surfaceTintColor: Colors.transparent,
        systemOverlayStyle: const SystemUiOverlayStyle(
          statusBarColor: Colors.transparent,
          statusBarIconBrightness: Brightness.light,
          systemNavigationBarColor: HasimTokens.darkCard,
          systemNavigationBarIconBrightness: Brightness.light,
        ),
        titleTextStyle: textTheme.titleLarge?.copyWith(color: Colors.white),
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: HasimTokens.darkCard,
        surfaceTintColor: Colors.transparent,
        shadowColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        ),
      ),
      dialogTheme: const DialogThemeData(
        backgroundColor: HasimTokens.darkCard,
        surfaceTintColor: Colors.transparent,
        insetPadding: HasimLayout.dialogInset,
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: HasimTokens.darkCard,
        surfaceTintColor: Colors.transparent,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: HasimTokens.darkCard,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 14,
          vertical: 14,
        ),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: HasimTokens.primary,
          foregroundColor: Colors.white,
          minimumSize: const Size(64, 48),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(HasimTokens.radiusButton),
          ),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: HasimTokens.chatGreen,
          textStyle: GoogleFonts.cairo(fontWeight: FontWeight.w700),
        ),
      ),
      iconTheme: const IconThemeData(color: Colors.white),
      chipTheme: ChipThemeData(
        backgroundColor: HasimTokens.darkCard,
        labelStyle: GoogleFonts.cairo(
          color: Colors.white,
          fontWeight: FontWeight.w600,
        ),
        side: const BorderSide(color: HasimTokens.darkLine),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
      popupMenuTheme: PopupMenuThemeData(
        color: HasimTokens.darkCard,
        surfaceTintColor: Colors.transparent,
        textStyle: GoogleFonts.cairo(
          color: Colors.white,
          fontSize: 14,
          fontWeight: FontWeight.w600,
        ),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: HasimTokens.darkCard,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        shadowColor: Colors.transparent,
        indicatorColor: HasimTokens.primary.withValues(alpha: 0.22),
        indicatorShape: const StadiumBorder(),
        iconTheme: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return IconThemeData(
            size: 24,
            color: selected
                ? HasimTokens.activeNavigation
                : HasimTokens.inactiveNavigation,
          );
        }),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return GoogleFonts.cairo(
            fontSize: 11,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            color: selected
                ? HasimTokens.activeNavigation
                : HasimTokens.inactiveNavigation,
          );
        }),
      ),
    );
  }
}

SwitchThemeData _switchTheme({bool dark = false}) {
  final off = dark ? const Color(0xFF3A3A3A) : const Color(0xFFE5EAEA);
  return SwitchThemeData(
    thumbColor: WidgetStateProperty.resolveWith((states) {
      if (states.contains(WidgetState.selected)) return Colors.white;
      return dark ? const Color(0xFFD5E0DD) : Colors.white;
    }),
    trackColor: WidgetStateProperty.resolveWith((states) {
      if (states.contains(WidgetState.selected)) return HasimTokens.primary;
      return off;
    }),
    trackOutlineColor: const WidgetStatePropertyAll(Colors.transparent),
  );
}
