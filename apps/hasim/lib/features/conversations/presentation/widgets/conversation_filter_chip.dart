import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

/// شريحة فلتر على شكل حبة دواء — المحدد بلون حاسم مع علامة صح على اليسار في RTL.
class ConversationFilterChip extends StatelessWidget {
  const ConversationFilterChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onTap,
    this.leading,
    this.dense = false,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;
  final Widget? leading;
  final bool dense;

  @override
  Widget build(BuildContext context) {
    final border = selected ? HasimTokens.chatGreen : const Color(0xFFD5E3DF);
    final fg = selected ? Colors.white : const Color(0xFF5C6F6A);

    return Material(
      color: selected ? HasimTokens.chatGreen : Colors.white,
      shape: StadiumBorder(side: BorderSide(color: border)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        customBorder: const StadiumBorder(),
        child: Padding(
          padding: dense
              ? const EdgeInsetsDirectional.fromSTEB(11, 7, 9, 7)
              : const EdgeInsetsDirectional.fromSTEB(14, 8, 12, 8),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                label,
                maxLines: 1,
                softWrap: false,
                style: TextStyle(
                  color: fg,
                  fontWeight: FontWeight.w700,
                  fontSize: dense ? 12.5 : 13,
                ),
              ),
              SizedBox(width: dense ? 5 : 6),
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

/// قائمة قنوات سطح المكتب — زر سهم منسدل منفصل عن فلاتر الحالة.
class ConversationChannelDropdown extends StatelessWidget {
  const ConversationChannelDropdown({
    super.key,
    required this.channels,
    required this.selectedChannel,
    required this.onSelected,
  });

  final List<(String, String, IconData)> channels;
  final String? selectedChannel;
  final ValueChanged<String?> onSelected;

  @override
  Widget build(BuildContext context) {
    final selected = selectedChannel != null;

    return PopupMenuButton<String>(
      key: const Key('conversation-channel-dropdown'),
      tooltip: 'فلتر القناة',
      position: PopupMenuPosition.under,
      offset: const Offset(0, 8),
      elevation: 10,
      color: Colors.white,
      shadowColor: const Color(0x240F2A24),
      surfaceTintColor: Colors.transparent,
      padding: EdgeInsets.zero,
      splashRadius: 18,
      style: const ButtonStyle(
        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
        minimumSize: WidgetStatePropertyAll(Size(32, 32)),
        padding: WidgetStatePropertyAll(EdgeInsets.zero),
      ),
      constraints: const BoxConstraints(minWidth: 176, maxWidth: 184),
      menuPadding: const EdgeInsets.symmetric(vertical: 6),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: const BorderSide(color: Color(0xFFE7F0ED)),
      ),
      onSelected: (value) {
        onSelected(selectedChannel == value ? null : value);
      },
      itemBuilder: (context) => [
        for (var i = 0; i < channels.length; i++) ...[
          if (i > 0) const PopupMenuDivider(height: 1),
          PopupMenuItem<String>(
            value: channels[i].$1,
            height: 44,
            padding: const EdgeInsets.symmetric(horizontal: 14),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    channels[i].$2,
                    style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w600,
                      color: selectedChannel == channels[i].$1
                          ? AppTheme.brandDark
                          : const Color(0xFF172024),
                    ),
                  ),
                ),
                Icon(
                  channels[i].$3,
                  size: 18,
                  color: selectedChannel == channels[i].$1
                      ? AppTheme.brand
                      : const Color(0xFF7A8B87),
                ),
              ],
            ),
          ),
        ],
      ],
      child: Material(
        color: selected ? AppTheme.brand.withValues(alpha: 0.12) : Colors.white,
        shape: StadiumBorder(
          side: BorderSide(
            color: selected ? AppTheme.brand : const Color(0xFFD5E3DF),
          ),
        ),
        clipBehavior: Clip.antiAlias,
        child: const SizedBox(
          width: 32,
          height: 32,
          child: Icon(Icons.keyboard_arrow_down_rounded, size: 22, color: Color(0xFF5C6F6A)),
        ),
      ),
    );
  }
}

/// عدد الأدوات الإضافية الظاهرة خارج القائمة — يزيد/ينقص تدريجيًا حسب العرض (حد أقصى [wideVisibleExtras]).
int conversationPromotedExtraCount({
  required double availableWidth,
  required List<double> primaryWidths,
  required List<double> extraWidths,
  required double dropdownWidth,
  double gap = 8,
  int wideVisibleExtras = 3,
}) {
  if (extraWidths.isEmpty) return 0;

  double span(List<double> widths) {
    if (widths.isEmpty) return 0;
    return widths.reduce((a, b) => a + b) + gap * (widths.length - 1);
  }

  final maxPromoted = wideVisibleExtras.clamp(0, extraWidths.length);
  var promoted = 0;
  for (var k = 1; k <= maxPromoted; k++) {
    final row = span([...primaryWidths, ...extraWidths.take(k), dropdownWidth]);
    if (availableWidth + 0.5 >= row) {
      promoted = k;
    } else {
      break;
    }
  }
  return promoted;
}

/// فلاتر سطح المكتب: الأدوات الإضافية تخرج من القائمة تدريجيًا حسب عرض اللوحة.
class DesktopAdaptiveConversationFilters extends StatefulWidget {
  const DesktopAdaptiveConversationFilters({
    super.key,
    required this.primary,
    required this.extras,
    required this.dropdown,
  });

  /// تُستدعى أكثر من مرة حتى لا تُركَّب نفس عناصر الواجهة في القياس والصف الظاهر.
  final List<Widget> Function() primary;
  final List<Widget> Function() extras;

  /// [promotedCount] هو عدد الأدوات التي خرجت من القائمة وتُعرض كشرائح.
  final Widget Function(int promotedCount) dropdown;

  @override
  State<DesktopAdaptiveConversationFilters> createState() => _DesktopAdaptiveConversationFiltersState();
}

class _DesktopAdaptiveConversationFiltersState extends State<DesktopAdaptiveConversationFilters> {
  List<double>? _primaryWidths;
  List<double>? _extraWidths;
  double? _dropdownWidth;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final primary = widget.primary();
        final extras = widget.extras();
        final promoted = (_primaryWidths == null || _extraWidths == null || _dropdownWidth == null)
            ? 0
            : conversationPromotedExtraCount(
                availableWidth: constraints.maxWidth,
                primaryWidths: _primaryWidths!,
                extraWidths: _extraWidths!,
                dropdownWidth: _dropdownWidth!,
              ).clamp(0, extras.length);
        final dropdown = widget.dropdown(promoted);

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ClipRect(
              child: SizedBox(
                height: 0,
                width: constraints.maxWidth,
                child: OverflowBox(
                  alignment: AlignmentDirectional.centerStart,
                  minWidth: 0,
                  maxWidth: double.infinity,
                  minHeight: 0,
                  maxHeight: 48,
                  child: _WidthRow(
                    onWidths: (primaryWidths, extraWidths, dropdownWidth) {
                      if (!mounted) return;
                      if (_same(_primaryWidths, primaryWidths) &&
                          _same(_extraWidths, extraWidths) &&
                          _dropdownWidth != null &&
                          (_dropdownWidth! - dropdownWidth).abs() < 0.5) {
                        return;
                      }
                      setState(() {
                        _primaryWidths = primaryWidths;
                        _extraWidths = extraWidths;
                        _dropdownWidth = dropdownWidth;
                      });
                    },
                    primary: widget.primary(),
                    extras: widget.extras(),
                    dropdown: widget.dropdown(0),
                  ),
                ),
              ),
            ),
            SingleChildScrollView(
              key: const Key('desktop-conversation-filters-row'),
              scrollDirection: Axis.horizontal,
              clipBehavior: Clip.hardEdge,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  for (var i = 0; i < primary.length; i++) ...[
                    if (i > 0) const SizedBox(width: 8),
                    primary[i],
                  ],
                  for (var i = 0; i < promoted; i++) ...[
                    const SizedBox(width: 8),
                    extras[i],
                  ],
                  const SizedBox(width: 8),
                  dropdown,
                ],
              ),
            ),
          ],
        );
      },
    );
  }

  bool _same(List<double>? a, List<double> b) {
    if (a == null || a.length != b.length) return false;
    for (var i = 0; i < a.length; i++) {
      if ((a[i] - b[i]).abs() > 0.5) return false;
    }
    return true;
  }
}

class _WidthRow extends StatefulWidget {
  const _WidthRow({
    required this.primary,
    required this.extras,
    required this.dropdown,
    required this.onWidths,
  });

  final List<Widget> primary;
  final List<Widget> extras;
  final Widget dropdown;
  final void Function(List<double> primary, List<double> extras, double dropdown) onWidths;

  @override
  State<_WidthRow> createState() => _WidthRowState();
}

class _WidthRowState extends State<_WidthRow> {
  List<GlobalKey> _primaryKeys = [];
  List<GlobalKey> _extraKeys = [];
  final _dropdownKey = GlobalKey();

  @override
  void initState() {
    super.initState();
    _syncKeys();
    _report();
  }

  @override
  void didUpdateWidget(covariant _WidthRow oldWidget) {
    super.didUpdateWidget(oldWidget);
    _syncKeys();
    _report();
  }

  void _syncKeys() {
    if (_primaryKeys.length != widget.primary.length) {
      _primaryKeys = List.generate(widget.primary.length, (_) => GlobalKey());
    }
    if (_extraKeys.length != widget.extras.length) {
      _extraKeys = List.generate(widget.extras.length, (_) => GlobalKey());
    }
  }

  void _report() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final primary = _read(_primaryKeys);
      final extras = _read(_extraKeys);
      final dropdownBox = _dropdownKey.currentContext?.findRenderObject() as RenderBox?;
      if (primary == null || extras == null || dropdownBox == null || !dropdownBox.hasSize) return;
      widget.onWidths(primary, extras, dropdownBox.size.width);
    });
  }

  List<double>? _read(List<GlobalKey> keys) {
    final widths = <double>[];
    for (final key in keys) {
      final box = key.currentContext?.findRenderObject() as RenderBox?;
      if (box == null || !box.hasSize) return null;
      widths.add(box.size.width);
    }
    return widths;
  }

  @override
  Widget build(BuildContext context) {
    final children = <Widget>[
      for (var i = 0; i < widget.primary.length; i++)
        KeyedSubtree(key: _primaryKeys[i], child: widget.primary[i]),
      for (var i = 0; i < widget.extras.length; i++)
        KeyedSubtree(key: _extraKeys[i], child: widget.extras[i]),
      KeyedSubtree(key: _dropdownKey, child: widget.dropdown),
    ];
    return Row(
      mainAxisSize: MainAxisSize.min,
      spacing: 8,
      children: children,
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
