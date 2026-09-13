import 'package:flutter/material.dart';

import '../theme/hasim_colors.dart';
import '../theme/hasim_radius.dart';

/// Hasim Smart mark shown at splash and the top of login.
class HasimBrandLogo extends StatelessWidget {
  const HasimBrandLogo({
    super.key,
    this.width = 220,
    this.semanticLabel = 'حاسم الذكي',
  });

  final double width;
  final String semanticLabel;

  static const assetPath = 'assets/branding/hasim_smart_logo.jpg';

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: semanticLabel,
      image: true,
      child: Image.asset(
        assetPath,
        key: const ValueKey('hasim-smart-logo'),
        width: width,
        fit: BoxFit.contain,
        filterQuality: FilterQuality.high,
        errorBuilder: (context, error, stackTrace) {
          return Container(
            width: 88,
            height: 88,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(HasimRadius.xl),
              border: Border.all(color: HasimColors.border),
            ),
            child: const Text(
              'ح',
              style: TextStyle(
                fontSize: 42,
                fontWeight: FontWeight.w900,
                color: HasimColors.brand,
              ),
            ),
          );
        },
      ),
    );
  }
}
