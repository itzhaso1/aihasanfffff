import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';

/// موجات مينت خفيفة في أسفل الشاشة — زخرفة فقط.
class HasimWaveBackdrop extends StatelessWidget {
  const HasimWaveBackdrop({super.key, this.height = 120});

  final double height;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: SizedBox(
        height: height,
        width: double.infinity,
        child: CustomPaint(painter: _WavePainter(color: AppTheme.brand)),
      ),
    );
  }
}

class _WavePainter extends CustomPainter {
  _WavePainter({required this.color});

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final back = Path()
      ..moveTo(0, size.height * 0.42)
      ..cubicTo(size.width * 0.22, size.height * 0.08, size.width * 0.48, size.height * 0.72, size.width, size.height * 0.28)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(back, Paint()..color = color.withValues(alpha: 0.05));

    final front = Path()
      ..moveTo(0, size.height * 0.58)
      ..cubicTo(size.width * 0.28, size.height * 0.22, size.width * 0.62, size.height * 0.92, size.width, size.height * 0.46)
      ..lineTo(size.width, size.height)
      ..lineTo(0, size.height)
      ..close();
    canvas.drawPath(front, Paint()..color = color.withValues(alpha: 0.07));
  }

  @override
  bool shouldRepaint(covariant _WavePainter oldDelegate) => oldDelegate.color != color;
}
