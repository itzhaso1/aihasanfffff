import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// بطاقة SaaS موحّدة — استخدمها في الإعدادات واللوحات والقوائم.
class HasimCard extends StatelessWidget {
  const HasimCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(HasimTokens.spaceMd),
    this.margin,
    this.radius = HasimTokens.radiusMd,
    this.backgroundColor,
    this.borderColor,
    this.showShadow = true,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final EdgeInsetsGeometry? margin;
  final double radius;
  final Color? backgroundColor;
  final Color? borderColor;
  final bool showShadow;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: margin ?? EdgeInsets.zero,
      child: DecoratedBox(
        decoration: BoxDecoration(
          color: backgroundColor ?? HasimTokens.cardBg(context),
          borderRadius: BorderRadius.circular(radius),
          border: Border.all(color: borderColor ?? HasimTokens.lineColor(context)),
          boxShadow: showShadow ? HasimTokens.softShadow(context) : const [],
        ),
        child: Padding(padding: padding, child: child),
      ),
    );
  }
}
