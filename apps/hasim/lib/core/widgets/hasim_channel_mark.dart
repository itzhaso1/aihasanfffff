import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

/// شعارات القنوات الرسمية بجودة فكتور مطابقة للهوية البصرية للعلامات التجارية.
class HasimChannelMark extends StatelessWidget {
  const HasimChannelMark({super.key, required this.channel, this.size = 28});

  final String channel;
  final double size;

  static Color colorFor(String channel) {
    return switch (channel) {
      'whatsapp' => const Color(0xFF25D366),
      'facebook_messenger' => const Color(0xFF0084FF),
      'instagram' => const Color(0xFFE1306C),
      'telegram' => const Color(0xFF2AABEE),
      'tiktok' => const Color(0xFF111111),
      'email' => const Color(0xFFEA4335),
      'web' => const Color(0xFF0F9F8F),
      'manual' => const Color(0xFF64748B),
      _ => const Color(0xFF64748B),
    };
  }

  bool get _squircle => channel == 'instagram' || channel == 'email';

  @override
  Widget build(BuildContext context) {
    final radius = _squircle ? size * 0.28 : size / 2;
    return ExcludeSemantics(
      child: Directionality(
        textDirection: TextDirection.ltr,
        child: SizedBox(
          width: size,
          height: size,
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(radius),
              boxShadow: [
                BoxShadow(
                  color: colorFor(channel).withValues(alpha: 0.28),
                  blurRadius: size * 0.16,
                  offset: Offset(0, size * 0.06),
                ),
              ],
            ),
            child: CustomPaint(
              painter: _HasimChannelMarkPainter(channel: channel),
            ),
          ),
        ),
      ),
    );
  }
}

class _HasimChannelMarkPainter extends CustomPainter {
  const _HasimChannelMarkPainter({required this.channel});

  final String channel;

  @override
  void paint(Canvas canvas, Size size) {
    final s = math.min(size.width, size.height);
    canvas.save();
    canvas.translate((size.width - s) / 2, (size.height - s) / 2);
    // معايرة نظام الإحداثيات على شبكة قياسية 24x24 viewBox
    canvas.scale(s / 24.0, s / 24.0);

    switch (channel) {
      case 'whatsapp':
        _whatsapp(canvas);
      case 'facebook_messenger':
        _messenger(canvas);
      case 'instagram':
        _instagram(canvas);
      case 'telegram':
        _telegram(canvas);
      case 'tiktok':
        _tiktok(canvas);
      case 'email':
        _email(canvas);
      case 'web':
        _web(canvas);
      default:
        _fallback(canvas);
    }
    canvas.restore();
  }

  Paint _fill(Color color) => Paint()
    ..isAntiAlias = true
    ..style = PaintingStyle.fill
    ..color = color;

  Paint _stroke(Color color, double width) => Paint()
    ..isAntiAlias = true
    ..style = PaintingStyle.stroke
    ..strokeJoin = StrokeJoin.round
    ..strokeCap = StrokeCap.round
    ..strokeWidth = width
    ..color = color;

  void _disk(Canvas canvas, Color color) {
    const c = Offset(12, 12);
    canvas.drawCircle(c, 12, _fill(color));
    canvas.drawCircle(
      c,
      12,
      Paint()
        ..isAntiAlias = true
        ..shader = ui.Gradient.linear(
          const Offset(12, 0),
          const Offset(12, 24),
          [
            const Color(0x28FFFFFF),
            const Color(0x00000000),
            const Color(0x18000000),
          ],
          const [0.0, 0.48, 1.0],
        ),
    );
  }

  /// واتساب الرسمي: قرص أخضر مع فقاعة محادثة بيضاء وهاتف واتساب المائل بالأبعاد المعتمدة عالمياً.
  void _whatsapp(Canvas canvas) {
    _disk(canvas, const Color(0xFF25D366));

    final bubble = Path()
      ..moveTo(12.0, 3.5)
      ..cubicTo(7.3, 3.5, 3.5, 7.3, 3.5, 12.0)
      ..cubicTo(3.5, 13.6, 3.95, 15.1, 4.75, 16.4)
      ..lineTo(3.4, 20.6)
      ..lineTo(7.7, 19.5)
      ..cubicTo(9.0, 20.2, 10.45, 20.6, 12.0, 20.6)
      ..cubicTo(16.7, 20.6, 20.5, 16.7, 20.5, 12.0)
      ..cubicTo(20.5, 7.3, 16.7, 3.5, 12.0, 3.5)
      ..close();
    canvas.drawPath(bubble, _fill(Colors.white));

    final phone = Path()
      ..moveTo(16.5, 14.1)
      ..cubicTo(16.25, 13.98, 15.03, 13.38, 14.8, 13.3)
      ..cubicTo(14.57, 13.21, 14.41, 13.17, 14.24, 13.42)
      ..cubicTo(14.07, 13.67, 13.59, 14.24, 13.45, 14.4)
      ..cubicTo(13.3, 14.57, 13.16, 14.59, 12.91, 14.46)
      ..cubicTo(12.66, 14.34, 11.85, 14.07, 10.89, 13.22)
      ..cubicTo(10.15, 12.55, 9.64, 11.73, 9.5, 11.48)
      ..cubicTo(9.35, 11.23, 9.48, 11.09, 9.61, 10.97)
      ..cubicTo(9.72, 10.86, 9.85, 10.68, 9.98, 10.54)
      ..cubicTo(10.1, 10.39, 10.15, 10.29, 10.23, 10.13)
      ..cubicTo(10.31, 9.96, 10.27, 9.81, 10.21, 9.69)
      ..cubicTo(10.15, 9.56, 9.65, 8.33, 9.44, 7.83)
      ..cubicTo(9.24, 7.34, 9.03, 7.41, 8.88, 7.4)
      ..cubicTo(8.73, 7.39, 8.57, 7.39, 8.4, 7.39)
      ..cubicTo(8.23, 7.39, 7.96, 7.45, 7.73, 7.71)
      ..cubicTo(7.5, 7.96, 6.85, 8.57, 6.85, 9.8)
      ..cubicTo(6.85, 11.04, 7.75, 12.23, 7.87, 12.4)
      ..cubicTo(8.0, 12.56, 9.64, 15.1, 12.15, 16.19)
      ..cubicTo(12.75, 16.45, 13.21, 16.6, 13.58, 16.72)
      ..cubicTo(14.18, 16.91, 14.73, 16.88, 15.16, 16.82)
      ..cubicTo(15.64, 16.75, 16.64, 16.22, 16.85, 15.64)
      ..cubicTo(17.06, 15.06, 17.06, 14.55, 17.0, 14.45)
      ..cubicTo(16.94, 14.34, 16.77, 14.28, 16.5, 14.1)
      ..close();
    canvas.drawPath(phone, _fill(const Color(0xFF25D366)));
  }

  /// ماسنجر الرسمي: تدرج Meta التدرجي مع فقاعة المحادثة وصاعقة البرق الرسمية.
  void _messenger(Canvas canvas) {
    const rect = Rect.fromLTWH(0, 0, 24, 24);
    final shader = const LinearGradient(
      begin: Alignment.topLeft,
      end: Alignment.bottomRight,
      colors: [
        Color(0xFF00C6FF),
        Color(0xFF0078FF),
        Color(0xFFA033FF),
        Color(0xFFFF5E3A),
      ],
      stops: [0.0, 0.35, 0.70, 1.0],
    ).createShader(rect);

    canvas.drawCircle(
      const Offset(12, 12),
      12,
      Paint()
        ..isAntiAlias = true
        ..shader = shader,
    );

    final bubble = Path()
      ..moveTo(12.0, 3.6)
      ..cubicTo(7.1, 3.6, 3.2, 7.3, 3.2, 11.9)
      ..cubicTo(3.2, 14.5, 4.5, 16.9, 6.6, 18.3)
      ..lineTo(6.6, 20.6)
      ..lineTo(8.8, 19.4)
      ..cubicTo(9.8, 19.8, 10.9, 20.0, 12.0, 20.0)
      ..cubicTo(16.9, 20.0, 20.8, 16.3, 20.8, 11.9)
      ..cubicTo(20.8, 7.3, 16.9, 3.6, 12.0, 3.6)
      ..close();

    final bolt = Path()
      ..moveTo(12.7, 14.4)
      ..lineTo(10.5, 12.0)
      ..lineTo(6.5, 14.4)
      ..lineTo(11.3, 9.2)
      ..lineTo(13.5, 11.6)
      ..lineTo(17.5, 9.2)
      ..close();

    canvas.drawPath(
      Path.combine(PathOperation.difference, bubble, bolt),
      _fill(Colors.white),
    );
  }

  /// إنستغرام الرسمي: تدرج 5 ألوان الأيقونة الأصلية مع كاميرا إنستغرام الدائرية.
  void _instagram(Canvas canvas) {
    const rect = Rect.fromLTWH(0.5, 0.5, 23, 23);
    final squircle = RRect.fromRectAndRadius(rect, const Radius.circular(6.8));
    canvas.drawRRect(
      squircle,
      Paint()
        ..isAntiAlias = true
        ..shader = const LinearGradient(
          begin: Alignment.bottomLeft,
          end: Alignment.topRight,
          colors: [
            Color(0xFFFCAF45),
            Color(0xFFF77737),
            Color(0xFFE1306C),
            Color(0xFFC13584),
            Color(0xFF833AB4),
          ],
          stops: [0.0, 0.25, 0.50, 0.75, 1.0],
        ).createShader(rect),
    );

    final white = _stroke(Colors.white, 1.7);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        const Rect.fromLTWH(5.4, 5.4, 13.2, 13.2),
        const Radius.circular(3.8),
      ),
      white,
    );
    canvas.drawCircle(const Offset(12, 12), 3.4, white);
    canvas.drawCircle(const Offset(15.9, 8.1), 0.95, _fill(Colors.white));
  }

  /// تلغرام الرسمي: قرص أزرق سماوي وطائرة الورق البيضاء.
  void _telegram(Canvas canvas) {
    _disk(canvas, const Color(0xFF2AABEE));
    final plane = Path()
      ..moveTo(4.6, 11.6)
      ..lineTo(18.8, 6.2)
      ..cubicTo(19.4, 6.0, 20.0, 6.4, 19.8, 7.0)
      ..lineTo(16.5, 18.2)
      ..cubicTo(16.3, 18.8, 15.6, 19.0, 15.1, 18.6)
      ..lineTo(11.6, 15.8)
      ..lineTo(9.6, 17.7)
      ..cubicTo(9.3, 18.0, 8.8, 17.8, 8.8, 17.3)
      ..lineTo(9.1, 14.2)
      ..lineTo(16.2, 8.4)
      ..cubicTo(16.5, 8.1, 16.2, 7.8, 15.8, 8.0)
      ..lineTo(7.6, 13.2)
      ..lineTo(4.6, 11.6)
      ..close();
    canvas.drawPath(plane, _fill(Colors.white));
  }

  /// تيك توك الرسمي: قرص داكن مع النوتة الموسيقية وأطياف السيان والأحمر.
  void _tiktok(Canvas canvas) {
    _disk(canvas, const Color(0xFF111111));
    canvas.save();
    canvas.clipPath(Path()..addOval(const Rect.fromLTWH(0, 0, 24, 24)));

    void note(Offset shift, Color color) {
      canvas.save();
      canvas.translate(shift.dx, shift.dy);
      final p = _stroke(color, 2.0);
      canvas.drawLine(const Offset(13.5, 7.2), const Offset(13.5, 15.4), p);
      canvas.drawArc(
        const Rect.fromLTWH(13.5, 5.2, 4.8, 4.4),
        math.pi * 0.9,
        math.pi * 0.8,
        false,
        p,
      );
      canvas.drawOval(const Rect.fromLTWH(8.8, 13.8, 4.8, 3.8), _fill(color));
      canvas.restore();
    }

    note(const Offset(-1.0, 0.5), const Color(0xFF25F4EE));
    note(const Offset(1.0, -0.5), const Color(0xFFFE2C55));
    note(Offset.zero, Colors.white);
    canvas.restore();
  }

  /// بريد (Google Gmail الرسمي): بطاقة بيضاء ناصعة مع شعار M بألوان Google Workspace الأربعة.
  void _email(Canvas canvas) {
    final card = RRect.fromRectAndRadius(
      const Rect.fromLTWH(0.6, 0.6, 22.8, 22.8),
      const Radius.circular(6.4),
    );
    canvas.drawRRect(card, _fill(Colors.white));
    canvas.drawRRect(card, _stroke(const Color(0x18000000), 0.5));

    // القائم الأيسر (أحمر)
    final leftLeg = Path()
      ..moveTo(4.2, 8.4)
      ..lineTo(7.4, 8.4)
      ..lineTo(7.4, 18.0)
      ..lineTo(5.6, 18.0)
      ..cubicTo(4.8, 18.0, 4.2, 17.4, 4.2, 16.6)
      ..close();
    canvas.drawPath(leftLeg, _fill(const Color(0xFFEA4335)));

    // القائم الأيمن (أخضر)
    final rightLeg = Path()
      ..moveTo(16.6, 8.4)
      ..lineTo(19.8, 8.4)
      ..lineTo(19.8, 16.6)
      ..cubicTo(19.8, 17.4, 19.2, 18.0, 18.4, 18.0)
      ..lineTo(16.6, 18.0)
      ..close();
    canvas.drawPath(rightLeg, _fill(const Color(0xFF34A853)));

    // الكتف الأيسر (أزرق)
    final leftFold = Path()
      ..moveTo(4.2, 8.4)
      ..cubicTo(4.2, 7.3, 4.8, 6.4, 5.8, 6.0)
      ..lineTo(12.0, 10.6)
      ..lineTo(9.6, 12.4)
      ..lineTo(4.2, 8.4)
      ..close();
    canvas.drawPath(leftFold, _fill(const Color(0xFF4285F4)));

    // الكتف الأيمن (أصفر)
    final rightFold = Path()
      ..moveTo(19.8, 8.4)
      ..cubicTo(19.8, 7.3, 19.2, 6.4, 18.2, 6.0)
      ..lineTo(12.0, 10.6)
      ..lineTo(14.4, 12.4)
      ..lineTo(19.8, 8.4)
      ..close();
    canvas.drawPath(rightFold, _fill(const Color(0xFFFBBC05)));

    // الجزء الأوسط (أحمر)
    final centerV = Path()
      ..moveTo(7.4, 8.4)
      ..lineTo(12.0, 11.9)
      ..lineTo(16.6, 8.4)
      ..lineTo(12.0, 13.8)
      ..close();
    canvas.drawPath(centerV, _fill(const Color(0xFFEA4335)));
  }

  /// ويب الرسمي: قرص تيل أنيق مع مجسّم الكرة الأرضية وخطوط الطول والعرض.
  void _web(Canvas canvas) {
    _disk(canvas, const Color(0xFF0F9F8F));
    const c = Offset(12, 12);
    final ring = _stroke(Colors.white, 1.4);
    canvas.drawCircle(c, 7.4, ring);
    canvas.drawOval(Rect.fromCenter(center: c, width: 7.2, height: 14.8), ring);
    canvas.drawLine(const Offset(4.6, 12), const Offset(19.4, 12), ring);

    // خطوط العرض المنحنية
    final upperLat = Path()
      ..moveTo(6.8, 8.5)
      ..quadraticBezierTo(12.0, 9.8, 17.2, 8.5);
    canvas.drawPath(upperLat, ring);

    final lowerLat = Path()
      ..moveTo(6.8, 15.5)
      ..quadraticBezierTo(12.0, 14.2, 17.2, 15.5);
    canvas.drawPath(lowerLat, ring);
  }

  void _fallback(Canvas canvas) {
    _disk(canvas, const Color(0xFF64748B));
    canvas.drawCircle(const Offset(12, 12), 3.8, _stroke(Colors.white, 1.6));
  }

  @override
  bool shouldRepaint(covariant _HasimChannelMarkPainter oldDelegate) {
    return oldDelegate.channel != channel;
  }
}
