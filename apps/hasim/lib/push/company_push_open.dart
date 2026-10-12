import 'package:hasim/features/auth/providers/auth_controller.dart';
import 'package:hasim/router/notification_deep_link.dart';

/// Uses the existing deep-link keys. Does not invent a second navigation graph.
class CompanyPushOpenPlan {
  const CompanyPushOpenPlan({this.route, this.workspaceId});

  final String? route;
  final int? workspaceId;

  bool get hasRoute => route != null && route!.isNotEmpty;
}

CompanyPushOpenPlan planCompanyPushOpen(Map<String, dynamic> data) {
  final workspaceRaw = data['workspace_id'] ?? data['workspaceId'];
  int? workspaceId;
  if (workspaceRaw is int) {
    workspaceId = workspaceRaw;
  } else if (workspaceRaw is num) {
    workspaceId = workspaceRaw.toInt();
  } else {
    workspaceId = int.tryParse(workspaceRaw?.toString() ?? '');
  }
  return CompanyPushOpenPlan(
    route: resolvePushPayloadRoute(data),
    workspaceId: workspaceId != null && workspaceId > 0 ? workspaceId : null,
  );
}

bool companyPushMatchesSession({
  required AuthState auth,
  required CompanyPushOpenPlan plan,
}) {
  if (!auth.isCompanyUser || auth.workspace == null) return false;
  if (plan.workspaceId == null) return true;
  return auth.workspace?.id == plan.workspaceId ||
      auth.workspaces.any((workspace) => workspace.id == plan.workspaceId);
}
