import 'package:flutter/material.dart';
import 'package:hasim/core/theme/app_theme.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';

class HasimSegmentedTab<T> {
  const HasimSegmentedTab({required this.value, required this.label});
  final T value;
  final String label;
}

class HasimSegmentedTabs<T> extends StatelessWidget {
  const HasimSegmentedTabs({
    super.key,
    required this.tabs,
    required this.selected,
    required this.onChanged,
  });

  final List<HasimSegmentedTab<T>> tabs;
  final T selected;
  final ValueChanged<T> onChanged;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        color: HasimTokens.cardBg(context),
        borderRadius: BorderRadius.circular(HasimTokens.radiusPill),
        border: Border.all(color: HasimTokens.lineColor(context)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(4),
        child: Row(
          children: [
            for (final tab in tabs)
              Expanded(
                child: _TabChip(
                  label: tab.label,
                  selected: tab.value == selected,
                  onTap: () => onChanged(tab.value),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _TabChip extends StatelessWidget {
  const _TabChip({
    required this.label,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final fg = selected ? AppTheme.brandDark : HasimTokens.mutedColor(context);
    return Material(
      color: selected ? HasimTokens.mintChip : Colors.transparent,
      shape: const StadiumBorder(),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        customBorder: const StadiumBorder(),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 10),
          child: FittedBox(
            fit: BoxFit.scaleDown,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.center,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  label,
                  maxLines: 1,
                  style: TextStyle(
                    color: fg,
                    fontWeight: selected ? FontWeight.w800 : FontWeight.w600,
                    fontSize: 13,
                  ),
                ),
                if (selected) ...[
                  const SizedBox(width: 4),
                  Icon(Icons.check_rounded, size: 16, color: fg),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
