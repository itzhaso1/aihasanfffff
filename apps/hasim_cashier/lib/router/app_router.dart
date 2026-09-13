import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/auth/auth_controller.dart';
import '../core/config/app_config.dart';
import '../core/theme/hasim_colors.dart';
import '../core/theme/hasim_theme.dart';
import '../core/widgets/hasim_brand_logo.dart';
import '../features/auth/local_unlock_pin_screen.dart';
import '../features/auth/login_screen.dart';
import '../features/auth/pin_login_screen.dart';
import '../features/auth/pos_blocked_screen.dart';
import '../features/auth/workspace_picker_screen.dart';
import '../features/home/shell_screen.dart';
import '../features/kitchen/kitchen_station_screen.dart';
import '../features/reports/reports_station_screen.dart';

/// GoRouter must NOT be rebuilt on every auth state change.
/// Watching auth inside this provider remounted ShellScreen → re-hit
/// /bootstrap in a loop until Laravel returned 429 Too Many Attempts.
final appRouterProvider = Provider<GoRouter>((ref) {
  final refresh = _AuthRefresh(ref);

  return GoRouter(
    initialLocation: '/splash',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final loggingIn = state.matchedLocation == '/login';
      final splash = state.matchedLocation == '/splash';
      final pin = state.matchedLocation == '/pin';
      final setup = state.matchedLocation == '/standalone-setup';
      final blocked = state.matchedLocation == '/pos-blocked';
      final kitchen = state.matchedLocation == '/kitchen';
      final reports = state.matchedLocation == '/reports';
      final workspacesRoute = state.matchedLocation == '/workspaces';
      final localPin = state.matchedLocation == '/local-unlock-pin';

      if (setup) return loggingIn ? null : '/login';

      if (auth.isLoading) {
        return splash ? null : '/splash';
      }

      final session = auth.valueOrNull;
      if (session?.isCloudSetup == true) {
        if (session!.needsLocalUnlockPin) {
          return localPin ? null : '/local-unlock-pin';
        }
        if (workspacesRoute || blocked) return null;
        return '/workspaces';
      }

      // Offline-only: cloud auth routes are dead ends except during setup.
      if (AppConfig.offlineOnly) {
        final cloud =
            state.matchedLocation == '/forgot-password' ||
            state.matchedLocation == '/reset-password' ||
            workspacesRoute;
        if (cloud) return '/login';
      }

      if (session == null) {
        if (loggingIn || pin || kitchen || reports || localPin) {
          return null;
        }
        return '/login';
      }

      if (AppConfig.offlineOnly) {
        if (loggingIn || splash || pin || blocked || localPin) {
          return session.landingRoute;
        }
        if (state.matchedLocation == '/home' &&
            !session.canUsePos &&
            session.landingRoute != '/home') {
          return session.landingRoute;
        }
        if (reports && !session.canViewReports) {
          return session.landingRoute;
        }
        return null;
      }

      final needsPick =
          session.workspace == null && session.workspaces.length > 1;
      if (needsPick) {
        return workspacesRoute ? null : '/workspaces';
      }

      if (loggingIn || splash || pin) {
        return '/home';
      }
      if (reports && !session.canViewReports) {
        return session.landingRoute;
      }
      return null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, __) => const _Splash()),
      GoRoute(path: '/login', builder: (_, __) => const LoginScreen()),
      GoRoute(
        path: '/local-unlock-pin',
        builder: (_, __) => const LocalUnlockPinScreen(),
      ),
      GoRoute(path: '/pin', builder: (_, __) => const PinLoginScreen()),
      GoRoute(path: '/standalone-setup', redirect: (_, __) => '/login'),
      GoRoute(
        path: '/workspaces',
        builder: (_, __) => const WorkspacePickerScreen(),
      ),
      GoRoute(
        path: '/pos-blocked',
        builder: (_, __) => const PosBlockedScreen(),
      ),
      GoRoute(path: '/home', builder: (_, __) => const ShellScreen()),
      GoRoute(
        path: '/kitchen',
        builder: (_, __) => const KitchenStationScreen(),
      ),
      GoRoute(
        path: '/reports',
        builder: (_, __) => const ReportsStationScreen(),
      ),
    ],
  );
});

class _AuthRefresh extends ChangeNotifier {
  _AuthRefresh(this.ref) {
    ref.listen(authControllerProvider, (_, __) => notifyListeners());
  }

  final Ref ref;
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: DecoratedBox(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [HasimColors.brandSoft, Colors.white],
          ),
        ),
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const HasimBrandLogo(width: 220),
              const SizedBox(height: 12),
              const Text(
                'كاشير حاسم',
                style: TextStyle(
                  fontSize: 18,
                  fontWeight: FontWeight.w800,
                  color: HasimColors.ink,
                ),
              ),
              const SizedBox(height: 18),
              const SizedBox(
                width: 28,
                height: 28,
                child: CircularProgressIndicator(
                  strokeWidth: 2.5,
                  color: HasimColors.brand,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Expose theme helper for MaterialApp.
ThemeData hasimCashierTheme() => HasimTheme.light();
