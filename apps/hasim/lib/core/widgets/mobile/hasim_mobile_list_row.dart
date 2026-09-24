import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// صف قائمة للموبايل فقط — أسلوب بسيط (WhatsApp-like). لا تستخدمه في Desktop.
class HasimMobileListRow extends StatelessWidget {
  const HasimMobileListRow({
    super.key,
    required this.title,
    this.subtitle,
    this.subtitleTextDirection,
    required this.icon,
    this.onTap,
    this.danger = false,
    this.showChevron = true,
    this.trailing,
  });

  final String title;
  final String? subtitle;
  final TextDirection? subtitleTextDirection;
  final IconData icon;
  final VoidCallback? onTap;
  final bool danger;
  final bool showChevron;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final ink = danger ? HasimTokens.danger : HasimTokens.inkColor(context);
    final muted = danger ? HasimTokens.danger.withValues(alpha: 0.85) : HasimTokens.mutedColor(context);
    final bg = danger ? HasimTokens.dangerSoft : HasimTokens.cardBg(context);
    final border = danger ? HasimTokens.danger.withValues(alpha: 0.2) : HasimTokens.lineColor(context);
    final iconBg = danger ? HasimTokens.danger.withValues(alpha: 0.12) : AppTheme.brand.withValues(alpha: 0.12);
    final iconColor = danger ? HasimTokens.danger : AppTheme.brand;

    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Material(
        color: bg,
        elevation: 0,
        shadowColor: Colors.transparent,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(12),
          side: BorderSide(color: border),
        ),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(12),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Container(
                  width: 40,
                  height: 40,
                  decoration: BoxDecoration(
                    color: iconBg,
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Icon(icon, size: 22, color: iconColor),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: TextStyle(
                          fontSize: 16,
                          fontWeight: FontWeight.w600,
                          height: 1.25,
                          color: ink,
                        ),
                      ),
                      if (subtitle != null) ...[
                        const SizedBox(height: 4),
                        Text(
                          subtitle!,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          textDirection: subtitleTextDirection,
                          style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w500,
                            height: 1.3,
                            color: muted,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                if (trailing != null) ...[
                  const SizedBox(width: 8),
                  trailing!,
                ] else if (showChevron && onTap != null)
                  Icon(
                    Icons.chevron_left_rounded,
                    color: danger ? HasimTokens.danger : HasimTokens.mutedColor(context),
                    size: 26,
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// غلاف قائمة موبايل — padding + خلفية mint.
class HasimMobileListShell extends StatelessWidget {
  const HasimMobileListShell({super.key, required this.children});

  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return ColoredBox(
      color: HasimTokens.pageBg(context),
      child: ListView(
        padding: const EdgeInsets.fromLTRB(12, 8, 12, 24),
        children: children,
      ),
    );
  }
}
