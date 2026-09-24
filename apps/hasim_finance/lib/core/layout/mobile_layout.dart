import 'package:flutter/material.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';

/// Matches [FinanceShell] desktop breakpoint: below this width = phone/tablet shell.
class FinanceMobileLayout {
  FinanceMobileLayout._();

  static const double desktopMinWidth = 980;

  static bool isMobile(BuildContext context) =>
      MediaQuery.sizeOf(context).width < desktopMinWidth;

  static const double pageHorizontalPadding = 14;
  static const double cardGap = 10;
  static const double rowMinHeight = 52;
  static const double cardRadius = 12;
  static const double iconBoxSize = 40;

  static EdgeInsets pagePadding(BuildContext context) {
    if (!isMobile(context)) {
      return const EdgeInsets.fromLTRB(20, 8, 20, 28);
    }
    return const EdgeInsets.fromLTRB(pageHorizontalPadding, 4, pageHorizontalPadding, 20);
  }

  static EdgeInsets filterBarMargin(BuildContext context) {
    if (!isMobile(context)) {
      return const EdgeInsets.fromLTRB(20, 8, 20, 12);
    }
    return const EdgeInsets.fromLTRB(pageHorizontalPadding, 6, pageHorizontalPadding, 10);
  }

  static EdgeInsets listHorizontalPadding(BuildContext context) {
    if (!isMobile(context)) {
      return const EdgeInsets.fromLTRB(20, 0, 20, 24);
    }
    return const EdgeInsets.fromLTRB(pageHorizontalPadding, 0, pageHorizontalPadding, 20);
  }

  static BoxDecoration mobileSurfaceCard(BuildContext context, {Color? color, bool selected = false}) {
    final p = context.hs;
    return BoxDecoration(
      color: color ?? p.card,
      borderRadius: BorderRadius.circular(cardRadius),
      border: Border.all(color: selected ? FinanceTokens.brand.withValues(alpha: 0.35) : p.border),
      boxShadow: FinanceTokens.mobileSoftShadow,
    );
  }
}

/// WhatsApp-style tappable row: icon in soft teal box, title, optional subtitle, chevron.
class FinanceMobileNavRow extends StatelessWidget {
  const FinanceMobileNavRow({
    super.key,
    required this.icon,
    required this.title,
    this.subtitle,
    this.selected = false,
    this.showChevron = true,
    this.destructive = false,
    required this.onTap,
  });

  final IconData icon;
  final String title;
  final String? subtitle;
  final bool selected;
  final bool showChevron;
  final bool destructive;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final p = context.hs;
    final theme = Theme.of(context);
    final fg = destructive ? p.danger : p.text;
    final subFg = destructive ? p.danger.withValues(alpha: 0.85) : p.textMuted;
    final iconTone = destructive
        ? FinanceIconTone.red.themed(context)
        : FinanceIconTone.teal.themed(context);

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(FinanceMobileLayout.cardRadius),
        child: Ink(
          decoration: FinanceMobileLayout.mobileSurfaceCard(context, selected: selected),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: FinanceMobileLayout.rowMinHeight),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              child: Row(
                children: [
                  if (showChevron)
                    Icon(
                      Icons.chevron_left_rounded,
                      size: 22,
                      color: p.textFaint,
                    ),
                  if (showChevron) const SizedBox(width: 4),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Text(
                          title,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.titleSmall?.copyWith(
                            fontWeight: FontWeight.w700,
                            color: fg,
                            height: 1.25,
                          ),
                        ),
                        if (subtitle != null && subtitle!.trim().isNotEmpty) ...[
                          const SizedBox(height: 2),
                          Text(
                            subtitle!,
                            maxLines: 2,
                            overflow: TextOverflow.ellipsis,
                            style: theme.textTheme.bodySmall?.copyWith(color: subFg, height: 1.3),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(width: 12),
                  Container(
                    width: FinanceMobileLayout.iconBoxSize,
                    height: FinanceMobileLayout.iconBoxSize,
                    decoration: BoxDecoration(
                      color: iconTone.background,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Icon(icon, size: 20, color: iconTone.foreground),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Wraps arbitrary list/detail content in a mobile card; on desktop returns [child] unchanged.
class FinanceMobileCardShell extends StatelessWidget {
  const FinanceMobileCardShell({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
  });

  final Widget child;
  final EdgeInsets padding;

  @override
  Widget build(BuildContext context) {
    if (!FinanceMobileLayout.isMobile(context)) {
      return child;
    }
    return Container(
      width: double.infinity,
      decoration: FinanceMobileLayout.mobileSurfaceCard(context),
      child: Padding(padding: padding, child: child),
    );
  }
}
