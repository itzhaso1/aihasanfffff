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
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 14),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: HasimTokens.mintFill(context),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(icon, size: 22, color: tint ?? AppTheme.brand),
              ),
              const SizedBox(height: 8),
              Text(
                title,
                textAlign: TextAlign.center,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.labelMedium?.copyWith(
                      fontWeight: FontWeight.w700,
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
        ),
      ),
    );
  }
}
