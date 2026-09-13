import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';

/// شريحة فلتر على شكل حبة دواء — المحدد بلون حاسم مع علامة صح على اليسار في RTL.
class ConversationFilterChip extends StatelessWidget {
  const ConversationFilterChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.leading,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final Widget? leading;

  @override
  Widget build(BuildContext context) {
    final border = selected ? AppTheme.brand : const Color(0xFFD5E3DF);
    final fg = selected ? Colors.white : const Color(0xFF5C6F6A);

    return Material(
      color: selected ? AppTheme.brand : Colors.white,
      shape: StadiumBorder(side: BorderSide(color: border)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        customBorder: const StadiumBorder(),
        child: Padding(
          padding: const EdgeInsetsDirectional.fromSTEB(14, 8, 12, 8),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                label,
                style: TextStyle(
                  color: fg,
                  fontWeight: FontWeight.w700,
                  fontSize: 13,
                ),
              ),
              const SizedBox(width: 6),
              if (selected)
                const Icon(Icons.check_rounded, size: 16, color: Colors.white)
              else
                ?leading,
            ],
          ),
        ),
      ),
    );
  }
}

/// شريحة قناة أهدأ — تبقى وظيفية دون منافسة فلاتر الحالة.
class ConversationChannelChip extends StatelessWidget {
  const ConversationChannelChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onSelected,
  });

  final String label;
  final bool selected;
  final ValueChanged<bool> onSelected;

  @override
  Widget build(BuildContext context) {
    final border = selected ? AppTheme.brand : const Color(0xFFE0EAE7);
    final bg = selected ? AppTheme.brand.withValues(alpha: 0.12) : Colors.white;
    final fg = selected ? AppTheme.brandDark : const Color(0xFF7A8B87);

    return Material(
      color: bg,
      shape: StadiumBorder(side: BorderSide(color: border)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => onSelected(!selected),
        customBorder: const StadiumBorder(),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
          child: Text(
            label,
            style: TextStyle(
              color: fg,
              fontWeight: FontWeight.w600,
              fontSize: 12,
            ),
          ),
        ),
      ),
    );
  }
}
