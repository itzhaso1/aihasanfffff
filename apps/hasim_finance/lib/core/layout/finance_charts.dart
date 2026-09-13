import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:hasim_finance/core/theme/finance_tokens.dart';

class FinanceBarChart extends StatelessWidget {
  const FinanceBarChart({
    super.key,
    required this.series,
    this.height = 220,
    this.salesLabel = 'المبيعات',
    this.expensesLabel = 'المصروفات',
  });

  final List<({String label, double sales, double expenses})> series;
  final double height;
  final String salesLabel;
  final String expensesLabel;

  @override
  Widget build(BuildContext context) {
    if (series.isEmpty) {
      return SizedBox(height: height, child: const Center(child: Text('—')));
    }
    return Column(
      children: [
        SizedBox(
          height: height,
          width: double.infinity,
          child: CustomPaint(
            painter: _BarPainter(
              series: series,
              textDirection: Directionality.of(context),
              gridColor: context.hs.border,
              labelColor: context.hs.textMuted,
            ),
          ),
        ),
        const SizedBox(height: 10),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            _legendDot(context, FinanceTokens.brand, salesLabel),
            const SizedBox(width: 16),
            _legendDot(context, const Color(0xFF9AE6D5), expensesLabel),
          ],
        ),
      ],
    );
  }

  Widget _legendDot(BuildContext context, Color color, String label) {
    return Row(
      children: [
        Container(width: 10, height: 10, decoration: BoxDecoration(color: color, borderRadius: BorderRadius.circular(3))),
        const SizedBox(width: 6),
        Text(label, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: context.hs.textMuted)),
      ],
    );
  }
}

class _BarPainter extends CustomPainter {
  _BarPainter({
    required this.series,
    required this.textDirection,
    required this.gridColor,
    required this.labelColor,
  });

  final List<({String label, double sales, double expenses})> series;
  final TextDirection textDirection;
  final Color gridColor;
  final Color labelColor;

  @override
  void paint(Canvas canvas, Size size) {
    final maxValue = series.fold<double>(0, (m, row) => math.max(m, math.max(row.sales, row.expenses)));
    final chartMax = maxValue <= 0 ? 1.0 : maxValue * 1.15;
    const leftGutter = 36.0;
    const bottomGutter = 22.0;
    final chart = Rect.fromLTWH(leftGutter, 8, size.width - leftGutter - 8, size.height - bottomGutter - 8);

    final grid = Paint()
      ..color = gridColor
      ..strokeWidth = 1;
    for (var i = 0; i < 5; i++) {
      final y = chart.top + chart.height * i / 4;
      canvas.drawLine(Offset(chart.left, y), Offset(chart.right, y), grid);
    }

    final groupWidth = chart.width / series.length;
    final barWidth = math.min(18.0, groupWidth / 3.2);
    for (var i = 0; i < series.length; i++) {
      final row = series[i];
      final cx = chart.left + groupWidth * (i + 0.5);
      final salesH = chart.height * (row.sales / chartMax);
      final expH = chart.height * (row.expenses / chartMax);
      final salesRect = RRect.fromRectAndRadius(
        Rect.fromCenter(center: Offset(cx - barWidth * 0.65, chart.bottom - salesH / 2), width: barWidth, height: math.max(salesH, 1)),
        const Radius.circular(6),
      );
      final expRect = RRect.fromRectAndRadius(
        Rect.fromCenter(center: Offset(cx + barWidth * 0.65, chart.bottom - expH / 2), width: barWidth, height: math.max(expH, 1)),
        const Radius.circular(6),
      );
      canvas.drawRRect(salesRect, Paint()..color = FinanceTokens.brand);
      canvas.drawRRect(expRect, Paint()..color = const Color(0xFF9AE6D5));

      final tp = TextPainter(
        text: TextSpan(text: row.label, style: TextStyle(fontSize: 10, color: labelColor, fontWeight: FontWeight.w700)),
        textDirection: textDirection,
      )..layout(maxWidth: groupWidth);
      tp.paint(canvas, Offset(cx - tp.width / 2, size.height - tp.height));
    }
  }

  @override
  bool shouldRepaint(covariant _BarPainter oldDelegate) =>
      oldDelegate.series != series || oldDelegate.gridColor != gridColor || oldDelegate.labelColor != labelColor;
}

class FinanceDonutChart extends StatelessWidget {
  const FinanceDonutChart({
    super.key,
    required this.slices,
    required this.centerValue,
    this.centerLabel = 'إجمالي المبيعات',
    this.currency = 'ر.س',
  });

  final List<({String label, double value, Color color})> slices;
  final String centerValue;
  final String centerLabel;
  final String currency;

  @override
  Widget build(BuildContext context) {
    final total = slices.fold<double>(0, (sum, row) => sum + row.value);
    return LayoutBuilder(
      builder: (context, constraints) {
        final wide = constraints.maxWidth >= 280;
        final chart = SizedBox(
          width: 168,
          height: 168,
          child: Stack(
            alignment: Alignment.center,
            children: [
              CustomPaint(size: const Size(168, 168), painter: _DonutPainter(slices: slices, total: total, emptyColor: context.hs.border)),
              Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(centerValue, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                  Text(centerLabel, style: TextStyle(fontSize: 11, color: context.hs.textMuted)),
                  Text(currency, style: TextStyle(fontSize: 11, color: context.hs.textMuted)),
                ],
              ),
            ],
          ),
        );
        final legend = Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final slice in slices)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: [
                    Container(width: 8, height: 8, decoration: BoxDecoration(color: slice.color, shape: BoxShape.circle)),
                    const SizedBox(width: 8),
                    Expanded(child: Text(slice.label, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700))),
                    Text(
                      total <= 0 ? '0%' : '${((slice.value / total) * 100).round()}%',
                      style: TextStyle(fontSize: 12, color: context.hs.textMuted, fontWeight: FontWeight.w800),
                    ),
                  ],
                ),
              ),
          ],
        );
        if (!wide) {
          return Column(children: [chart, const SizedBox(height: 12), legend]);
        }
        return Row(
          children: [
            chart,
            const SizedBox(width: 16),
            Expanded(child: legend),
          ],
        );
      },
    );
  }
}

class _DonutPainter extends CustomPainter {
  _DonutPainter({required this.slices, required this.total, required this.emptyColor});

  final List<({String label, double value, Color color})> slices;
  final double total;
  final Color emptyColor;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Rect.fromCircle(center: size.center(Offset.zero), radius: size.shortestSide / 2 - 8);
    var start = -math.pi / 2;
    if (total <= 0) {
      canvas.drawArc(rect, 0, math.pi * 2, false, Paint()
        ..color = emptyColor
        ..style = PaintingStyle.stroke
        ..strokeWidth = 18
        ..strokeCap = StrokeCap.round);
      return;
    }
    for (final slice in slices) {
      final sweep = (slice.value / total) * math.pi * 2;
      canvas.drawArc(
        rect,
        start,
        sweep,
        false,
        Paint()
          ..color = slice.color
          ..style = PaintingStyle.stroke
          ..strokeWidth = 18
          ..strokeCap = StrokeCap.butt,
      );
      start += sweep;
    }
  }

  @override
  bool shouldRepaint(covariant _DonutPainter oldDelegate) =>
      oldDelegate.slices != slices || oldDelegate.emptyColor != emptyColor;
}
