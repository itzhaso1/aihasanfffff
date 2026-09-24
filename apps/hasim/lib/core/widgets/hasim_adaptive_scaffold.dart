import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/core/widgets/hasim_nav_rail.dart';

/// صدفة متجاوبة: شريط سفلي على الموبايل، سكة يمين على الشاشات العريضة.
class HasimAdaptiveScaffold extends StatelessWidget {
  const HasimAdaptiveScaffold({
    super.key,
    required this.selectedIndex,
    required this.onDestinationSelected,
    required this.body,
    required this.items,
    this.onSettingsTap,
    this.settingsSelected = false,
  });

  final int selectedIndex;
  final ValueChanged<int> onDestinationSelected;
  final Widget body;
  final List<HasimBottomNavItem> items;
  final VoidCallback? onSettingsTap;
  final bool settingsSelected;

  static const shellRoutes = [
    '/conversations',
    '/email',
    '/updates',
  ];

  static void goBranch(BuildContext context, int index) {
    if (index < 0 || index >= shellRoutes.length) return;
    context.go(shellRoutes[index]);
  }

  @override
  Widget build(BuildContext context) {
    final mint = HasimTokens.pageBg(context);
    final rail = HasimLayout.useNavRail(context);

    if (!rail) {
      return Scaffold(
        backgroundColor: mint,
        body: body,
        bottomNavigationBar: HasimBottomNav(
          selectedIndex: selectedIndex,
          onDestinationSelected: onDestinationSelected,
          items: items,
        ),
      );
    }

    return Scaffold(
      backgroundColor: mint,
      body: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          HasimNavRail(
            key: const Key('hasim-nav-rail'),
            selectedIndex: selectedIndex,
            onDestinationSelected: onDestinationSelected,
            items: items,
            onSettingsTap: onSettingsTap,
            settingsSelected: settingsSelected,
          ),
          Expanded(child: body),
        ],
      ),
    );
  }
}
