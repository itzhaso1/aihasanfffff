import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimPageHeader extends StatelessWidget {
  const HasimPageHeader({
    super.key,
    required this.title,
    this.subtitle,
  });

  final String title;
  final String? subtitle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: EdgeInsetsDirectional.fromSTEB(4, 4, 4, subtitle == null ? 4 : 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            title,
            textAlign: TextAlign.start,
            style: theme.textTheme.headlineSmall?.copyWith(
              fontWeight: FontWeight.w800,
              fontSize: 26,
              height: 1.25,
              color: HasimTokens.inkColor(context),
            ),
          ),
          if (subtitle != null) ...[
            const SizedBox(height: 4),
            Text(
              subtitle!,
              textAlign: TextAlign.start,
              style: theme.textTheme.bodyMedium?.copyWith(
                color: HasimTokens.mutedColor(context),
                fontWeight: FontWeight.w500,
                height: 1.45,
              ),
            ),
          ],
        ],
      ),
    );
  }
}
