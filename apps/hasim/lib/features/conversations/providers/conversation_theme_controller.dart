import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';

class ConversationTheme {
  const ConversationTheme({this.wallpaperId, this.bubbleColorId});

  final String? wallpaperId;
  final String? bubbleColorId;

  bool get isDefault => wallpaperId == null && bubbleColorId == null;
}

class ConversationThemeController extends StateNotifier<ConversationTheme> {
  ConversationThemeController(this._prefs, this.conversationId, ConversationTheme initial) : super(initial);

  final PrefsStore _prefs;
  final int conversationId;

  Future<void> setWallpaper(String id) async {
    final resolved = ChatWallpaperCatalog.normalize(id);
    state = ConversationTheme(wallpaperId: resolved, bubbleColorId: state.bubbleColorId);
    await _prefs.setConversationTheme(conversationId, wallpaper: resolved);
  }

  Future<void> setBubble(String id) async {
    final resolved = ChatBubbleColorCatalog.normalize(id);
    state = ConversationTheme(wallpaperId: state.wallpaperId, bubbleColorId: resolved);
    await _prefs.setConversationTheme(conversationId, bubble: resolved);
  }

  Future<void> useSharedTheme() async {
    state = const ConversationTheme();
    await _prefs.setConversationTheme(conversationId, clearWallpaper: true, clearBubble: true);
  }
}

final conversationThemeProvider =
    StateNotifierProvider.family<ConversationThemeController, ConversationTheme, int>((ref, conversationId) {
  final prefs = ref.watch(prefsStoreProvider);
  final stored = prefs.conversationTheme(conversationId);
  return ConversationThemeController(
    prefs,
    conversationId,
    ConversationTheme(wallpaperId: stored.wallpaper, bubbleColorId: stored.bubble),
  );
});
