import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// رسمة الحالة الفارغة للتحديثات — كتاب مفتوح بألوان حاسم.
class UpdatesEmptyIllustration extends StatelessWidget {
  const UpdatesEmptyIllustration({super.key, this.size = 168});

  final double size;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size * 0.92,
      child: Stack(
        alignment: Alignment.center,
        children: [
          Container(
            width: size * 0.86,
            height: size * 0.86,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: HasimTokens.mintLight.withValues(alpha: 0.85),
            ),
          ),
          Icon(Icons.auto_stories_outlined, size: size * 0.42, color: AppTheme.brand.withValues(alpha: 0.88)),
          Positioned(
            top: size * 0.14,
            child: Icon(Icons.auto_awesome, size: size * 0.12, color: AppTheme.brand.withValues(alpha: 0.55)),
          ),
          Positioned(
            left: size * 0.18,
            top: size * 0.38,
            child: Icon(Icons.circle, size: 5, color: AppTheme.brand.withValues(alpha: 0.28)),
          ),
          Positioned(
            right: size * 0.16,
            top: size * 0.32,
            child: Icon(Icons.circle, size: 4, color: AppTheme.brand.withValues(alpha: 0.22)),
          ),
        ],
      ),
    );
  }
}
