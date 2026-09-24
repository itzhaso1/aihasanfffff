import 'package:flutter/material.dart';
import 'package:hasim/core/widgets/hasim_primary_button.dart';

class EmptyState extends StatelessWidget {
  const EmptyState({
    super.key,
    required this.title,
    this.subtitle,
    this.icon = Icons.inbox_outlined,
    this.illustration,
    this.actionLabel,
    this.onAction,
    this.actionIcon,
    this.pillAction = false,
  });

  final String title;
  final String? subtitle;
  final IconData icon;
  final Widget? illustration;
  final String? actionLabel;
  final VoidCallback? onAction;
  final IconData? actionIcon;
  final bool pillAction;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurface.withValues(alpha: 0.55);
    final emphasized = illustration != null || pillAction;
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 16),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (illustration != null)
              illustration!
            else
              Icon(icon, size: 52, color: muted),
            SizedBox(height: emphasized ? 18 : 14),
            Text(
              title,
              textAlign: TextAlign.center,
              style: emphasized
                  ? theme.textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w800,
                      fontSize: 22,
                      color: theme.colorScheme.onSurface,
                    )
                  : theme.textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
            ),
            if (subtitle != null) ...[
              SizedBox(height: emphasized ? 8 : 6),
              Text(
                subtitle!,
                textAlign: TextAlign.center,
                style: TextStyle(color: muted, height: 1.55, fontSize: emphasized ? 14 : null),
              ),
            ],
            if (actionLabel != null && onAction != null) ...[
              SizedBox(height: emphasized ? 22 : 16),
              pillAction
                  ? HasimPrimaryButton(label: actionLabel!, onPressed: onAction!, icon: actionIcon)
                  : FilledButton(onPressed: onAction, child: Text(actionLabel!)),
            ],
          ],
        ),
      ),
    );
  }
}
