import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

/// ملصق البداية المرجعي — أبيض سادة كما في الصورة، بدون تدرج نعناعي.
const hasimSplashAsset = 'assets/branding/hasim_splash.png';
const hasimSplashMarkAsset = 'assets/branding/splash/mark.png';

const _artW = 950.0;
const _artH = 1656.0;
const _barFill = Color(0xFF01BDA6);
const _barTrack = Color(0xFFE0E0E0);

class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key, this.skipIntroAnimation});

  @visibleForTesting
  final bool? skipIntroAnimation;

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen>
    with SingleTickerProviderStateMixin {
  bool _navigated = false;
  bool _checkingNavigation = false;
  bool _navCheckScheduled = false;
  bool _bootstrapGuardElapsed = false;
  Timer? _bootstrapGuard;
  Timer? _retryTimer;
  late final AnimationController _intro;
  late final Animation<double> _markFade;
  late final Animation<double> _markScale;
  late final Animation<double> _markTurns;
  late final Animation<double> _posterFade;
  late final Animation<double> _progress;
  late final List<Animation<double>> _iconReveal;

  @override
  void initState() {
    super.initState();
    _intro = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 220),
    );
    _markFade = CurvedAnimation(
      parent: _intro,
      curve: const Interval(0.00, 0.25, curve: Curves.easeOut),
    );
    _markScale = Tween<double>(begin: 0.92, end: 1).animate(
      CurvedAnimation(
        parent: _intro,
        curve: const Interval(0.00, 0.30, curve: Curves.easeOut),
      ),
    );
    _markTurns = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(
        parent: _intro,
        curve: const Interval(0.15, 0.55, curve: Curves.easeInOut),
      ),
    );
    _posterFade = CurvedAnimation(
      parent: _intro,
      curve: const Interval(0.05, 0.40, curve: Curves.easeOut),
    );
    _progress = CurvedAnimation(
      parent: _intro,
      curve: const Interval(0.10, 0.95, curve: Curves.easeInOut),
    );
    _iconReveal = List<Animation<double>>.generate(6, (i) {
      final start = 0.25 + i * 0.08;
      return CurvedAnimation(
        parent: _intro,
        curve: Interval(
          start,
          (start + 0.18).clamp(0.0, 1.0),
          curve: Curves.easeOut,
        ),
      );
    });

    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(_runSplashIntro());
    });
    WidgetsBinding.instance.ensureVisualUpdate();

    if (!_skipIntro) {
      _bootstrapGuard = Timer(const Duration(milliseconds: 1200), () async {
        if (!mounted || _navigated) return;
        _bootstrapGuardElapsed = true;
        final auth = ref.read(authControllerProvider);
        if (auth.bootstrapping) {
          try {
            await ref
                .read(authControllerProvider.notifier)
                .abortBootstrapAsGuest();
          } catch (_) {}
        }
        if (mounted) {
          if (_intro.value < 1.0) {
            _intro.value = 1.0;
          }
          _scheduleNavigationCheck();
        }
      });
    }
  }

  static bool get _isFlutterTest =>
      !kIsWeb && Platform.environment.containsKey('FLUTTER_TEST');

  bool get _skipIntro => widget.skipIntroAnimation ?? _isFlutterTest;

  Future<void> _runSplashIntro() async {
    if (!mounted) return;
    if (_skipIntro) {
      _intro.value = 1.0;
      _scheduleNavigationCheck();
      return;
    }
    try {
      await _intro.forward();
    } catch (_) {
      _intro.value = 1.0;
    }
    if (!mounted) return;
    _scheduleNavigationCheck();
  }

  @override
  void dispose() {
    _bootstrapGuard?.cancel();
    _retryTimer?.cancel();
    _intro.dispose();
    super.dispose();
  }

  void _scheduleNavigationCheck() {
    if (!mounted || _navigated || _navCheckScheduled) return;
    _navCheckScheduled = true;
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _navCheckScheduled = false;
      if (mounted) {
        unawaited(_maybeNavigate());
      }
    });
    // Post-frame callbacks do not wake the engine once the intro ticker stops.
    WidgetsBinding.instance.ensureVisualUpdate();
  }

  Future<void> _maybeNavigate() async {
    if (_navigated || _checkingNavigation || !mounted) return;
    _checkingNavigation = true;
    try {
      if (!_skipIntro &&
          _intro.status != AnimationStatus.completed &&
          _intro.value < 1.0) {
        if (_bootstrapGuardElapsed) {
          _intro.value = 1.0;
        } else {
          return;
        }
      }
      var auth = ref.read(authControllerProvider);
      if (auth.bootstrapping) {
        if (!_skipIntro && _bootstrapGuardElapsed) {
          try {
            await ref
                .read(authControllerProvider.notifier)
                .abortBootstrapAsGuest();
          } catch (_) {}
          if (!mounted) return;
          auth = ref.read(authControllerProvider);
        } else {
          return;
        }
      }
      if (auth.bootstrapping) {
        if (!_skipIntro) {
          _retryTimer?.cancel();
          _retryTimer = Timer(const Duration(milliseconds: 100), () {
            if (mounted) _scheduleNavigationCheck();
          });
        }
        return;
      }
      _retryTimer?.cancel();
      _navigated = true;

      final target = !auth.isAuthenticated ? '/login' : auth.authenticatedHome;

      try {
        context.go(target);
      } catch (_) {
        _navigated = false;
        if (!_skipIntro) {
          _retryTimer?.cancel();
          _retryTimer = Timer(const Duration(milliseconds: 200), () {
            if (mounted) _scheduleNavigationCheck();
          });
        }
        return;
      }
    } finally {
      _checkingNavigation = false;
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(authControllerProvider, (prev, next) {
      if (prev?.bootstrapping == true && !next.bootstrapping) {
        _scheduleNavigationCheck();
      }
    });

    return AnnotatedRegion<SystemUiOverlayStyle>(
      value: const SystemUiOverlayStyle(
        statusBarColor: Colors.transparent,
        statusBarIconBrightness: Brightness.dark,
        statusBarBrightness: Brightness.light,
        systemNavigationBarColor: Colors.white,
        systemNavigationBarIconBrightness: Brightness.dark,
      ),
      child: Scaffold(
        backgroundColor: Colors.white,
        body: AnimatedBuilder(
          animation: _intro,
          builder: (context, _) {
            return Stack(
              fit: StackFit.expand,
              children: [
                const ColoredBox(color: Colors.white),
                Center(
                  child: FittedBox(
                    fit: BoxFit.contain,
                    child: SizedBox(
                      width: _artW,
                      height: _artH,
                      child: Stack(
                        children: [
                          FadeTransition(
                            opacity: _posterFade,
                            child: RepaintBoundary(
                              child: Image.asset(
                                hasimSplashAsset,
                                key: const Key('hasim-splash-art'),
                                width: _artW,
                                height: _artH,
                                fit: BoxFit.fill,
                                filterQuality: FilterQuality.medium,
                                gaplessPlayback: true,
                              ),
                            ),
                          ),
                          Positioned.fill(
                            child: FadeTransition(
                              opacity: _posterFade,
                              child: Stack(
                                children: [
                                  const Positioned(
                                    left: 330,
                                    top: 1442,
                                    width: 292,
                                    height: 28,
                                    child: ColoredBox(color: Colors.white),
                                  ),
                                  Positioned(
                                    left: 337,
                                    top: 1447,
                                    width: 278,
                                    height: 18,
                                    child: _LoadingBar(
                                      progress: _progress.value,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                          if (_intro.value < 1) ...[
                            const Positioned(
                              left: 358,
                              top: 478,
                              width: 234,
                              height: 270,
                              child: ColoredBox(color: Colors.white),
                            ),
                            Positioned(
                              left: 358,
                              top: 478,
                              width: 234,
                              height: 270,
                              child: FadeTransition(
                                opacity: _markFade,
                                child: ScaleTransition(
                                  scale: _markScale,
                                  child: ClipRect(
                                    child: RotationTransition(
                                      turns: _markTurns,
                                      child: Image.asset(
                                        hasimSplashMarkAsset,
                                        width: 234,
                                        height: 270,
                                        fit: BoxFit.fill,
                                        filterQuality: FilterQuality.medium,
                                      ),
                                    ),
                                  ),
                                ),
                              ),
                            ),
                            ..._iconCovers(),
                          ],
                        ],
                      ),
                    ),
                  ),
                ),
              ],
            );
          },
        ),
      ),
    );
  }

  List<Widget> _iconCovers() {
    const covers = <_IconCover>[
      _IconCover.circle(0, 474, 361, 100),
      _IconCover.circle(1, 758, 519, 98),
      _IconCover.circle(2, 808, 754, 90),
      _IconCover.circle(3, 750, 930, 88),
      _IconCover.circle(4, 148, 860, 88),
      _IconCover.circle(4, 228, 918, 50),
      _IconCover.circle(5, 190, 518, 96),
    ];

    return [
      for (final cover in covers)
        Positioned(
          left: cover.left,
          top: cover.top,
          width: cover.width,
          height: cover.height,
          child: IgnorePointer(
            child: Opacity(
              opacity: (1 - _iconReveal[cover.reveal].value).clamp(0.0, 1.0),
              child: const DecoratedBox(
                decoration: BoxDecoration(
                  color: Colors.white,
                  shape: BoxShape.circle,
                ),
              ),
            ),
          ),
        ),
    ];
  }
}

class _IconCover {
  const _IconCover.circle(this.reveal, double cx, double cy, double r)
    : left = cx - r,
      top = cy - r,
      width = r * 2,
      height = r * 2;

  final int reveal;
  final double left;
  final double top;
  final double width;
  final double height;
}

class _LoadingBar extends StatelessWidget {
  const _LoadingBar({required this.progress});

  final double progress;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(9),
      child: ColoredBox(
        color: _barTrack,
        child: Align(
          alignment: Alignment.centerLeft,
          child: FractionallySizedBox(
            widthFactor: progress.clamp(0.0, 1.0),
            heightFactor: 1,
            child: const ColoredBox(color: _barFill),
          ),
        ),
      ),
    );
  }
}
