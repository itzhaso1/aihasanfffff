import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/desktop_window_frame.dart';
import 'package:hasim/core/widgets/hasim_adaptive_scaffold.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/router/hasim_nav.dart';

class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.navigationShell});
  final StatefulNavigationShell navigationShell;

  void _go(int index) {
    // الحفاظ على حالة كل تبويب (scroll / محتوى) عبر indexedStack.
    navigationShell.goBranch(
      index,
      initialLocation: index == navigationShell.currentIndex,
    );
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final snap = ref.watch(homeControllerProvider).snapshot;
    final stories = ref.watch(storiesControllerProvider);
    final items = hasimBottomNavItems(
      chatBadge: snap?.unreadConversations ?? 0,
      emailBadge: snap?.unreadEmail ?? 0,
      updatesBadge: stories.unviewedCount,
    );

    final location = GoRouterState.of(context).uri.path;
    final settingsActive = location == '/settings' || location.startsWith('/settings/');

    return DesktopWindowFrame(
      child: HasimAdaptiveScaffold(
        selectedIndex: navigationShell.currentIndex.clamp(0, HasimNav.updatesIndex),
        onDestinationSelected: _go,
        onSettingsTap: () => context.push('/settings'),
        settingsSelected: settingsActive,
        items: items,
        body: ColoredBox(
          color: HasimTokens.pageBg(context),
          child: navigationShell,
        ),
      ),
    );
  }
}
