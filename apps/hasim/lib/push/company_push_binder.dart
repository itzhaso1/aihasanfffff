import 'dart:async';

import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/push/company_push_open.dart';
import 'package:hasim/router/app_router.dart';

class CompanyPushBinder extends ConsumerStatefulWidget {
  const CompanyPushBinder({super.key, required this.child});

  final Widget child;

  @override
  ConsumerState<CompanyPushBinder> createState() => _CompanyPushBinderState();
}

class _CompanyPushBinderState extends ConsumerState<CompanyPushBinder> {
  StreamSubscription<Map<String, dynamic>>? _openedSub;
  var _launchHandled = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      unawaited(_bind());
    });
  }

  Future<void> _bind() async {
    final source = ref.read(pushTokenSourceProvider);
    _openedSub = source.openedNotifications.listen(_open);
    await _consumeLaunch();
  }

  Future<void> _consumeLaunch() async {
    if (_launchHandled) return;
    final source = ref.read(pushTokenSourceProvider);
    final pending = await source.takeLaunchNotification();
    _launchHandled = true;
    if (pending != null) {
      await _open(pending);
    }
  }

  Future<void> _open(Map<String, dynamic> data) async {
    final auth = ref.read(authControllerProvider);
    if (auth.bootstrapping || !auth.isCompanyUser) return;
    final plan = planCompanyPushOpen(data);
    if (!plan.hasRoute) return;
    if (!companyPushMatchesSession(auth: auth, plan: plan)) return;

    if (plan.workspaceId != null && auth.workspace?.id != plan.workspaceId) {
      final target = auth.workspaces.where((item) => item.id == plan.workspaceId);
      if (target.isEmpty) return;
      final switched = await ref
          .read(authControllerProvider.notifier)
          .switchWorkspace(target.first);
      if (!switched) return;
    }

    ref.read(appRouterProvider).push(plan.route!);
  }

  @override
  void dispose() {
    _openedSub?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    ref.listen(authControllerProvider, (previous, next) {
      if (!next.bootstrapping && next.isCompanyUser) {
        unawaited(_consumeLaunch());
      }
    });
    return widget.child;
  }
}
