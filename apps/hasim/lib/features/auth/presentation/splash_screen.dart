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
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen>
    with SingleTickerProviderStateMixin {
  bool _navigated = false;
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
      duration: const Duration(milliseconds: 2600),
    );
    _markFade = CurvedAnimation(
      parent: _intro,
      curve: const Interval(0.00, 0.16, curve: Curves.easeOut),
    );
    _markScale = Tween<double>(begin: 0.86, end: 1).animate(
      CurvedAnimation(
        parent: _intro,
        curve: const Interval(0.00, 0.18, curve: Curves.easeOut),
      ),
    );
    _markTurns = Tween<double>(begin: 0, end: 1).animate(
      CurvedAnimation(
        parent: _intro,
        curve: const Interval(0.18, 0.42, curve: Curves.easeInOut),
      ),
    );
    _posterFade = CurvedAnimation(
      parent: _intro,
      curve: const Interval(0.10, 0.30, curve: Curves.easeOut),
    );
    _progress = CurvedAnimation(
      parent: _intro,
      curve: const Interval(0.22, 0.92, curve: Curves.easeInOut),
    );
    _iconReveal = List<Animation<double>>.generate(6, (i) {
      final start = 0.36 + i * 0.055;
      return CurvedAnimation(
        parent: _intro,
        curve: Interval(
          start,
          (start + 0.12).clamp(0.0, 1.0),
          curve: Curves.easeOut,
        ),
      );
    });

    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      precacheImage(const AssetImage(hasimSplashAsset), context);
      precacheImage(const AssetImage(hasimSplashMarkAsset), context);
      await _intro.forward();
      _maybeNavigate();
    });
  }

  @override
  void dispose() {
    _intro.dispose();
    super.dispose();
  }

  Future<void> _maybeNavigate() async {
    if (_navigated || !mounted) return;
    if (_intro.status != AnimationStatus.completed) return;
    final auth = ref.read(authControllerProvider);
    if (auth.bootstrapping) return;
    _navigated = true;

    if (!auth.isAuthenticated) {
      context.go('/login');
    } else if (auth.workspace == null) {
      context.go('/workspaces');
    } else {
      context.go('/conversations');
    }
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(authControllerProvider, (prev, next) {
      if (prev?.bootstrapping == true && !next.bootstrapping) {
        _maybeNavigate();
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
                            child: Image.asset(
                              hasimSplashAsset,
                              key: const Key('hasim-splash-art'),
                              width: _artW,
                              height: _artH,
                              fit: BoxFit.fill,
                              filterQuality: FilterQuality.high,
                              gaplessPlayback: true,
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
                                        filterQuality: FilterQuality.high,
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
