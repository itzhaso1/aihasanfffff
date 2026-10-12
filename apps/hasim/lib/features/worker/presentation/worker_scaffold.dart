import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:hasim/core/theme/hasim_tokens.dart';
import 'package:hasim/core/widgets/hasim_bottom_nav.dart';
import 'package:hasim/router/hasim_nav.dart';
import 'package:hasim/router/worker_nav.dart';

class WorkerSidePaneScope extends InheritedWidget {
  const WorkerSidePaneScope({
    super.key,
    required this.onNavigate,
    required this.onBack,
    required super.child,
  });

  final ValueChanged<String> onNavigate;
  final VoidCallback onBack;

  static WorkerSidePaneScope? maybeOf(BuildContext context) {
    return context.dependOnInheritedWidgetOfExactType<WorkerSidePaneScope>();
  }

  @override
  bool updateShouldNotify(WorkerSidePaneScope oldWidget) {
    return onNavigate != oldWidget.onNavigate || onBack != oldWidget.onBack;
  }
}

void navigateWorkerDestination(BuildContext context, String location) {
  final scope = WorkerSidePaneScope.maybeOf(context);
  if (scope != null && location.startsWith('/worker/')) {
    scope.onNavigate(location);
    return;
  }
  context.push(location);
}

void navigateWorkerBack(BuildContext context, String fallbackLocation) {
  final scope = WorkerSidePaneScope.maybeOf(context);
  if (scope != null) {
    scope.onBack();
    return;
  }
  if (context.canPop()) {
    context.pop();
    return;
  }
  context.go(fallbackLocation);
}

class WorkerScaffold extends StatelessWidget {
  const WorkerScaffold({
    super.key,
    required this.selectedIndex,
    required this.body,
    this.showBottomNavigation = true,
  });

  final int selectedIndex;
  final Widget body;
  final bool showBottomNavigation;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: HasimTokens.pageBg(context),
      body: body,
      bottomNavigationBar: showBottomNavigation
          ? HasimBottomNav(
              selectedIndex: selectedIndex,
              onDestinationSelected: (index) {
                final location = WorkerNav.locationForIndex(index);
                // The Inbox is the shared shell, so it stacks over the worker
                // screen and the system back returns here.
                if (location == HasimNav.initialShellRoute) {
                  context.push(location);
                  return;
                }
                context.go(location);
              },
              items: const [
                HasimBottomNavItem(
                  icon: Icons.chat_bubble_outline,
                  selectedIcon: Icons.chat_bubble,
                  label: 'المحادثات',
                ),
                HasimBottomNavItem(
                  icon: Icons.local_offer_outlined,
                  selectedIcon: Icons.local_offer,
                  label: 'العروض',
                ),
                HasimBottomNavItem(
                  icon: Icons.work_outline,
                  selectedIcon: Icons.work,
                  label: 'الأعمال',
                ),
                HasimBottomNavItem(
                  icon: Icons.person_outline,
                  selectedIcon: Icons.person,
                  label: 'الحساب',
                ),
              ],
            )
          : null,
    );
  }
}
