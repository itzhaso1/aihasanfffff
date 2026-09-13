import 'package:flutter/material.dart';
import 'package:hasim_finance/core/layout/finance_chrome.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';

class KpiCard extends StatelessWidget {
  const KpiCard({
    super.key,
    required this.label,
    required this.value,
    this.hint,
    this.delta,
    this.direction = 0,
    this.icon = Icons.analytics_outlined,
    this.tone = FinanceIconTone.teal,
    this.compact = false,
    this.currency = 'ر.س',
    this.showCurrency = true,
  });

  final String label;
  final String value;
  final String? hint;
  final String? delta;
  final int direction;
  final IconData icon;
  final FinanceIconTone tone;
  final bool compact;
  final String currency;
  final bool showCurrency;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final p = context.hs;
    final deltaColor = direction > 0
        ? p.success
        : direction < 0
            ? p.danger
            : p.textMuted;
    return Container(
      padding: EdgeInsets.all(compact ? 14 : 16),
      decoration: FinanceTokens.card(context),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  label,
                  style: theme.textTheme.bodySmall?.copyWith(
                    fontWeight: FontWeight.w700,
                    color: p.textMuted,
                  ),
                ),
              ),
              FinanceIconBadge(icon: icon, tone: tone, size: compact ? 34 : 40),
            ],
          ),
          SizedBox(height: compact ? 10 : 14),
          Wrap(
            crossAxisAlignment: WrapCrossAlignment.end,
            spacing: 6,
            children: [
              Text(
                value,
                style: theme.textTheme.headlineSmall?.copyWith(
                  fontWeight: FontWeight.w800,
                  fontSize: compact ? 20 : 24,
                  height: 1,
                ),
              ),
              if (showCurrency)
                Padding(
                  padding: const EdgeInsets.only(bottom: 2),
                  child: Text(currency, style: theme.textTheme.bodySmall?.copyWith(fontWeight: FontWeight.w700)),
                ),
            ],
          ),
          if (hint != null || delta != null) ...[
            const SizedBox(height: 8),
            Row(
              children: [
                if (delta != null) ...[
                  Icon(
                    direction < 0 ? Icons.arrow_downward_rounded : Icons.arrow_upward_rounded,
                    size: 14,
                    color: deltaColor,
                  ),
                  const SizedBox(width: 2),
                  Text(
                    delta!,
                    style: TextStyle(color: deltaColor, fontWeight: FontWeight.w800, fontSize: 12),
                  ),
                  const SizedBox(width: 8),
                ],
                if (hint != null)
                  Expanded(
                    child: Text(
                      hint!,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.bodySmall?.copyWith(fontSize: 11),
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}

class KpiGrid extends StatelessWidget {
  const KpiGrid({super.key, required this.cards, this.minWidth = 220});

  final List<KpiCard> cards;
  final double minWidth;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final cols = (constraints.maxWidth / (minWidth + 12)).floor().clamp(1, cards.length);
        final width = cols == 1 ? constraints.maxWidth : (constraints.maxWidth - (12 * (cols - 1))) / cols;
        return Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            for (final card in cards) SizedBox(width: width, child: card),
          ],
        );
      },
    );
  }
}
