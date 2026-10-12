import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';

class ChatBubbleColorController extends StateNotifier<String> {
  ChatBubbleColorController(this._prefs)
      : super(ChatBubbleColorCatalog.normalize(_prefs.chatOutgoingBubbleColor));

  final PrefsStore _prefs;

  Future<void> select(String id) async {
    final resolved = ChatBubbleColorCatalog.normalize(id);
    state = resolved;
    await _prefs.setChatOutgoingBubbleColor(resolved);
  }
}

final chatBubbleColorControllerProvider =
    StateNotifierProvider<ChatBubbleColorController, String>((ref) {
  return ChatBubbleColorController(ref.watch(prefsStoreProvider));
});
