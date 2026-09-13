import 'package:flutter/material.dart';

class HasimLogo extends StatelessWidget {
  const HasimLogo({
    super.key,
    this.size = 96,
    this.showWordmark = false,
  });

  final double size;
  final bool showWordmark;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Image.asset(
          'assets/branding/hasim_mark.png',
          width: size,
          height: size,
          fit: BoxFit.contain,
          errorBuilder: (_, _, _) => Icon(
            Icons.hexagon_rounded,
            size: size * 0.85,
            color: Theme.of(context).colorScheme.primary,
          ),
        ),
        if (showWordmark) ...[
          const SizedBox(height: 10),
          Text(
            'حاسم',
            style: Theme.of(context).textTheme.headlineMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: Theme.of(context).colorScheme.primary,
                ),
          ),
        ],
      ],
    );
  }
}
