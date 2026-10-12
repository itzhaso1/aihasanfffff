import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

/// شعارات القنوات بشكل أيقونة التطبيق الأصلية: قرص/مربع ممتلئ مع الرسمة البيضاء.
class HasimChannelMark extends StatelessWidget {
  const HasimChannelMark({
    super.key,
    required this.channel,
    this.size = 28,
  });

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
                  color: colorFor(channel).withValues(alpha: 0.32),
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
    switch (channel) {
      case 'whatsapp':
        _whatsapp(canvas, s);
      case 'facebook_messenger':
        _messenger(canvas, s);
      case 'instagram':
        _instagram(canvas, s);
      case 'telegram':
        _telegram(canvas, s);
      case 'tiktok':
        _tiktok(canvas, s);
      case 'email':
        _email(canvas, s);
      case 'web':
        _web(canvas, s);
      default:
        _fallback(canvas, s);
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

  void _disk(Canvas canvas, double s, Color color) {
    final c = Offset(s / 2, s / 2);
    canvas.drawCircle(c, s / 2, _fill(color));
    canvas.drawCircle(
      c,
      s / 2,
      Paint()
        ..isAntiAlias = true
        ..shader = ui.Gradient.linear(
          Offset(s * 0.5, 0),
          Offset(s * 0.5, s),
          [
            const Color(0x2EFFFFFF),
            const Color(0x00000000),
            const Color(0x14000000),
          ],
          const [0.0, 0.48, 1.0],
        ),
    );
  }

  void _whatsapp(Canvas canvas, double s) {
    _disk(canvas, s, const Color(0xFF25D366));

    final bubble = Path()
      ..addOval(
        Rect.fromCenter(
          center: Offset(s * 0.54, s * 0.46),
          width: s * 0.58,
          height: s * 0.58,
        ),
      );
    final tail = Path()
      ..moveTo(s * 0.16, s * 0.84)
      ..quadraticBezierTo(s * 0.20, s * 0.62, s * 0.36, s * 0.70)
      ..lineTo(s * 0.27, s * 0.78)
      ..quadraticBezierTo(s * 0.18, s * 0.84, s * 0.16, s * 0.84)
      ..close();
    canvas.drawPath(
      Path.combine(PathOperation.union, bubble, tail),
      _fill(Colors.white),
    );

    canvas.save();
    canvas.translate(s * 0.54, s * 0.455);
    canvas.rotate(-0.58);
    final phone = _stroke(const Color(0xFF25D366), s * 0.11);
    canvas.drawArc(
      Rect.fromCenter(center: Offset.zero, width: s * 0.33, height: s * 0.40),
      0.72,
      1.70,
      false,
      phone,
    );
    canvas.drawLine(
      Offset(s * 0.015, s * 0.155),
      Offset(s * 0.11, s * 0.20),
      phone,
    );
    canvas.drawLine(
      Offset(s * 0.015, -s * 0.155),
      Offset(s * 0.11, -s * 0.20),
      phone,
    );
    canvas.restore();
  }

  void _messenger(Canvas canvas, double s) {
    final rect = Rect.fromLTWH(0, 0, s, s);
    canvas.drawCircle(
      Offset(s / 2, s / 2),
      s / 2,
      Paint()
        ..isAntiAlias = true
        ..shader = const LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [
            Color(0xFF00C6FF),
            Color(0xFF0078FF),
            Color(0xFFA033FF),
            Color(0xFFFF45A8),
          ],
          stops: [0.0, 0.38, 0.68, 1.0],
        ).createShader(rect),
    );

    final bubble = Path()
      ..addRRect(
        RRect.fromRectAndRadius(
          Rect.fromCenter(
            center: Offset(s * 0.50, s * 0.46),
            width: s * 0.62,
            height: s * 0.54,
          ),
          Radius.circular(s * 0.27),
        ),
      );
    final tail = Path()
      ..moveTo(s * 0.20, s * 0.84)
      ..quadraticBezierTo(s * 0.26, s * 0.64, s * 0.40, s * 0.70)
      ..lineTo(s * 0.30, s * 0.78)
      ..close();
    final white = Path.combine(PathOperation.union, bubble, tail);

    final bolt = Path()
      ..moveTo(s * 0.57, s * 0.26)
      ..lineTo(s * 0.34, s * 0.51)
      ..lineTo(s * 0.48, s * 0.51)
      ..lineTo(s * 0.41, s * 0.72)
      ..lineTo(s * 0.68, s * 0.44)
      ..lineTo(s * 0.53, s * 0.44)
      ..close();

    canvas.drawPath(
      Path.combine(PathOperation.difference, white, bolt),
      _fill(Colors.white),
    );
  }

  void _instagram(Canvas canvas, double s) {
    final pad = s * 0.02;
    final rect = Rect.fromLTWH(pad, pad, s - pad * 2, s - pad * 2);
    final squircle = RRect.fromRectAndRadius(rect, Radius.circular(s * 0.28));
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

    final white = _stroke(Colors.white, s * 0.068);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(
          center: Offset(s / 2, s / 2),
          width: s * 0.50,
          height: s * 0.50,
        ),
        Radius.circular(s * 0.14),
      ),
      white,
    );
    canvas.drawCircle(Offset(s / 2, s / 2), s * 0.135, white);
    canvas.drawCircle(Offset(s * 0.66, s * 0.355), s * 0.038, _fill(Colors.white));
  }

  void _telegram(Canvas canvas, double s) {
    _disk(canvas, s, const Color(0xFF2AABEE));
    final plane = Path()
      ..moveTo(s * 0.18, s * 0.49)
      ..lineTo(s * 0.82, s * 0.22)
      ..lineTo(s * 0.40, s * 0.55)
      ..close()
      ..moveTo(s * 0.40, s * 0.55)
      ..lineTo(s * 0.82, s * 0.22)
      ..lineTo(s * 0.54, s * 0.78)
      ..lineTo(s * 0.46, s * 0.58)
      ..close();
    canvas.drawPath(plane, _fill(Colors.white));
    canvas.drawLine(
      Offset(s * 0.40, s * 0.55),
      Offset(s * 0.58, s * 0.47),
      _stroke(const Color(0xFF2AABEE), s * 0.045),
    );
  }

  void _tiktok(Canvas canvas, double s) {
    _disk(canvas, s, const Color(0xFF111111));
    canvas.save();
    canvas.clipPath(
      Path()..addOval(Rect.fromCircle(center: Offset(s / 2, s / 2), radius: s / 2)),
    );

    void note(Offset shift, Color color, {double width = 0.085}) {
      canvas.save();
      canvas.translate(shift.dx, shift.dy);
      final p = _stroke(color, s * width);
      canvas.drawLine(Offset(s * 0.56, s * 0.30), Offset(s * 0.56, s * 0.64), p);
      canvas.drawArc(
        Rect.fromCenter(
          center: Offset(s * 0.62, s * 0.34),
          width: s * 0.20,
          height: s * 0.16,
        ),
        math.pi,
        math.pi * 0.9,
        false,
        p,
      );
      canvas.drawOval(
        Rect.fromCenter(
          center: Offset(s * 0.44, s * 0.68),
          width: s * 0.20,
          height: s * 0.15,
        ),
        _fill(color),
      );
      canvas.restore();
    }

    note(Offset(-s * 0.05, s * 0.02), const Color(0xFF25F4EE));
    note(Offset(s * 0.05, -s * 0.02), const Color(0xFFFE2C55));
    note(Offset.zero, Colors.white, width: 0.08);
    canvas.restore();
  }

  void _email(Canvas canvas, double s) {
    final pad = s * 0.03;
    final rect = Rect.fromLTWH(pad, pad, s - pad * 2, s - pad * 2);
    final card = RRect.fromRectAndRadius(rect, Radius.circular(s * 0.22));
    canvas.drawRRect(card, _fill(Colors.white));
    canvas.drawRRect(
      card,
      _stroke(const Color(0x14000000), s * 0.02),
    );

    final l = Offset(s * 0.22, s * 0.30);
    final lb = Offset(s * 0.22, s * 0.72);
    final c = Offset(s * 0.50, s * 0.54);
    final r = Offset(s * 0.78, s * 0.30);
    final rb = Offset(s * 0.78, s * 0.72);
    final w = s * 0.095;

    canvas.drawLine(r, rb, _stroke(const Color(0xFFFBBC05), w));
    canvas.drawLine(r, c, _stroke(const Color(0xFFFBBC05), w));
    canvas.drawLine(l, lb, _stroke(const Color(0xFFEA4335), w));
    canvas.drawLine(l, c, _stroke(const Color(0xFFEA4335), w));

    final inner = s * 0.055;
    canvas.drawLine(
      Offset(s * 0.30, s * 0.36),
      c,
      _stroke(const Color(0xFF4285F4), inner),
    );
    canvas.drawLine(
      Offset(s * 0.70, s * 0.36),
      c,
      _stroke(const Color(0xFF34A853), inner),
    );
  }

  void _web(Canvas canvas, double s) {
    _disk(canvas, s, const Color(0xFF0F9F8F));
    final c = Offset(s / 2, s / 2);
    final ring = _stroke(Colors.white, s * 0.058);
    canvas.drawCircle(c, s * 0.30, ring);
    canvas.drawOval(
      Rect.fromCenter(center: c, width: s * 0.26, height: s * 0.60),
      ring,
    );
    canvas.drawLine(Offset(s * 0.22, s * 0.50), Offset(s * 0.78, s * 0.50), ring);
    canvas.drawLine(Offset(s * 0.26, s * 0.36), Offset(s * 0.74, s * 0.36), ring);
    canvas.drawLine(Offset(s * 0.26, s * 0.64), Offset(s * 0.74, s * 0.64), ring);
  }

  void _fallback(Canvas canvas, double s) {
    _disk(canvas, s, const Color(0xFF64748B));
    canvas.drawCircle(
      Offset(s / 2, s / 2),
      s * 0.16,
      _stroke(Colors.white, s * 0.07),
    );
  }

  @override
  bool shouldRepaint(covariant _HasimChannelMarkPainter oldDelegate) {
    return oldDelegate.channel != channel;
  }
}
