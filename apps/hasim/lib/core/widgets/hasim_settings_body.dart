import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// محتوى صفحة إعدادات (مضمّنة أو مستقلة) — عرض أقصى ومسافات موحّدة.
class HasimSettingsBody extends StatelessWidget {
  const HasimSettingsBody({
    super.key,
    required this.children,
    this.padding,
    this.mobileLayout = false,
    this.backgroundColor,
  });

  final List<Widget> children;
  final EdgeInsetsGeometry? padding;
  final Color? backgroundColor;

  /// تخطيط موبايل فقط — عرض كامل ومسافات أوسع للمس.
  final bool mobileLayout;

  @override
  Widget build(BuildContext context) {
    final list = ListView(
      padding:
          padding ??
          (mobileLayout
              ? const EdgeInsets.fromLTRB(16, 14, 16, 32)
              : const EdgeInsets.fromLTRB(24, 16, 24, HasimTokens.spaceXl)),
      children: children,
    );

    return ColoredBox(
      color: backgroundColor ?? HasimTokens.settingsCanvasBg(context),
      child: mobileLayout
          ? list
          : Align(
              alignment: Alignment.topCenter,
              child: ConstrainedBox(
                constraints: const BoxConstraints(
                  maxWidth: HasimTokens.settingsContentMaxWidth,
                ),
                child: list,
              ),
            ),
    );
  }
}

/// رأس قسم موحّد داخل بطاقات الإعدادات.
class HasimSettingsSectionHeader extends StatelessWidget {
  const HasimSettingsSectionHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.icon,
    this.trailing,
    this.accentColor,
  });

  final String title;
  final String? subtitle;
  final IconData? icon;
  final Widget? trailing;
  final Color? accentColor;

  @override
  Widget build(BuildContext context) {
    final accent = accentColor ?? HasimTokens.primary;
    final dark = HasimTokens.isDark(context);
    return Row(
      crossAxisAlignment: CrossAxisAlignment.center,
      children: [
        if (icon != null) ...[
          Container(
            width: 36,
            height: 36,
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: dark ? 0.16 : 0.10),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, size: 19, color: accent),
          ),
          const SizedBox(width: 12),
        ],
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: Theme.of(context).textTheme.titleMedium?.copyWith(
                  fontWeight: FontWeight.w700,
                  color: HasimTokens.inkColor(context),
                ),
              ),
              if (subtitle != null && subtitle!.isNotEmpty) ...[
                const SizedBox(height: 2),
                Text(
                  subtitle!,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(
                    color: HasimTokens.mutedColor(context),
                  ),
                ),
              ],
            ],
          ),
        ),
        if (trailing != null) ...[
          const SizedBox(width: 8),
          trailing!,
        ],
      ],
    );
  }
}
