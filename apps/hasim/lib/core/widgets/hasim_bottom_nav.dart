import 'package:flutter/material.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/router/hasim_nav.dart';

class HasimBottomNavItem {
  const HasimBottomNavItem({
    required this.icon,
    required this.selectedIcon,
    required this.label,
    this.badge = 0,
  });

  final IconData icon;
  final IconData selectedIcon;
  final String label;
  final int badge;
}

class HasimBottomNav extends StatelessWidget {
  const HasimBottomNav({
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

  static const double barHeight = 54;
  static const double barRadius = 16;

  int get _selectedIndex {
    if (onSettingsTap != null && settingsSelected) return items.length;
    if (items.isEmpty) return 0;
    return selectedIndex.clamp(0, items.length - 1);
  }

  @override
  Widget build(BuildContext context) {
    final barColor = HasimTokens.cardBg(context);
    final bottomInset = MediaQuery.paddingOf(context).bottom;

    final width = MediaQuery.sizeOf(context).width;
    return Padding(
      padding: EdgeInsets.fromLTRB(
        width < 360 ? 8 : 12,
        0,
        width < 360 ? 8 : 12,
        8 + bottomInset,
      ),
      child: MediaQuery.removePadding(
        context: context,
        removeBottom: true,
        child: DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(barRadius),
            boxShadow: HasimTokens.navShadow(context),
          ),
          child: Material(
            color: barColor,
            elevation: 0,
            shadowColor: Colors.transparent,
            surfaceTintColor: Colors.transparent,
            borderRadius: BorderRadius.circular(barRadius),
            clipBehavior: Clip.antiAlias,
            child: DecoratedBox(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(barRadius),
                border: Border.all(color: HasimTokens.lineColor(context)),
              ),
              child: SizedBox(
                height: barHeight,
                child: NavigationBar(
                  selectedIndex: _selectedIndex,
                  onDestinationSelected: (index) {
                    if (onSettingsTap != null && index == items.length) {
                      onSettingsTap!();
                      return;
                    }
                    onDestinationSelected(index);
                  },
                  backgroundColor: barColor,
                  surfaceTintColor: Colors.transparent,
                  shadowColor: Colors.transparent,
                  elevation: 0,
                  indicatorColor: HasimTokens.isDark(context)
                      ? HasimTokens.primary.withValues(alpha: 0.22)
                      : HasimTokens.mintChip,
                  indicatorShape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                  labelBehavior: HasimLayout.bottomNavLabelBehavior(width),
                  height: barHeight,
                  destinations: [
                    for (final item in items)
                      NavigationDestination(
                        icon: _NavIcon(icon: item.icon, badge: item.badge),
                        selectedIcon: _NavIcon(icon: item.selectedIcon, badge: item.badge),
                        label: item.label,
                      ),
                    if (onSettingsTap != null)
                      const NavigationDestination(
                        icon: _NavIcon(
                          key: Key('hasim-settings-disc'),
                          icon: Icons.settings_outlined,
                        ),
                        selectedIcon: _NavIcon(icon: Icons.settings_rounded),
                        label: 'الإعدادات',
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _NavIcon extends StatelessWidget {
  const _NavIcon({super.key, required this.icon, this.badge = 0});

  final IconData icon;
  final int badge;

  @override
  Widget build(BuildContext context) {
    return Badge(
      isLabelVisible: badge > 0,
      label: Text('$badge'),
      child: Icon(icon),
    );
  }
}

List<HasimBottomNavItem> hasimBottomNavItems({
  required int chatBadge,
  required int emailBadge,
  required int updatesBadge,
}) {
  return [
    HasimBottomNavItem(
      icon: Icons.chat_bubble_outline_rounded,
      selectedIcon: Icons.chat_bubble_rounded,
      label: HasimNav.bottomLabels[HasimNav.conversationsIndex],
      badge: chatBadge,
    ),
    HasimBottomNavItem(
      icon: Icons.mail_outline_rounded,
      selectedIcon: Icons.mail_rounded,
      label: HasimNav.bottomLabels[HasimNav.emailIndex],
      badge: emailBadge,
    ),
    HasimBottomNavItem(
      icon: Icons.auto_stories_outlined,
      selectedIcon: Icons.auto_stories,
      label: HasimNav.bottomLabels[HasimNav.updatesIndex],
      badge: updatesBadge,
    ),
  ];
}
