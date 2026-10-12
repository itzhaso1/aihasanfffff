import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimPrimaryButton extends StatelessWidget {
  const HasimPrimaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.width = 220,
    this.loading = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final double width;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: width,
      height: 46,
      child: FilledButton(
        onPressed: loading ? null : onPressed,
        style: FilledButton.styleFrom(
          backgroundColor: AppTheme.brand,
          disabledBackgroundColor: AppTheme.brand.withValues(alpha: 0.45),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          elevation: 0,
        ),
        child: Text(label),
      ),
    );
  }
}

class HasimSecondaryButton extends StatelessWidget {
  const HasimSecondaryButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.width = 220,
    this.loading = false,
  });

  final String label;
  final VoidCallback? onPressed;
  final double width;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: width,
      height: 46,
      child: OutlinedButton(
        onPressed: loading ? null : onPressed,
        style: OutlinedButton.styleFrom(
          foregroundColor: AppTheme.brand,
          side: BorderSide(color: AppTheme.brand.withValues(alpha: 0.55)),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
        child: Text(label),
      ),
    );
  }
}

class HasimTextLinkButton extends StatelessWidget {
  const HasimTextLinkButton({
    super.key,
    required this.label,
    required this.onPressed,
    this.icon,
    this.color,
  });

  final String label;
  final VoidCallback? onPressed;
  final IconData? icon;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final c = color ?? AppTheme.brand;
    return TextButton.icon(
      onPressed: onPressed,
      icon: Icon(icon ?? Icons.logout_rounded, size: 18, color: c),
      label: Text(label, style: TextStyle(color: c, fontWeight: FontWeight.w600)),
      style: TextButton.styleFrom(
        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
      ),
    );
  }
}

class HasimBadge extends StatelessWidget {
  const HasimBadge({super.key, required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: HasimTokens.primaryLight,
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: AppTheme.brand.withValues(alpha: 0.25)),
      ),
      child: Text(
        label,
        style: const TextStyle(
          color: AppTheme.brand,
          fontSize: 12,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}
