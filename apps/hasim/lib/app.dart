import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/theme_mode_controller.dart';
import 'package:hasim/core/widgets/desktop_window_frame.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/privacy/presentation/app_lock_host.dart';
import 'package:hasim/l10n/app_localizations.dart';
import 'package:hasim/router/app_router.dart';
import 'package:hasim/router/app_shell.dart';

class HasimApp extends ConsumerWidget {
  const HasimApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    // Ensure auth controller is created (wires 401 handler) even on splash.
    final auth = ref.watch(authControllerProvider);
    final themeMode = ref.watch(themeModeControllerProvider);
    final router = ref.watch(appRouterProvider);
    final chromeLocation = ref.watch(appChromeLocationProvider);
    final showNavigation =
        auth.isAuthenticated &&
        (auth.isIndependentWorker || auth.workspace != null);

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
      builder: (context, child) {
        final overlay =
            Theme.of(context).appBarTheme.systemOverlayStyle ??
            const SystemUiOverlayStyle(
              statusBarColor: Colors.transparent,
              statusBarIconBrightness: Brightness.dark,
            );
        return AnnotatedRegion<SystemUiOverlayStyle>(
          value: overlay,
          child: Directionality(
            textDirection: TextDirection.rtl,
            child: DesktopWindowFrame(
              showTitleBar: chromeLocation != '/splash',
              showNavRail: showNavigation,
              child: AppLockHost(child: child ?? const SizedBox.shrink()),
            ),
          ),
        );
      },
      routerConfig: router,
    );
  }
}
