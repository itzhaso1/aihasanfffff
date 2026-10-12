import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_adaptive_scaffold.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/features/conversations/providers/conversations_controller.dart';
import 'package:hasim/features/home/providers/home_controller.dart';
import 'package:hasim/features/privacy/presentation/locked_chats_navigation.dart';
import 'package:hasim/features/privacy/providers/chat_lock_controller.dart';
import 'package:hasim/features/stories/providers/stories_controller.dart';
import 'package:hasim/features/worker/presentation/worker_inbox_workspace_selector.dart';
import 'package:hasim/router/hasim_nav.dart';
import 'package:hasim/router/worker_nav.dart';

/// آخر مسار رسمه الغلاف، ليظهر الشريط العلوي فور مغادرة شاشة البداية.
final appChromeLocationProvider = StateProvider<String>((ref) => '/splash');

/// محادثة أو ملف عميل مفتوح (موبايل): الشريط السفلي يختفي مثل شاشة الدردشة.
bool hidesPhoneBottomNav(String location) {
  return location.startsWith('/conversations/') ||
      location.startsWith('/customers/') ||
      location == '/search';
}

class AppShell extends StatelessWidget {
  const AppShell({super.key, required this.navigationShell});
  final StatefulNavigationShell navigationShell;

  @override
  Widget build(BuildContext context) {
    return navigationShell;
  }
}

/// غلاف التنقل المشترك لكل المسارات المصادق عليها.
///
/// يبقي السكة الجانبية/الشريط السفلي ثابتين بينما يتبدل محتوى الـ Navigator.
class AppRootShell extends ConsumerWidget {
  const AppRootShell({super.key, required this.child});

  final Widget child;

  int _selectedIndex(String location) {
    if (location == '/conversations' ||
        location.startsWith('/conversations/')) {
      return HasimNav.conversationsIndex;
    }
    if (location == '/email' || location.startsWith('/email/')) {
      return HasimNav.emailIndex;
    }
    if (location == '/updates' || location.startsWith('/updates/')) {
      return HasimNav.updatesIndex;
    }
    return -1;
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final location = GoRouterState.of(context).uri.path;
    final chromeLocation = ref.read(appChromeLocationProvider);
    final chromeVisibilityChanged =
        (chromeLocation == '/splash') != (location == '/splash');
    if (chromeVisibilityChanged) {
      final container = ProviderScope.containerOf(context);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        container.read(appChromeLocationProvider.notifier).state = location;
      });
    }

    final auth = ref.watch(authControllerProvider);
    final canShowNavigation =
        auth.isAuthenticated &&
        (auth.isIndependentWorker || auth.workspace != null);
    if (!canShowNavigation ||
        location == '/splash' ||
        WorkerNav.isWorkerLocation(location)) {
      return child;
    }

    return _buildNavigation(context, ref, location);
  }

  Widget _buildNavigation(
    BuildContext context,
    WidgetRef ref,
    String location,
  ) {
    final auth = ref.watch(authControllerProvider);
    final isIndependentWorker = auth.isIndependentWorker;
    final showSpecialInboxSections = auth.canSeeSpecialInboxSections;
    final isInboxLocation =
        location == '/conversations' || location.startsWith('/conversations/');
    final homeSnapshot = isIndependentWorker
        ? null
        : ref.watch(homeControllerProvider).snapshot;
    // Worker access to Updates is checked by the screen against its engagement capability.
    final updatesBadge = isIndependentWorker
        ? 0
        : ref.watch(
            storiesControllerProvider.select((state) => state.unviewedCount),
          );
    final workerUnreadCount = isIndependentWorker && isInboxLocation
        ? ref.watch(
            conversationsControllerProvider.select(
              (state) =>
                  state.items.where((item) => item.unreadCount > 0).length,
            ),
          )
        : 0;
    final items = hasimBottomNavItems(
      chatBadge: isIndependentWorker
          ? workerUnreadCount
          : (homeSnapshot?.unreadConversations ?? 0),
      emailBadge: homeSnapshot?.unreadEmail ?? 0,
      updatesBadge: updatesBadge,
    );

    final settingsActive =
        location == '/settings' || location.startsWith('/settings/');
    final router = GoRouter.of(context);

    return HasimAdaptiveScaffold(
      selectedIndex: _selectedIndex(location),
      onDestinationSelected: (index) {
        if (index == HasimNav.conversationsIndex) {
          leaveSpecialInbox(ref);
        }
        router.go(HasimAdaptiveScaffold.shellRoutes[index]);
      },
      onSettingsTap: () {
        if (!settingsActive) router.push('/settings');
      },
      settingsSelected: settingsActive,
      onArchiveTap: showSpecialInboxSections
          ? () {
              ref.read(chatLockControllerProvider.notifier).lockChats();
              router.go('/archive');
            }
          : null,
      archiveSelected: showSpecialInboxSections && location == '/archive',
      onLockedTap: showSpecialInboxSections ? () => router.go('/locked') : null,
      lockedSelected: showSpecialInboxSections && location == '/locked',
      showSpecialInboxSections: showSpecialInboxSections,
      items: items,
      showBottomNavigation: !hidesPhoneBottomNav(location),
      workerWorkspaceSelector: isIndependentWorker
          ? WorkerInboxWorkspacePicker(
              iconOnly: true,
              newMessagesCount: workerUnreadCount,
            )
          : null,
      body: ColoredBox(color: HasimTokens.pageBg(context), child: child),
    );
  }
}
