import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/inbox_scope.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

class WorkerWorkspaceCapability {
  const WorkerWorkspaceCapability({
    required this.allowed,
    required this.workspaceSelected,
  });

  final bool allowed;
  final bool workspaceSelected;
}

final workerWorkspaceCapabilityProvider = FutureProvider.autoDispose
    .family<WorkerWorkspaceCapability, String>((ref, capabilityKey) async {
      final auth = ref.watch(authControllerProvider);
      if (!auth.isIndependentWorker) {
        return const WorkerWorkspaceCapability(
          allowed: true,
          workspaceSelected: true,
        );
      }

      final workspaceId = ref.watch(activeWorkspaceIdProvider);
      if (workspaceId == null) {
        return const WorkerWorkspaceCapability(
          allowed: false,
          workspaceSelected: false,
        );
      }

      final engagements = await ref
          .read(workerRepositoryProvider)
          .engagements();
      final allowed = engagements.any(
        (engagement) =>
            engagement.status == 'active' &&
            engagement.workspaceId == workspaceId &&
            engagement.hasCapability(capabilityKey),
      );

      return WorkerWorkspaceCapability(
        allowed: allowed,
        workspaceSelected: true,
      );
    });
