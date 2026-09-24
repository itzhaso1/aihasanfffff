import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim/core/config/app_config.dart';
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
      onSurfaceVariant: const Color(0xFF9AABA7),
      surfaceContainerLowest: HasimTokens.darkCard,
      surfaceContainerLow: HasimTokens.darkCard,
      surfaceContainer: HasimTokens.darkCard,
      surfaceContainerHigh: HasimTokens.darkCard,
      surfaceContainerHighest: HasimTokens.darkLine,
      outline: HasimTokens.darkLine,
      outlineVariant: HasimTokens.darkLine,
    );
  }

  static ThemeData light() {
    final scheme = lightScheme();
    final textTheme = GoogleFonts.cairoTextTheme().apply(
      bodyColor: HasimTokens.text,
      displayColor: HasimTokens.text,
    );
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.light,
      colorScheme: scheme,
      textTheme: textTheme,
      scaffoldBackgroundColor: HasimTokens.background,
      canvasColor: HasimTokens.background,
      cardColor: HasimTokens.card,
      dividerColor: HasimTokens.line,
      applyElevationOverlayColor: false,
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
        titleTextStyle: GoogleFonts.cairo(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: HasimTokens.text,
        ),
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: HasimTokens.card,
        surfaceTintColor: Colors.transparent,
        shadowColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(HasimTokens.radiusCard)),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: HasimTokens.card,
        surfaceTintColor: Colors.transparent,
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: HasimTokens.card,
        surfaceTintColor: Colors.transparent,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: HasimTokens.card,
        labelStyle: GoogleFonts.cairo(fontWeight: FontWeight.w600, fontSize: 14),
        hintStyle: GoogleFonts.cairo(color: HasimTokens.mutedSoft, fontSize: 14),
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
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: HasimTokens.primary,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(46),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: HasimTokens.primary,
          side: const BorderSide(color: HasimTokens.fieldBorder),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          minimumSize: const Size(64, 44),
        ),
      ),
      chipTheme: ChipThemeData(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
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
            color: selected ? HasimTokens.activeNavigation : HasimTokens.inactiveNavigation,
          );
        }),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return GoogleFonts.cairo(
            fontSize: 11,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            color: selected ? HasimTokens.activeNavigation : HasimTokens.inactiveNavigation,
          );
        }),
      ),
    );
  }

  static ThemeData dark() {
    final scheme = darkScheme();
    final textTheme = GoogleFonts.cairoTextTheme(ThemeData.dark().textTheme).apply(
      bodyColor: Colors.white,
      displayColor: Colors.white,
    );
    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      colorScheme: scheme,
      textTheme: textTheme,
      scaffoldBackgroundColor: HasimTokens.darkPage,
      canvasColor: HasimTokens.darkPage,
      cardColor: HasimTokens.darkCard,
      dividerColor: HasimTokens.darkLine,
      applyElevationOverlayColor: false,
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
        titleTextStyle: GoogleFonts.cairo(
          fontSize: 18,
          fontWeight: FontWeight.w700,
          color: Colors.white,
        ),
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: HasimTokens.darkCard,
        surfaceTintColor: Colors.transparent,
        shadowColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(HasimTokens.radiusCard)),
      ),
      dialogTheme: const DialogThemeData(
        backgroundColor: HasimTokens.darkCard,
        surfaceTintColor: Colors.transparent,
      ),
      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: HasimTokens.darkCard,
        surfaceTintColor: Colors.transparent,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: HasimTokens.darkCard,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 14),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          minimumSize: const Size.fromHeight(48),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
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
            color: selected ? HasimTokens.activeNavigation : HasimTokens.inactiveNavigation,
          );
        }),
        labelTextStyle: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return GoogleFonts.cairo(
            fontSize: 11,
            fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
            color: selected ? HasimTokens.activeNavigation : HasimTokens.inactiveNavigation,
          );
        }),
      ),
    );
  }
}
