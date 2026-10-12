import 'package:flutter/material.dart';
import 'package:hasim/features/worker/data/worker_repository.dart';

class WorkerInboxWorkspace {
  const WorkerInboxWorkspace({required this.id, required this.name});

  final int id;
  final String name;
}

/// Returns the unique workspaces where this worker can read the canonical Inbox.
///
/// The server remains authoritative for the actual conversation query. This
/// list only describes the valid choices already exposed by the engagements API.
List<WorkerInboxWorkspace> workerInboxWorkspaces(
  Iterable<WorkerEngagement> engagements,
) {
  final workspaces = <WorkerInboxWorkspace>[];

  for (final engagement in engagements) {
    final workspaceId = engagement.workspaceId;
    if (engagement.status != 'active' || workspaceId == null) {
      continue;
    }

    final canRead = engagement.capabilities.any(
      (capability) => capability.key == 'inbox.messages.read',
    );
    if (!canRead ||
        workspaces.any((workspace) => workspace.id == workspaceId)) {
      continue;
    }

    final name = engagement.workspaceName?.trim();
    workspaces.add(
      WorkerInboxWorkspace(
        id: workspaceId,
        name: name == null || name.isEmpty ? 'الشركة $workspaceId' : name,
      ),
    );
  }

  return workspaces;
}

/// Workspace that may supply `X-Workspace-Id` for the canonical Inbox.
///
/// Membership is not created. The backend still decides whether this
/// engagement may read conversations.
int? workerInboxWorkspaceId(Iterable<WorkerEngagement> engagements) {
  final workspaces = workerInboxWorkspaces(engagements);
  return workspaces.isEmpty ? null : workspaces.first.id;
}

/// Legacy `/worker/chat` entry. Routing sends this location to `/conversations`
/// before a worker shell can wrap the Inbox.
class WorkerChatScreen extends StatelessWidget {
  const WorkerChatScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return const SizedBox.shrink();
  }
}
