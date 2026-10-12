import 'package:hasim/core/models/models.dart';

/// يخفي الدردشات المقفلة عن الوارد، ولا يعرضها إلا داخل قسمها.
List<ConversationModel> applyChatLockView({
  required List<ConversationModel> items,
  required Set<int> lockedIds,
  required String filter,
}) {
  if (filter == 'locked') {
    return items.where((item) => lockedIds.contains(item.id)).toList();
  }
  if (lockedIds.isEmpty) return items;
  return items.where((item) => !lockedIds.contains(item.id)).toList();
}
