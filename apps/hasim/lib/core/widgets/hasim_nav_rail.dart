import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';

/// سكة تنقل سطح المكتب — شريط عمودي مستقل ملتصق بحافة النافذة، خارج قائمة المحادثات.
class HasimNavRail extends StatelessWidget {
  const HasimNavRail({
    super.key,
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.items,
    this.onSettingsTap,
    this.settingsSelected = false,
  });

  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final List<HasimBottomNavItem> items;
  final VoidCallback? onSettingsTap;
  final bool settingsSelected;

  @override
  Widget build(BuildContext context) {
    final bg = HasimTokens.pageBg(context);

    return ColoredBox(
      color: bg,
      child: SizedBox(
        width: HasimLayout.navRailWidth,
        child: Column(
            children: [
              const SizedBox(height: 12),
              for (var i = 0; i < items.length; i++) ...[
                if (i > 0) const SizedBox(height: 4),
                _RailDestination(
                  item: items[i],
                  selected: i == selectedIndex,
                  onTap: () => onDestinationSelected(i),
                ),
              ],
              const Spacer(),
              if (onSettingsTap != null)
                _RailDestination(
                  item: const HasimBottomNavItem(
                    icon: Icons.settings_outlined,
                    selectedIcon: Icons.settings_rounded,
                    label: 'الإعدادات',
                  ),
                  selected: settingsSelected,
                  onTap: onSettingsTap!,
                ),
              const SizedBox(height: 12),
            ],
        ),
      ),
    );
  }
}

class _RailDestination extends StatelessWidget {
  const _RailDestination({
    required this.item,
    required this.selected,
    required this.onTap,
  });

  final HasimBottomNavItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final selectedColor = HasimTokens.chatGreen;
    final idleColor = HasimTokens.mutedColor(context);
    final bubble = selected
        ? (HasimTokens.isDark(context) ? HasimTokens.chatGreen.withValues(alpha: 0.22) : const Color(0xFFE7F6F2))
        : Colors.transparent;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 6),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(18),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                AnimatedContainer(
                  duration: const Duration(milliseconds: 180),
                  width: 44,
                  height: 36,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: bubble,
                    borderRadius: BorderRadius.circular(14),
                  ),
                  child: Badge(
                    isLabelVisible: item.badge > 0,
                    label: Text('${item.badge}'),
                    child: Icon(
                      selected ? item.selectedIcon : item.icon,
                      size: 22,
                      color: selected ? selectedColor : idleColor,
                    ),
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  item.label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.labelSmall?.copyWith(
                    fontSize: 10,
                    fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                    color: selected ? selectedColor : idleColor,
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
