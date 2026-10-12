import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';

/// رسمة الحالة الفارغة للمحادثات — صندوق وارد + ورقة + طائرة ورقية.
class InboxEmptyIllustration extends StatelessWidget {
  const InboxEmptyIllustration({super.key, this.size = 236});

  final double size;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size * 0.9,
      child: CustomPaint(painter: _InboxEmptyPainter(color: AppTheme.brand)),
    );
  }
}

class _InboxEmptyPainter extends CustomPainter {
  _InboxEmptyPainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final mint = Color.lerp(color, Colors.white, 0.82)!;
    final mintSoft = Color.lerp(color, Colors.white, 0.9)!;
    final leaf = Color.lerp(color, const Color(0xFF7BC9A8), 0.25)!;
    final leafDark = Color.lerp(color, const Color(0xFF2E8F73), 0.35)!;
    final tray = color;
    final trayDeep = Color.lerp(color, const Color(0xFF067E6B), 0.35)!;

    // خلفية سحابية ناعمة
    final blob = Paint()..color = mintSoft;
    canvas.drawOval(Rect.fromCenter(center: Offset(size.width * 0.50, size.height * 0.58), width: size.width * 0.86, height: size.height * 0.28), blob);
    canvas.drawOval(Rect.fromCenter(center: Offset(size.width * 0.28, size.height * 0.46), width: size.width * 0.42, height: size.height * 0.22), blob);
    canvas.drawOval(Rect.fromCenter(center: Offset(size.width * 0.72, size.height * 0.42), width: size.width * 0.38, height: size.height * 0.20), blob);

    canvas.drawOval(
      Rect.fromCenter(center: Offset(size.width * 0.50, size.height * 0.82), width: size.width * 0.52, height: size.height * 0.06),
      Paint()..color = color.withValues(alpha: 0.10),
    );

    final box = RRect.fromRectAndRadius(
      Rect.fromLTWH(size.width * 0.29, size.height * 0.58, size.width * 0.42, size.height * 0.22),
      Radius.circular(size.width * 0.04),
    );
    canvas.drawRRect(box, Paint()..color = tray);

    final inner = RRect.fromRectAndRadius(
      Rect.fromLTWH(size.width * 0.325, size.height * 0.605, size.width * 0.35, size.height * 0.10),
      Radius.circular(size.width * 0.025),
    );
    canvas.drawRRect(inner, Paint()..color = trayDeep);

    final front = Path()
      ..moveTo(size.width * 0.29, size.height * 0.68)
      ..lineTo(size.width * 0.71, size.height * 0.68)
      ..lineTo(size.width * 0.71, size.height * 0.78)
      ..arcToPoint(Offset(size.width * 0.67, size.height * 0.80), radius: Radius.circular(size.width * 0.04))
      ..lineTo(size.width * 0.33, size.height * 0.80)
      ..arcToPoint(Offset(size.width * 0.29, size.height * 0.78), radius: Radius.circular(size.width * 0.04))
      ..close();
    canvas.drawPath(front, Paint()..color = Color.lerp(tray, Colors.white, 0.08)!);

    _drawLeaf(canvas, Offset(size.width * 0.22, size.height * 0.62), size.width * 0.13, -0.7, leaf, leafDark);
    _drawLeaf(canvas, Offset(size.width * 0.16, size.height * 0.70), size.width * 0.11, -0.15, leafDark, leaf);
    _drawLeaf(canvas, Offset(size.width * 0.78, size.height * 0.64), size.width * 0.12, 0.85, leaf, leafDark);
    _drawLeaf(canvas, Offset(size.width * 0.84, size.height * 0.72), size.width * 0.10, 0.25, leafDark, leaf);

    // ورقة مائلة داخل الصندوق
    canvas.save();
    canvas.translate(size.width * 0.52, size.height * 0.48);
    canvas.rotate(-0.28);
    final paper = RRect.fromRectAndRadius(
      Rect.fromCenter(center: Offset.zero, width: size.width * 0.22, height: size.height * 0.30),
      Radius.circular(size.width * 0.025),
    );
    canvas.drawRRect(
      paper,
      Paint()
        ..color = Colors.white
        ..style = PaintingStyle.fill,
    );
    canvas.drawRRect(
      paper,
      Paint()
        ..color = mint
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.5,
    );
    final linePaint = Paint()
      ..color = color.withValues(alpha: 0.55)
      ..strokeWidth = 2.4
      ..strokeCap = StrokeCap.round;
    for (var i = 0; i < 3; i++) {
      final y = -size.height * 0.06 + i * size.height * 0.045;
      canvas.drawLine(Offset(-size.width * 0.06, y), Offset(size.width * 0.06, y), linePaint);
    }
    canvas.restore();

    // شرارات بجانب الورقة
    final spark = Paint()
      ..color = color
      ..strokeWidth = 2.2
      ..strokeCap = StrokeCap.round;
    canvas.drawLine(Offset(size.width * 0.34, size.height * 0.30), Offset(size.width * 0.34, size.height * 0.36), spark);
    canvas.drawLine(Offset(size.width * 0.31, size.height * 0.33), Offset(size.width * 0.37, size.height * 0.33), spark);
    canvas.drawLine(Offset(size.width * 0.38, size.height * 0.24), Offset(size.width * 0.38, size.height * 0.29), spark);
    canvas.drawLine(Offset(size.width * 0.36, size.height * 0.265), Offset(size.width * 0.40, size.height * 0.265), spark);

    // مسار متقطع نحو الطائرة
    final curve = Path()
      ..moveTo(size.width * 0.62, size.height * 0.38)
      ..cubicTo(
        size.width * 0.72,
        size.height * 0.28,
        size.width * 0.78,
        size.height * 0.22,
        size.width * 0.86,
        size.height * 0.16,
      );
    _drawDashedPath(canvas, curve, Paint()
      ..color = Color.lerp(color, Colors.white, 0.25)!
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..strokeCap = StrokeCap.round);

    _drawPlane(canvas, Offset(size.width * 0.90, size.height * 0.12), size.width * 0.11, color);
  }

  void _drawLeaf(Canvas canvas, Offset origin, double length, double angle, Color fill, Color vein) {
    canvas.save();
    canvas.translate(origin.dx, origin.dy);
    canvas.rotate(angle);
    final leaf = Path()
      ..moveTo(0, 0)
      ..quadraticBezierTo(length * 0.55, -length * 0.55, length, 0)
      ..quadraticBezierTo(length * 0.55, length * 0.55, 0, 0)
      ..close();
    canvas.drawPath(leaf, Paint()..color = fill);
    canvas.drawLine(
      Offset.zero,
      Offset(length * 0.78, 0),
      Paint()
        ..color = vein.withValues(alpha: 0.55)
        ..strokeWidth = 1.4,
    );
    canvas.restore();
  }

  void _drawPlane(Canvas canvas, Offset tip, double scale, Color fill) {
    canvas.save();
    canvas.translate(tip.dx, tip.dy);
    canvas.rotate(-0.55);
    final body = Path()
      ..moveTo(scale, 0)
      ..lineTo(-scale * 0.55, -scale * 0.38)
      ..lineTo(-scale * 0.15, 0)
      ..lineTo(-scale * 0.55, scale * 0.38)
      ..close();
    canvas.drawPath(body, Paint()..color = fill);
    final fold = Path()
      ..moveTo(-scale * 0.12, 0)
      ..lineTo(-scale * 0.55, scale * 0.38)
      ..lineTo(-scale * 0.08, scale * 0.12)
      ..close();
    canvas.drawPath(fold, Paint()..color = Color.lerp(fill, Colors.white, 0.28)!);
    canvas.restore();
  }

  void _drawDashedPath(Canvas canvas, Path path, Paint paint) {
    for (final metric in path.computeMetrics()) {
      var distance = 0.0;
      const dash = 7.0;
      const gap = 5.0;
      while (distance < metric.length) {
        final next = math.min(distance + dash, metric.length);
        canvas.drawPath(metric.extractPath(distance, next), paint);
        distance = next + gap;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _InboxEmptyPainter oldDelegate) => oldDelegate.color != color;
}
