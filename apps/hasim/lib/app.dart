import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/router/app_router.dart';

class HasimApp extends ConsumerWidget {
  const HasimApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Ensure auth controller is created (wires 401 handler) even on splash.
    ref.watch(authControllerProvider);
    final themeMode = ref.watch(themeModeControllerProvider);
    final router = ref.watch(appRouterProvider);

    return MaterialApp.router(
      title: 'حاسم',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: themeMode,
      locale: const Locale('ar'),
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      builder: (context, child) => Directionality(
        textDirection: TextDirection.rtl,
        child: child ?? const SizedBox.shrink(),
      ),
      routerConfig: router,
    );
  }
}
