import 'package:hasim/core/models/models.dart';

/// Merges conversation pages without dropping already-loaded rows.
class ConversationReconciler {
  const ConversationReconciler._();

  static List<ConversationModel> unionById(
    List<ConversationModel> existing,
    List<ConversationModel> incoming,
  ) {
    final map = <int, ConversationModel>{
      for (final item in existing) item.id: item,
    };
    for (final item in incoming) {
      map[item.id] = item;
    }
    return sortServerOrder(map.values.toList());
  }

  static List<ConversationModel> sortServerOrder(List<ConversationModel> items) {
    final copy = [...items];
    copy.sort((a, b) {
      final at = a.lastMessageAt;
      final bt = b.lastMessageAt;
      if (at == null && bt == null) return b.id.compareTo(a.id);
      if (at == null) return 1;
      if (bt == null) return -1;
      final byTime = bt.compareTo(at);
      if (byTime != 0) return byTime;
      return b.id.compareTo(a.id);
    });
    return copy;
  }

  static String syncResource({
    required String filter,
    String? channel,
    String? search,
    String? handoff,
  }) {
    return 'conversations:$filter:${channel ?? ''}:${handoff ?? ''}:${search ?? ''}';
  }

  static String messagesResource(int conversationId) => 'messages/$conversationId';
}
