import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim_finance/core/auth/auth_controller.dart';
import 'package:hasim_finance/core/routing/app_router.dart';
import 'package:hasim_finance/core/theme/app_theme.dart';
import 'package:hasim_finance/core/theme/theme_controllers.dart';
import 'package:hasim_finance/l10n/app_localizations.dart';

export 'package:hasim_finance/core/theme/theme_controllers.dart';

class HasimFinanceApp extends ConsumerWidget {
  const HasimFinanceApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(appRouterProvider);
    final localeCode = ref.watch(localeProvider);
    final mode = ref.watch(themeModeProvider);
    ref.watch(authControllerProvider);

    return MaterialApp.router(
      title: 'Hasim Finance',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: mode,
      locale: Locale(localeCode),
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      routerConfig: router,
    );
  }
}
