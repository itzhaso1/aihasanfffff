import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimPageHeader extends StatelessWidget {
  const HasimPageHeader({
    super.key,
    required this.title,
    this.subtitle,
    this.icon,
    this.onBack,
  });

  final String title;
  final String? subtitle;
  final IconData? icon;
  final VoidCallback? onBack;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final titleBlock = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          maxLines: 2,
          overflow: TextOverflow.ellipsis,
          textAlign: TextAlign.start,
          style: theme.textTheme.headlineMedium?.copyWith(
            color: HasimTokens.inkColor(context),
          ),
        ),
        if (subtitle != null) ...[
          const SizedBox(height: 4),
          Text(
            subtitle!,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.start,
            style: theme.textTheme.bodyMedium?.copyWith(
              color: HasimTokens.mutedColor(context),
            ),
          ),
        ],
      ],
    );

    final leading = onBack != null
        ? Material(
            color: HasimTokens.mutedColor(context).withValues(alpha: 0.08),
            borderRadius: BorderRadius.circular(14),
            child: Tooltip(
              message: 'رجوع',
              child: InkWell(
                key: const Key('page-back'),
                onTap: onBack,
                borderRadius: BorderRadius.circular(14),
                child: SizedBox(
                  width: 44,
                  height: 44,
                  child: Icon(
                    Icons.arrow_forward_rounded,
                    color: HasimTokens.mutedColor(context),
                    size: 22,
                  ),
                ),
              ),
            ),
          )
        : icon == null
            ? null
            : Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: HasimTokens.mutedColor(context).withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(
                  icon,
                  color: HasimTokens.mutedColor(context),
                  size: 22,
                ),
              );

    return Padding(
      padding: EdgeInsetsDirectional.fromSTEB(4, 4, 4, subtitle == null ? 4 : 12),
      child: leading == null
          ? titleBlock
          : Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                leading,
                const SizedBox(width: 12),
                Expanded(child: titleBlock),
              ],
            ),
    );
  }
}
