import 'dart:math' as math;

import 'package:flutter/material.dart';

/// زاوية داخلية مقعّرة بين شريط العنوان وسكة التنقل.
/// الرسم محصور داخل المربع مع امتداد بكسل واحد لإخفاء طرف السكة.
class HasimInnerChromeCorner extends StatelessWidget {
  const HasimInnerChromeCorner({
    super.key,
    required this.color,
    required this.lineColor,
  });

  static const double radius = 4;

  final Color color;
  final Color lineColor;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: CustomPaint(
        size: const Size(radius + 1, radius),
        painter: _InnerChromeCornerPainter(color: color, lineColor: lineColor),
      ),
    );
  }
}

class _InnerChromeCornerPainter extends CustomPainter {
  const _InnerChromeCornerPainter({
    required this.color,
    required this.lineColor,
  });

  final Color color;
  final Color lineColor;

  @override
  void paint(Canvas canvas, Size size) {
    final radius = size.shortestSide;
    final circle = Rect.fromCircle(center: Offset(0, radius), radius: radius);
    final wedge = Path()
      ..moveTo(radius, 0)
      ..lineTo(radius, radius)
      ..arcTo(circle, 0, -math.pi / 2, false)
      ..close();

    canvas.save();
    canvas.clipRect(Offset.zero & size);
    canvas.drawPath(wedge, Paint()..color = color);
    canvas.drawRect(
      Rect.fromLTWH(radius, 0, size.width - radius, radius),
      Paint()..color = color,
    );
    canvas.drawPath(
      Path()..arcTo(circle, -math.pi / 2, math.pi / 2, false),
      Paint()
        ..color = lineColor
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1,
    );
    canvas.restore();
  }

  @override
  bool shouldRepaint(covariant _InnerChromeCornerPainter oldDelegate) {
    return oldDelegate.color != color || oldDelegate.lineColor != lineColor;
  }
}
