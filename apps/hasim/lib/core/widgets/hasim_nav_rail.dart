import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/core/widgets/desktop_window_title_bar.dart';
import 'package:hasim/core/widgets/hasim_inner_chrome_corner.dart';
import 'package:hasim/router/hasim_nav.dart';

/// سكة تنقل سطح المكتب — شريط عمودي مستقل ملتصق بحافة النافذة، خارج قائمة المحادثات.
class HasimNavRail extends StatelessWidget {
  const HasimNavRail({
    super.key,
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.items,
    this.onSettingsTap,
    this.settingsSelected = false,
    this.onArchiveTap,
    this.archiveSelected = false,
    this.onLockedTap,
    this.lockedSelected = false,
    this.showSpecialInboxSections = true,
    this.workerWorkspaceSelector,
  });

  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final List<HasimBottomNavItem> items;
  final VoidCallback? onSettingsTap;
  final bool settingsSelected;
  final VoidCallback? onArchiveTap;
  final bool archiveSelected;
  final VoidCallback? onLockedTap;
  final bool lockedSelected;
  final bool showSpecialInboxSections;
  final Widget? workerWorkspaceSelector;

  @override
  Widget build(BuildContext context) {
    final bg = HasimTokens.pageBg(context);
    final line = HasimTokens.lineColor(context);
    final topLineInset = hasimUsesDesktopTitleBar
        ? HasimInnerChromeCorner.radius
        : 0.0;

    return DecoratedBox(
      decoration: BoxDecoration(color: bg),
      child: SizedBox(
        width: HasimLayout.navRailWidth,
        child: Stack(
          fit: StackFit.expand,
          children: [
            Positioned(
              left: 0,
              top: topLineInset,
              bottom: 0,
              width: 1,
              child: ColoredBox(color: line),
            ),
            Column(
              children: [
                const SizedBox(height: 12),
                if (workerWorkspaceSelector != null) ...[
                  workerWorkspaceSelector!,
                  const SizedBox(height: 4),
                ],
                for (var position = 0; position < items.length; position++) ...[
                  if (position > 0) const SizedBox(height: 4),
                  _RailDestination(
                    key: _itemKey(_destinationIndex(position)),
                    item: items[_destinationIndex(position)],
                    selected: _destinationIndex(position) == selectedIndex,
                    onTap: () =>
                        onDestinationSelected(_destinationIndex(position)),
                  ),
                ],
                if (showSpecialInboxSections) ...[
                  const SizedBox(height: 4),
                  _RailDestination(
                    key: const Key('nav-rail-archived'),
                    item: const HasimBottomNavItem(
                      icon: Icons.archive_outlined,
                      selectedIcon: Icons.archive_rounded,
                      label: 'المؤرشفة',
                    ),
                    selected: archiveSelected,
                    onTap: onArchiveTap ?? () {},
                  ),
                  const SizedBox(height: 4),
                  _RailDestination(
                    key: const Key('nav-rail-locked'),
                    item: const HasimBottomNavItem(
                      icon: Icons.lock_outline,
                      selectedIcon: Icons.lock,
                      label: 'الدردشات المقفلة',
                    ),
                    selected: lockedSelected,
                    onTap: onLockedTap ?? () {},
                  ),
                ],
                const Spacer(),
                if (onSettingsTap != null)
                  _RailDestination(
                    key: const Key('nav-rail-settings'),
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
          ],
        ),
      ),
    );
  }

  int _destinationIndex(int position) {
    if (workerWorkspaceSelector == null) return position;
    if (position == 1) return HasimNav.updatesIndex;
    if (position == HasimNav.updatesIndex) return 1;
    return position;
  }

  Key _itemKey(int index) {
    return switch (index) {
      HasimNav.conversationsIndex => const Key('nav-rail-conversations'),
      HasimNav.emailIndex => const Key('nav-rail-email'),
      HasimNav.updatesIndex => const Key('nav-rail-updates'),
      _ => Key('nav-rail-$index'),
    };
  }
}

class _RailDestination extends StatelessWidget {
  const _RailDestination({
    super.key,
    required this.item,
    required this.selected,
    required this.onTap,
  });

  final HasimBottomNavItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final selectedColor = HasimTokens.chatGreen;
    final idleColor = HasimTokens.mutedColor(context);
    final bubble = selected
        ? (HasimTokens.isDark(context)
              ? HasimTokens.chatGreen.withValues(alpha: 0.22)
              : const Color(0xFFE7F6F2))
        : Colors.transparent;

    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: Tooltip(
        message: item.label,
        waitDuration: const Duration(milliseconds: 400),
        child: Material(
          color: Colors.transparent,
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(18),
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: 8),
              child: AnimatedContainer(
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
            ),
          ),
        ),
      ),
    );
  }
}
