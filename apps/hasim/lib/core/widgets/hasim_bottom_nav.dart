import 'package:flutter/material.dart';
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
  });

  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final List<HasimBottomNavItem> items;

  @override
  Widget build(BuildContext context) {
    final barColor = HasimTokens.cardBg(context);
    final bottomInset = MediaQuery.paddingOf(context).bottom;

    return Padding(
      padding: EdgeInsets.fromLTRB(12, 0, 12, 8 + bottomInset),
      child: MediaQuery.removePadding(
        context: context,
        removeBottom: true,
        child: DecoratedBox(
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(HasimTokens.radiusNav),
            boxShadow: HasimTokens.navShadow(context),
          ),
          child: Material(
            color: barColor,
            elevation: 0,
            shadowColor: Colors.transparent,
            surfaceTintColor: Colors.transparent,
            borderRadius: BorderRadius.circular(HasimTokens.radiusNav),
            clipBehavior: Clip.antiAlias,
            child: DecoratedBox(
              decoration: BoxDecoration(
                borderRadius: BorderRadius.circular(HasimTokens.radiusNav),
                border: Border.all(color: HasimTokens.lineColor(context)),
              ),
              child: NavigationBar(
                selectedIndex: selectedIndex,
                onDestinationSelected: onDestinationSelected,
                backgroundColor: barColor,
                surfaceTintColor: Colors.transparent,
                shadowColor: Colors.transparent,
                elevation: 0,
                indicatorColor: HasimTokens.isDark(context)
                    ? HasimTokens.primary.withValues(alpha: 0.22)
                    : HasimTokens.mintChip,
                labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
                height: 68,
                destinations: [
                  for (final item in items)
                    NavigationDestination(
                      icon: _NavIcon(icon: item.icon, badge: item.badge),
                      selectedIcon: _NavIcon(icon: item.selectedIcon, badge: item.badge),
                      label: item.label,
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _NavIcon extends StatelessWidget {
  const _NavIcon({required this.icon, required this.badge});

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
