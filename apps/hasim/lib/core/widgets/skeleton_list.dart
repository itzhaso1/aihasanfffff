import 'package:flutter/material.dart';

class SkeletonBox extends StatelessWidget {
  const SkeletonBox({
    super.key,
    this.height = 16,
    this.width,
    this.radius = 10,
  });

  final double height;
  final double? width;
  final double radius;

  @override
  Widget build(BuildContext context) {
    final base = Theme.of(context).brightness == Brightness.dark
        ? Colors.white12
        : Colors.black.withValues(alpha: 0.06);
    return Container(
      height: height,
      width: width,
      decoration: BoxDecoration(
        color: base,
        borderRadius: BorderRadius.circular(radius),
      ),
    );
  }
}

class SkeletonList extends StatelessWidget {
  const SkeletonList({super.key, this.itemCount = 6, this.padding});

  final int itemCount;
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    return ListView.separated(
      padding: padding ?? const EdgeInsets.all(16),
      itemCount: itemCount,
      separatorBuilder: (_, _) => const SizedBox(height: 12),
      itemBuilder: (context, index) {
        return Row(
          children: [
            const SkeletonBox(height: 48, width: 48, radius: 24),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SkeletonBox(height: 14, width: MediaQuery.sizeOf(context).width * 0.45),
                  const SizedBox(height: 8),
                  const SkeletonBox(height: 12, width: double.infinity),
                ],
              ),
            ),
          ],
        );
      },
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
