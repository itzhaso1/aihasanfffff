import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// قائمة مرفقات عائمة (سطح المكتب) — مضغوطة مع سهم للزر.
class ChatAttachmentMenuPanel extends StatelessWidget {
  const ChatAttachmentMenuPanel({
    super.key,
    required this.onSelected,
    required this.arrowOffset,
    this.maxHeight,
  });

  static const double panelWidth = 246;
  static const double arrowHalfWidth = 7;
  static const double arrowHeight = 8;

  final ValueChanged<String> onSelected;
  final double arrowOffset;
  final double? maxHeight;

  static const _document = _WaItem('document', 'مستند', Icons.description_outlined, Color(0xFF7F66FF));
  static const _photos = _WaItem(
    'photos_videos',
    'الصور ومقاطع الفيديو',
    Icons.photo_size_select_actual_outlined,
    Color(0xFF007BFC),
  );
  static const _camera = _WaItem('camera', 'الكاميرا', Icons.photo_camera_outlined, Color(0xFFFF2E74));
  static const _audio = _WaItem('audio', 'مقطع صوتي', Icons.headset_outlined, Color(0xFFFF9500));
  static const _contact = _WaItem('contact', 'جهة اتصال', Icons.person_outline, Color(0xFF009DE2));
  static const _poll = _WaItem('poll', 'استطلاع رأي', Icons.poll_outlined, Color(0xFFFFB020));
  static const _event = _WaItem('event', 'مناسبة', Icons.event_outlined, Color(0xFFF1524B));
  static const _sticker = _WaItem('sticker', 'ملصق جديد', Icons.add_circle_outline, Color(0xFF00A884));
  static const _quickReplies = _WaItem('quick_replies', 'الردود السريعة', Icons.bolt_outlined, Color(0xFFFFBE0A));

  static const _primaryGroup = [
    _document,
    _photos,
    _camera,
    _audio,
    _contact,
    _poll,
    _event,
    _sticker,
  ];

  static const _secondaryGroup = [_quickReplies];

  @override
  Widget build(BuildContext context) {
    final surface = HasimTokens.cardBg(context);
    final rows = Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        for (final item in _primaryGroup) _WaRow(item: item, onTap: () => onSelected(item.action)),
        Divider(height: 1, thickness: 1, color: HasimTokens.lineColor(context)),
        for (final item in _secondaryGroup) _WaRow(item: item, onTap: () => onSelected(item.action)),
      ],
    );
    final cap = maxHeight;
    final listHeight = cap == null ? null : cap - arrowHeight;
    final list = listHeight == null || listHeight >= 520
        ? rows
        : ConstrainedBox(
            constraints: BoxConstraints(maxHeight: listHeight < 80 ? 80 : listHeight),
            child: SingleChildScrollView(child: rows),
          );

    return SizedBox(
      width: panelWidth,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          DecoratedBox(
            decoration: BoxDecoration(
              color: surface,
              borderRadius: BorderRadius.circular(18),
              boxShadow: const [
                BoxShadow(
                  color: Color(0x26000000),
                  blurRadius: 22,
                  offset: Offset(0, 8),
                  spreadRadius: -2,
                ),
                BoxShadow(
                  color: Color(0x14000000),
                  blurRadius: 6,
                  offset: Offset(0, 2),
                ),
              ],
            ),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(18),
              child: Directionality(
                textDirection: TextDirection.rtl,
                child: list,
              ),
            ),
          ),
          SizedBox(
            height: arrowHeight,
            width: panelWidth,
            child: Stack(
              clipBehavior: Clip.none,
              children: [
                Positioned(
                  left: arrowOffset,
                  top: 0,
                  child: _MenuArrow(color: surface),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _MenuArrow extends StatelessWidget {
  const _MenuArrow({required this.color});

  final Color color;

  @override
  Widget build(BuildContext context) {
    return CustomPaint(
      size: const Size(14, 8),
      painter: _MenuArrowPainter(color),
    );
  }
}

class _MenuArrowPainter extends CustomPainter {
  const _MenuArrowPainter(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final shadow = Path()
      ..moveTo(size.width / 2, size.height + 1)
      ..lineTo(0, 0)
      ..lineTo(size.width, 0)
      ..close();
    canvas.drawShadow(shadow, Colors.black.withValues(alpha: 0.18), 2, false);

    final path = Path()
      ..moveTo(size.width / 2, size.height)
      ..lineTo(0, 0)
      ..lineTo(size.width, 0)
      ..close();
    canvas.drawPath(path, Paint()..color = color);
  }

  @override
  bool shouldRepaint(covariant _MenuArrowPainter oldDelegate) => oldDelegate.color != color;
}

class _WaItem {
  const _WaItem(this.action, this.label, this.icon, this.color);

  final String action;
  final String label;
  final IconData icon;
  final Color color;
}

class _WaRow extends StatelessWidget {
  const _WaRow({required this.item, required this.onTap});

  final _WaItem item;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        hoverColor: dark ? Colors.white.withValues(alpha: 0.06) : const Color(0xFFF5F6F6),
        splashColor: dark ? Colors.white.withValues(alpha: 0.08) : const Color(0xFFE9EDEF),
        child: SizedBox(
          height: 42,
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(14, 0, 12, 0),
            child: Row(
              children: [
                Icon(item.icon, size: 22, color: item.color),
                const SizedBox(width: 10),
                Expanded(
                  child: Align(
                    alignment: AlignmentDirectional.centerStart,
                    child: Text(
                      item.label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        fontSize: 14.5,
                        height: 1.2,
                        fontWeight: FontWeight.w500,
                        color: HasimTokens.inkColor(context),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
