import 'package:flutter/material.dart';

class SkeletonBox extends StatelessWidget {
  const SkeletonBox({
    super.key,
    this.height = 16,
    this.width,
    this.radius = 10,
    this.color,
  });

  final double height;
  final double? width;
  final double radius;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final base =
        color ??
        (Theme.of(context).brightness == Brightness.dark
            ? Colors.white12
            : Colors.black.withValues(alpha: 0.06));
    return DecoratedBox(
      decoration: BoxDecoration(
        color: base,
        borderRadius: BorderRadius.circular(radius),
      ),
      child: SizedBox(height: height, width: width),
    );
  }
}

class SkeletonList extends StatefulWidget {
  const SkeletonList({super.key, this.itemCount = 6, this.padding});

  final int itemCount;
  final EdgeInsetsGeometry? padding;

  @override
  State<SkeletonList> createState() => _SkeletonListState();
}

class _SkeletonListState extends State<SkeletonList>
    with SingleTickerProviderStateMixin {
  late final AnimationController _pulse;
  bool _animating = false;

  @override
  void initState() {
    super.initState();
    _pulse = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1100),
    );
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final disableAnimations =
        MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    if (disableAnimations && _animating) {
      _pulse.stop();
      _animating = false;
    } else if (!disableAnimations && !_animating) {
      _animating = true;
      _pulse.repeat(reverse: true);
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final titleWidth = MediaQuery.sizeOf(context).width * 0.45;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final fromColor = dark
        ? Colors.white.withValues(alpha: 0.08)
        : Colors.black.withValues(alpha: 0.045);
    final toColor = dark
        ? Colors.white.withValues(alpha: 0.15)
        : Colors.black.withValues(alpha: 0.09);

    return RepaintBoundary(
      child: AnimatedBuilder(
        animation: _pulse,
        builder: (context, _) {
          final fill = Color.lerp(fromColor, toColor, _pulse.value) ?? fromColor;
          return ListView.separated(
            padding: widget.padding ?? const EdgeInsets.all(16),
            itemCount: widget.itemCount,
            separatorBuilder: (_, _) => const SizedBox(height: 12),
            itemBuilder: (context, index) {
              return Row(
                children: [
                  SkeletonBox(height: 48, width: 48, radius: 24, color: fill),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        SkeletonBox(height: 14, width: titleWidth, color: fill),
                        const SizedBox(height: 8),
                        SkeletonBox(
                          height: 12,
                          width: double.infinity,
                          color: fill,
                        ),
                      ],
                    ),
                  ),
                ],
              );
            },
          );
        },
      ),
    );
  }
}

class SkeletonCards extends StatelessWidget {
  const SkeletonCards({super.key});

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SkeletonBox(height: 28, width: 180),
          const SizedBox(height: 8),
          const SkeletonBox(height: 14, width: 240),
          const SizedBox(height: 20),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: List.generate(
              4,
              (_) => const SkeletonBox(height: 72, width: 150, radius: 16),
            ),
          ),
          const SizedBox(height: 24),
          const SkeletonBox(height: 18, width: 140),
          const SizedBox(height: 12),
          for (var i = 0; i < 4; i++) ...[
            if (i > 0) const SizedBox(height: 12),
            const Row(
              children: [
                SkeletonBox(height: 48, width: 48, radius: 24),
                SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      SkeletonBox(height: 14, width: 140),
                      SizedBox(height: 8),
                      SkeletonBox(height: 12, width: double.infinity),
                    ],
                  ),
                ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
