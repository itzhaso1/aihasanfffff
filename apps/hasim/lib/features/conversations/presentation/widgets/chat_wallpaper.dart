import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';
import 'package:hasim/features/conversations/providers/chat_wallpaper_controller.dart';

/// خلفية شاشة المحادثة — نمط الدودل المتكرر من السمات.
class ChatWallpaper extends ConsumerWidget {
  const ChatWallpaper({super.key});

  /// كثافة تكرار النمط (أعلى = أيقونات أصغر؛ أقل = دودل أكبر مثل المرجع).
  static const double patternScale = 1.35;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final wallpaperId = ref.watch(chatWallpaperControllerProvider);
    final option = ChatWallpaperCatalog.find(wallpaperId);
    final overlayAlpha = option?.tile == true ? 0.06 : 0.04;
    return SizedBox.expand(
      child: Stack(
        fit: StackFit.expand,
        children: [
          DecoratedBox(
            decoration: BoxDecoration(
              color: HasimTokens.chatCanvas(context),
              image: ChatWallpaperCatalog.decorationImage(
                wallpaperId,
                patternScale: patternScale,
                opacity: option?.tile == true ? 0.88 : 1,
              ),
            ),
          ),
          ColoredBox(color: HasimTokens.chatCanvas(context).withValues(alpha: overlayAlpha)),
        ],
      ),
    );
  }
}

/// احتياطي عند فشل تحميل الصورة — نفس أسلوب الدودل المرسوم.
class ChatDoodleWallpaper extends StatelessWidget {
  const ChatDoodleWallpaper({super.key});

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    return ColoredBox(
      color: HasimTokens.chatCanvas(context),
      child: CustomPaint(
        painter: _DoodlePainter(
          color: (dark ? Colors.white : const Color(0xFF8D8376)).withValues(alpha: dark ? 0.035 : 0.09),
        ),
        child: const SizedBox.expand(),
      ),
    );
  }
}

class _DoodlePainter extends CustomPainter {
  const _DoodlePainter({required this.color});

  final Color color;

  static const _icons = <IconData>[
    Icons.chat_bubble_outline_rounded,
    Icons.photo_camera_outlined,
    Icons.sentiment_satisfied_alt_outlined,
    Icons.phone_outlined,
    Icons.star_outline_rounded,
    Icons.headphones_outlined,
    Icons.favorite_border_rounded,
    Icons.music_note_outlined,
  ];

  @override
  void paint(Canvas canvas, Size size) {
    const cell = 92.0;
    final paint = Paint()..color = color;
    final textPainter = TextPainter(textDirection: TextDirection.ltr);
    var index = 0;
    for (var y = 8.0; y < size.height; y += cell) {
      for (var x = 10.0; x < size.width; x += cell) {
        final icon = _icons[index % _icons.length];
        index++;
        textPainter.text = TextSpan(
          text: String.fromCharCode(icon.codePoint),
          style: TextStyle(
            fontSize: 18,
            fontFamily: icon.fontFamily,
            package: icon.fontPackage,
            color: color,
          ),
        );
        textPainter.layout();
        canvas.save();
        canvas.translate(x, y);
        canvas.rotate((index.isEven ? -1 : 1) * math.pi / 18);
        textPainter.paint(canvas, Offset.zero);
        canvas.restore();
        paint.color = color;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _DoodlePainter oldDelegate) => oldDelegate.color != color;
}
