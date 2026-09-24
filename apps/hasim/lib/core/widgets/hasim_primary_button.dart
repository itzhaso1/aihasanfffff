import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimPrimaryButton extends StatelessWidget {
  const HasimPrimaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.expanded = false,
    this.enabled = true,
    this.busy = false,
  });

  final String label;
  final VoidCallback onPressed;
  final IconData? icon;
  final bool expanded;
  final bool enabled;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final active = enabled && !busy;
    final child = Padding(
      padding: EdgeInsets.symmetric(horizontal: 26, vertical: expanded ? 16 : 14),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: expanded ? MainAxisSize.max : MainAxisSize.min,
          children: [
            if (busy) ...[
              const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
              ),
              const SizedBox(width: 10),
            ] else if (icon != null) ...[
              Icon(icon, size: 20, color: Colors.white),
              const SizedBox(width: 8),
            ],
            Text(
              label,
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: Colors.white,
                  ),
            ),
          ],
        ),
      ),
    );

    return DecoratedBox(
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(HasimTokens.radiusPill),
        boxShadow: active ? HasimTokens.buttonShadow(context) : const [],
      ),
      child: Material(
        color: active ? AppTheme.brand : AppTheme.brand.withValues(alpha: 0.38),
        elevation: 0,
        shape: const StadiumBorder(),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: active ? onPressed : null,
          customBorder: const StadiumBorder(),
          child: expanded ? SizedBox(width: double.infinity, child: child) : child,
        ),
      ),
    );
  }
}

class HasimOutlineButton extends StatelessWidget {
  const HasimOutlineButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.expanded = false,
    this.enabled = true,
    this.busy = false,
  });

  final String label;
  final VoidCallback onPressed;
  final IconData? icon;
  final bool expanded;
  final bool enabled;
  final bool busy;

  @override
  Widget build(BuildContext context) {
    final active = enabled && !busy;
    final child = Padding(
      padding: EdgeInsets.symmetric(horizontal: 26, vertical: expanded ? 16 : 14),
      child: FittedBox(
        fit: BoxFit.scaleDown,
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: expanded ? MainAxisSize.max : MainAxisSize.min,
          children: [
            if (busy) ...[
              const SizedBox(
                width: 18,
                height: 18,
                child: CircularProgressIndicator(strokeWidth: 2, color: AppTheme.brand),
              ),
              const SizedBox(width: 10),
            ] else if (icon != null) ...[
              Icon(icon, size: 20, color: AppTheme.brand),
              const SizedBox(width: 8),
            ],
            Text(
              label,
              style: Theme.of(context).textTheme.titleSmall?.copyWith(
                    fontWeight: FontWeight.w800,
                    color: AppTheme.brand,
                  ),
            ),
          ],
        ),
      ),
    );

    return Material(
      color: HasimTokens.cardBg(context),
      elevation: 0,
      shape: StadiumBorder(
        side: BorderSide(color: active ? AppTheme.brand : AppTheme.brand.withValues(alpha: 0.35)),
      ),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: active ? onPressed : null,
        customBorder: const StadiumBorder(),
        child: expanded ? SizedBox(width: double.infinity, child: child) : child,
      ),
    );
  }
}
