import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimMessageCard extends StatelessWidget {
  const HasimMessageCard({
    super.key,
    required this.title,
    required this.address,
    this.snippet,
    this.timeLabel,
    this.unread = false,
    this.starred = false,
    this.onTap,
    this.onStarTap,
  });

  final String title;
  final String address;
  final String? snippet;
  final String? timeLabel;
  final bool unread;
  final bool starred;
  final VoidCallback? onTap;
  final VoidCallback? onStarTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final ink = HasimTokens.inkColor(context);
    final muted = HasimTokens.mutedColor(context);

    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
        border: Border.all(color: HasimTokens.lineColor(context)),
        boxShadow: HasimTokens.softShadow(context),
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(HasimTokens.radiusCard),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 46,
                  height: 46,
                  decoration: BoxDecoration(
                    color: HasimTokens.mintFill(context),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    unread ? Icons.mark_email_unread_outlined : Icons.mail_outline_rounded,
                    color: AppTheme.brand,
                    size: 22,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textAlign: TextAlign.start,
                        style: theme.textTheme.titleSmall?.copyWith(
                          fontWeight: unread ? FontWeight.w800 : FontWeight.w700,
                          color: ink,
                          height: 1.3,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        address,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        textAlign: TextAlign.start,
                        textDirection: TextDirection.ltr,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: AppTheme.brandDark,
                          fontWeight: FontWeight.w600,
                          height: 1.35,
                        ),
                      ),
                      if (snippet != null && snippet!.isNotEmpty) ...[
                        const SizedBox(height: 4),
                        Text(
                          snippet!,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          textAlign: TextAlign.start,
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: muted,
                            height: 1.45,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
                const SizedBox(width: 8),
                Column(
                  children: [
                    IconButton(
                      tooltip: starred ? 'إزالة النجمة' : 'تمييز بنجمة',
                      visualDensity: VisualDensity.compact,
                      constraints: const BoxConstraints(minWidth: 40, minHeight: 40),
                      onPressed: onStarTap,
                      icon: Icon(
                        starred ? Icons.star_rounded : Icons.star_border_rounded,
                        color: starred ? const Color(0xFFE6B325) : muted,
                        size: 22,
                      ),
                    ),
                    if (timeLabel != null && timeLabel!.isNotEmpty)
                      Text(
                        timeLabel!,
                        style: theme.textTheme.labelSmall?.copyWith(
                          color: muted,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
