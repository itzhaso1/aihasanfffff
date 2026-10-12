import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';
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
    final iconBg = danger ? HasimTokens.dangerSoftColor(context) : HasimTokens.mintFill(context);
    final iconColor = danger ? HasimTokens.danger : HasimTokens.primary;

    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(8, 14, 12, 14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: GoogleFonts.cairo(
                        fontSize: 16,
                        fontWeight: FontWeight.w600,
                        height: 1.35,
                        color: ink,
                      ),
                    ),
                    if (subtitle != null) ...[
                      const SizedBox(height: 3),
                      Text(
                        subtitle!,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        textDirection: subtitleTextDirection,
                        style: GoogleFonts.cairo(
                          fontSize: 13,
                          fontWeight: FontWeight.w500,
                          height: 1.35,
                          color: muted,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
              const SizedBox(width: 14),
              Container(
                width: 40,
                height: 40,
                alignment: Alignment.center,
                decoration: BoxDecoration(
                  color: iconBg,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(icon, size: 21, color: iconColor),
              ),
              if (trailing != null) ...[
                const SizedBox(width: 8),
                trailing!,
              ] else if (showChevron && onTap != null) ...[
                const SizedBox(width: 4),
                Icon(
                  Icons.chevron_left_rounded,
                  color: danger ? HasimTokens.danger : HasimTokens.mutedColor(context),
                  size: 22,
                ),
              ],
            ],
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
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 28),
        children: children,
      ),
    );
  }
}
