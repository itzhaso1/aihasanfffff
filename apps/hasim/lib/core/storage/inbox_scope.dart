import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/inbox_session.dart';
import 'package:hasim/features/auth/providers/auth_controller.dart';

final activeWorkspaceIdProvider = Provider<int?>((ref) {
  return ref.watch(authControllerProvider).workspace?.id ?? ref.watch(prefsStoreProvider).workspaceId;
});

InboxScope captureInboxScope(Ref ref) {
  final session = ref.read(inboxSessionProvider);
  return InboxScope(
    generation: session.generation,
    workspaceId: session.workspaceId ?? ref.read(activeWorkspaceIdProvider),
  );
}

bool inboxScopeIsCurrent(Ref ref, InboxScope scope) {
  return ref.read(inboxSessionProvider).isCurrent(
        generation: scope.generation,
        workspaceId: scope.workspaceId,
      );
}
