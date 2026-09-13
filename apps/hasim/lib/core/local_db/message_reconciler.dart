import 'package:hasim/core/local_db/inbox_local_store.dart';
import 'package:hasim/core/models/models.dart';

/// Single matching policy for every server/optimistic ingest path.
///
/// Match order: [clientId] then [serverId]. Never invent a match from content.
class MessageReconciler {
  const MessageReconciler._();

  static String localStateFor({
    required String? existingState,
    required MessageModel incoming,
  }) {
    if (incoming.localFailed) return MessageLocalState.failed;
    if (incoming.localPending) {
      if (existingState == MessageLocalState.sending) {
        return MessageLocalState.sending;
      }
      return MessageLocalState.pending;
    }

    final hasServerId = incoming.id > 0;
    if (!hasServerId &&
        (existingState == MessageLocalState.pending ||
            existingState == MessageLocalState.sending ||
            existingState == MessageLocalState.failed)) {
      return existingState!;
    }

    return MessageLocalState.sent;
  }
}
