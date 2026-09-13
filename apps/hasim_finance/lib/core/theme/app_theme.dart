import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';

class AppTheme {
  static const Color brand = FinanceTokens.brand;

  static ThemeData light() {
    final textTheme = GoogleFonts.cairoTextTheme();
    const scheme = ColorScheme.light(
      primary: FinanceTokens.brand,
      onPrimary: Colors.white,
      secondary: FinanceTokens.brandDark,
      onSecondary: Colors.white,
      secondaryContainer: FinanceTokens.brandSoft,
      onSecondaryContainer: FinanceTokens.brandDark,
      surface: FinanceTokens.surface,
      onSurface: FinanceTokens.text,
      error: FinanceTokens.danger,
      onError: Colors.white,
      outline: FinanceTokens.borderStrong,
    );
    return _base(scheme, textTheme, FinancePalette.light);
  }

  static ThemeData dark() {
    final textTheme = GoogleFonts.cairoTextTheme(ThemeData.dark().textTheme);
    const p = FinancePalette.dark;
    const scheme = ColorScheme.dark(
      primary: FinanceTokens.brand,
      onPrimary: Color(0xFF06201C),
      secondary: FinanceTokens.brandLight,
      onSecondary: Color(0xFF06201C),
      secondaryContainer: Color(0xFF123832),
      onSecondaryContainer: Color(0xFF56E3CC),
      surface: Color(0xFF102126),
      onSurface: Color(0xFFF4FAFA),
      error: Color(0xFFFF6B6B),
      onError: Color(0xFF3A1C1C),
      outline: Color(0xFF284047),
    );
    return _base(scheme, textTheme, p);
  }

  static ThemeData _base(ColorScheme scheme, TextTheme textTheme, FinancePalette palette) {
    final isLight = scheme.brightness == Brightness.light;
    final shaped = textTheme.copyWith(
      headlineLarge: textTheme.headlineLarge?.copyWith(fontWeight: FontWeight.w800, color: palette.text, letterSpacing: -0.4),
      headlineMedium: textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800, color: palette.text),
      headlineSmall: textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800, color: palette.text),
      titleLarge: textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800, color: palette.text),
      titleMedium: textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700, color: palette.text),
      titleSmall: textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700, color: palette.text),
      bodyLarge: textTheme.bodyLarge?.copyWith(color: palette.text, height: 1.45),
      bodyMedium: textTheme.bodyMedium?.copyWith(color: palette.text, height: 1.45),
      bodySmall: textTheme.bodySmall?.copyWith(color: palette.textMuted, height: 1.4),
      labelLarge: textTheme.labelLarge?.copyWith(fontWeight: FontWeight.w700, color: palette.text),
    );

    final inputBorder = OutlineInputBorder(
      borderRadius: BorderRadius.circular(FinanceTokens.radius),
      borderSide: BorderSide(color: palette.border),
    );

    return ThemeData(
      useMaterial3: true,
      brightness: scheme.brightness,
      colorScheme: scheme,
      textTheme: shaped,
      scaffoldBackgroundColor: palette.canvas,
      canvasColor: palette.card,
      cardColor: palette.card,
      dividerColor: palette.border,
      extensions: [palette],
      iconTheme: IconThemeData(color: palette.textMuted),
      primaryIconTheme: IconThemeData(color: palette.textMuted),
      appBarTheme: AppBarTheme(
        centerTitle: false,
        backgroundColor: palette.header,
        foregroundColor: palette.text,
        surfaceTintColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        toolbarHeight: 64,
        titleSpacing: 20,
        titleTextStyle: shaped.headlineSmall?.copyWith(fontWeight: FontWeight.w800, fontSize: 22),
      ),
      cardTheme: CardThemeData(
        elevation: 0,
        color: palette.card,
        margin: EdgeInsets.zero,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(FinanceTokens.radiusLg),
          side: BorderSide(color: palette.border),
        ),
        shadowColor: const Color(0xFF000000).withValues(alpha: isLight ? 0.06 : 0.28),
      ),
      dividerTheme: DividerThemeData(color: palette.border, space: 1, thickness: 1),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: palette.input,
        isDense: true,
        hintStyle: TextStyle(color: palette.placeholder, fontSize: 13),
        labelStyle: TextStyle(color: palette.textMuted, fontWeight: FontWeight.w600, fontSize: 12),
        prefixIconColor: palette.textFaint,
        suffixIconColor: palette.textFaint,
        border: inputBorder,
        enabledBorder: inputBorder,
        disabledBorder: inputBorder,
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(FinanceTokens.radius),
          borderSide: const BorderSide(color: FinanceTokens.brand, width: 1.4),
        ),
        errorBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(FinanceTokens.radius),
          borderSide: BorderSide(color: palette.danger),
        ),
        contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
      ),
      dropdownMenuTheme: DropdownMenuThemeData(
        menuStyle: MenuStyle(
          backgroundColor: WidgetStatePropertyAll(palette.card),
          surfaceTintColor: const WidgetStatePropertyAll(Colors.transparent),
          shape: WidgetStatePropertyAll(
            RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(FinanceTokens.radius),
              side: BorderSide(color: palette.border),
            ),
          ),
        ),
      ),
      popupMenuTheme: PopupMenuThemeData(
        color: palette.card,
        surfaceTintColor: Colors.transparent,
        textStyle: shaped.bodyMedium,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(FinanceTokens.radius),
          side: BorderSide(color: palette.border),
        ),
      ),
      menuTheme: MenuThemeData(
        style: MenuStyle(
          backgroundColor: WidgetStatePropertyAll(palette.card),
          surfaceTintColor: const WidgetStatePropertyAll(Colors.transparent),
        ),
      ),
      tooltipTheme: TooltipThemeData(
        decoration: BoxDecoration(
          color: palette.surface,
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: palette.border),
        ),
        textStyle: shaped.bodySmall?.copyWith(color: palette.text),
      ),
      dialogTheme: DialogThemeData(
        backgroundColor: palette.card,
        surfaceTintColor: Colors.transparent,
        iconColor: palette.textMuted,
        titleTextStyle: shaped.titleLarge,
        contentTextStyle: shaped.bodyMedium,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radiusXl)),
      ),
      bottomSheetTheme: BottomSheetThemeData(
        backgroundColor: palette.card,
        surfaceTintColor: Colors.transparent,
        modalBackgroundColor: palette.card,
        shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
        ),
      ),
      datePickerTheme: DatePickerThemeData(
        backgroundColor: palette.card,
        headerBackgroundColor: palette.surface,
        headerForegroundColor: palette.text,
        dayForegroundColor: WidgetStatePropertyAll(palette.text),
        todayForegroundColor: const WidgetStatePropertyAll(FinanceTokens.brand),
        yearForegroundColor: WidgetStatePropertyAll(palette.text),
        weekdayStyle: shaped.bodySmall,
        surfaceTintColor: Colors.transparent,
      ),
      timePickerTheme: TimePickerThemeData(
        backgroundColor: palette.card,
        hourMinuteColor: palette.input,
        dayPeriodColor: palette.input,
        dialBackgroundColor: palette.input,
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: FinanceTokens.brand,
          foregroundColor: Colors.white,
          minimumSize: const Size(40, FinanceTokens.controlHeight),
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radius)),
          textStyle: shaped.labelLarge?.copyWith(fontWeight: FontWeight.w800, color: Colors.white),
          elevation: 0,
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: palette.text,
          backgroundColor: palette.surface,
          minimumSize: const Size(40, FinanceTokens.controlHeight),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          side: BorderSide(color: palette.border),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radius)),
          textStyle: shaped.labelLarge?.copyWith(fontWeight: FontWeight.w700),
        ),
      ),
      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: palette.brandAccent,
          textStyle: shaped.labelLarge?.copyWith(fontWeight: FontWeight.w700),
        ),
      ),
      chipTheme: ChipThemeData(
        backgroundColor: palette.input,
        selectedColor: palette.brandSoft,
        disabledColor: palette.surface,
        side: BorderSide(color: palette.border),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radius)),
        labelStyle: shaped.labelLarge?.copyWith(fontSize: 12, fontWeight: FontWeight.w700, color: palette.text),
        secondaryLabelStyle: shaped.labelLarge?.copyWith(fontSize: 12, fontWeight: FontWeight.w700, color: palette.brandAccent),
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 0),
      ),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: palette.surface,
        contentTextStyle: shaped.bodyMedium,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(FinanceTokens.radius)),
      ),
      floatingActionButtonTheme: const FloatingActionButtonThemeData(
        backgroundColor: FinanceTokens.brand,
        foregroundColor: Colors.white,
        elevation: 2,
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: palette.header,
        indicatorColor: palette.brandSoft,
        elevation: 0,
        iconTheme: WidgetStateProperty.resolveWith((states) {
          final selected = states.contains(WidgetState.selected);
          return IconThemeData(color: selected ? palette.brandAccent : palette.textMuted);
        }),
        labelTextStyle: WidgetStatePropertyAll(shaped.labelSmall?.copyWith(fontWeight: FontWeight.w700, color: palette.textMuted)),
      ),
      dataTableTheme: DataTableThemeData(
        headingRowColor: WidgetStatePropertyAll(palette.surface),
        dataRowColor: WidgetStateProperty.resolveWith((states) {
          if (states.contains(WidgetState.hovered)) return palette.input;
          return palette.card;
        }),
        headingTextStyle: shaped.labelLarge?.copyWith(fontSize: 12, color: palette.textMuted, fontWeight: FontWeight.w800),
        dataTextStyle: shaped.bodyMedium?.copyWith(fontSize: 13, color: palette.text),
        dividerThickness: 1,
        headingRowHeight: 40,
        dataRowMinHeight: 44,
        dataRowMaxHeight: 52,
      ),
      listTileTheme: ListTileThemeData(
        iconColor: palette.textMuted,
        textColor: palette.text,
        tileColor: Colors.transparent,
        selectedTileColor: palette.brandSoft,
        dense: true,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      ),
      switchTheme: SwitchThemeData(
        thumbColor: WidgetStateProperty.resolveWith((states) {
          return states.contains(WidgetState.selected) ? FinanceTokens.brand : palette.textFaint;
        }),
        trackColor: WidgetStateProperty.resolveWith((states) {
          return states.contains(WidgetState.selected) ? palette.brandSoft : palette.input;
        }),
      ),
      checkboxTheme: CheckboxThemeData(
        fillColor: WidgetStateProperty.resolveWith((states) {
          return states.contains(WidgetState.selected) ? FinanceTokens.brand : Colors.transparent;
        }),
        side: BorderSide(color: palette.borderStrong),
      ),
      radioTheme: RadioThemeData(
        fillColor: WidgetStateProperty.resolveWith((states) {
          return states.contains(WidgetState.selected) ? FinanceTokens.brand : palette.textFaint;
        }),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(color: FinanceTokens.brand),
    );
  }
}
