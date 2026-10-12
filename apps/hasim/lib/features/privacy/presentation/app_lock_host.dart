import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/privacy/presentation/pin_prompt.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';

/// يغطي التطبيق عند تفعيل القفل، ويُقفل من جديد عند مغادرة التطبيق.
class AppLockHost extends ConsumerStatefulWidget {
  const AppLockHost({super.key, required this.child});

  final Widget child;

  @override
  ConsumerState<AppLockHost> createState() => _AppLockHostState();
}

class _AppLockHostState extends ConsumerState<AppLockHost> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused || state == AppLifecycleState.hidden) {
      ref.read(chatLockControllerProvider.notifier).lockSessions();
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(authControllerProvider, (previous, next) {
      if (previous?.isAuthenticated == true && !next.isAuthenticated) {
        ref.read(chatLockControllerProvider.notifier).lockSessions();
      }
    });

    final auth = ref.watch(authControllerProvider);
    final lock = ref.watch(chatLockControllerProvider);
    if (auth.isAuthenticated && !lock.loaded) {
      return const ColoredBox(color: Color(0xFFFFFFFF), child: SizedBox.expand());
    }
    if (!auth.isAuthenticated || !lock.needsAppLock) return widget.child;

    final notifier = ref.read(chatLockControllerProvider.notifier);
    return AppLockScreen(
      onVerified: (pin) {
        final ok = notifier.verifyPin(pin);
        if (ok) notifier.markAppUnlocked();
        return ok;
      },
      onBiometric: lock.appBiometricEnabled && lock.biometricAvailable
          ? () async {
              final ok = await notifier.authenticateBiometric('أكّد هويتك لفتح حاسم');
              if (ok) notifier.markAppUnlocked();
              return ok;
            }
          : null,
    );
  }
}
