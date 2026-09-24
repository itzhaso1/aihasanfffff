import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/storage/prefs_store.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';

class ChatWallpaperController extends StateNotifier<String> {
  ChatWallpaperController(this._prefs) : super(ChatWallpaperCatalog.normalize(_prefs.chatWallpaper));

  final PrefsStore _prefs;

  Future<void> select(String id) async {
    final resolved = ChatWallpaperCatalog.normalize(id);
    state = resolved;
    await _prefs.setChatWallpaper(resolved);
  }
}

final chatWallpaperControllerProvider = StateNotifierProvider<ChatWallpaperController, String>((ref) {
  return ChatWallpaperController(ref.watch(prefsStoreProvider));
});
