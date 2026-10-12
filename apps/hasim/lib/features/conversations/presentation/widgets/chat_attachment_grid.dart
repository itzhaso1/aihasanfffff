import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// شبكة المرفقات على الهاتف، أسفل حقل الكتابة في مكان لوحة المفاتيح.
class ChatAttachmentGridPanel extends StatelessWidget {
  const ChatAttachmentGridPanel({super.key, required this.onSelected});

  static const double panelHeight = 188;

  static double heightFor(BuildContext context) =>
      HasimLayout.attachmentPanelHeight(MediaQuery.sizeOf(context).width);

  final ValueChanged<String> onSelected;

  static const _items = [
    _GridItem('document', 'مستند', Icons.description_outlined, Color(0xFF7F66FF)),
    _GridItem('photos_videos', 'المعرض', Icons.photo_outlined, Color(0xFF0A7EA4)),
    _GridItem('quick_replies', 'رد سريع', Icons.bolt_rounded, Color(0xFFF5B400)),
    _GridItem('location', 'الموقع', Icons.location_on_outlined, Color(0xFF1FA855)),
    _GridItem('contact', 'جهة اتصال', Icons.person_outline_rounded, Color(0xFF1A8AD6)),
    _GridItem('poll', 'استطلاع رأي', Icons.poll_outlined, Color(0xFFFF8A00)),
    _GridItem('event', 'مناسبة', Icons.event_outlined, Color(0xFFE85D8C)),
  ];

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        final columns = HasimLayout.attachmentGridCount(width);
        return Material(
          color: HasimTokens.cardBg(context),
          child: SizedBox(
            height: HasimLayout.attachmentPanelHeight(width),
            child: Directionality(
              textDirection: TextDirection.rtl,
              child: Column(
                children: [
                  const SizedBox(height: 6),
                  Container(
                    width: 32,
                    height: 3,
                    decoration: BoxDecoration(
                      color: HasimTokens.lineColor(context),
                      borderRadius: BorderRadius.circular(4),
                    ),
                  ),
                  const SizedBox(height: 6),
                  Expanded(
                    child: GridView.count(
                      crossAxisCount: columns,
                      physics: const NeverScrollableScrollPhysics(),
                      padding: const EdgeInsets.fromLTRB(8, 0, 8, 4),
                      mainAxisSpacing: 2,
                      crossAxisSpacing: 4,
                      childAspectRatio: columns < 4 ? 1.15 : 1.05,
                      children: [
                        for (final item in _items)
                          _GridTile(
                            item: item,
                            onTap: () => onSelected(item.action),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}

class _GridItem {
  const _GridItem(this.action, this.label, this.icon, this.color);

  final String action;
  final String label;
  final IconData icon;
  final Color color;
}

class _GridTile extends StatelessWidget {
  const _GridTile({required this.item, required this.onTap});

  final _GridItem item;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final dark = HasimTokens.isDark(context);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.start,
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: dark ? HasimTokens.darkLine : const Color(0xFFF7F8F8),
              shape: BoxShape.circle,
              border: Border.all(color: HasimTokens.lineColor(context)),
            ),
            child: Icon(item.icon, color: item.color, size: 20),
          ),
          const SizedBox(height: 4),
          Text(
            item.label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            textAlign: TextAlign.center,
            style: TextStyle(
              fontSize: 11,
              height: 1.1,
              fontWeight: FontWeight.w500,
              color: HasimTokens.inkColor(context),
            ),
          ),
        ],
      ),
    );
  }
}
