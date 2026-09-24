import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimFeatureCard extends StatelessWidget {
  const HasimFeatureCard({
    super.key,
    required this.title,
    required this.icon,
    this.subtitle,
    this.tint,
    this.onTap,
  });

  final String title;
  final String? subtitle;
  final IconData icon;
  final Color? tint;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final bg = tint ?? HasimTokens.cardBg(context);
    final child = Padding(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 14),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: HasimTokens.isDark(context) ? 0.08 : 0.72),
              shape: BoxShape.circle,
            ),
            child: Icon(icon, size: 20, color: AppTheme.brand),
          ),
          const SizedBox(height: 8),
          Text(
            title,
            textAlign: TextAlign.center,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.labelMedium?.copyWith(
                  fontWeight: FontWeight.w800,
                  color: HasimTokens.inkColor(context),
                  height: 1.3,
                ),
          ),
          if (subtitle != null) ...[
            const SizedBox(height: 2),
            Text(
              subtitle!,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: Theme.of(context).textTheme.labelSmall?.copyWith(
                    color: HasimTokens.mutedColor(context),
                    fontWeight: FontWeight.w500,
                    height: 1.3,
                  ),
            ),
          ],
        ],
      ),
    );

    return DecoratedBox(
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        border: Border.all(color: HasimTokens.fieldBorder.withValues(alpha: 0.55)),
        boxShadow: HasimTokens.softShadow(context),
      ),
      child: Material(
        color: Colors.transparent,
        child: onTap == null
            ? child
            : InkWell(
                onTap: onTap,
                borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
                child: child,
              ),
      ),
    );
  }
}
