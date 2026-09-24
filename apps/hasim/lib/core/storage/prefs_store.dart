import 'package:hasim/features/conversations/data/chat_bubble_color_catalog.dart';
import 'package:hasim/features/conversations/data/chat_wallpaper_catalog.dart';
import 'package:shared_preferences/shared_preferences.dart';

class PrefsStore {
  PrefsStore(this._prefs);

  final SharedPreferences _prefs;

  static const _workspaceKey = 'workspace_id';
  static const _apiBaseKey = 'api_base_override';
  static const _themeModeKey = 'theme_mode';
  static const _chatWallpaperKey = 'chat_wallpaper';
  static const _chatOutgoingBubbleColorKey = 'chat_outgoing_bubble_color';

  int? get workspaceId {
    final v = _prefs.getInt(_workspaceKey);
    return v == null || v <= 0 ? null : v;
  }

  Future<void> setWorkspaceId(int? id) async {
    if (id == null) {
      await _prefs.remove(_workspaceKey);
    } else {
      await _prefs.setInt(_workspaceKey, id);
    }
  }

  String? get apiBaseOverride => _prefs.getString(_apiBaseKey);

  Future<void> setApiBaseOverride(String? value) async {
    if (value == null || value.isEmpty) {
      await _prefs.remove(_apiBaseKey);
    } else {
      await _prefs.setString(_apiBaseKey, value);
    }
  }

  /// `light` | `dark` | `system`
  /// Light is the product default so a dark OS cannot invert Hasim cards.
  String get themeMode => _prefs.getString(_themeModeKey) ?? 'light';

  Future<void> setThemeMode(String mode) async {
    await _prefs.setString(_themeModeKey, mode);
  }

  /// معرّف خلفية شاشة المحادثة (يُطبَّع عند القراءة في [ChatWallpaperController]).
  String get chatWallpaper => _prefs.getString(_chatWallpaperKey) ?? ChatWallpaperCatalog.defaultId;

  Future<void> setChatWallpaper(String id) async {
    await _prefs.setString(_chatWallpaperKey, id);
  }

  /// معرّف لون فقاعات الرسائل المرسلة ([ChatBubbleColorCatalog]).
  String get chatOutgoingBubbleColor =>
      _prefs.getString(_chatOutgoingBubbleColorKey) ?? ChatBubbleColorCatalog.defaultId;

  Future<void> setChatOutgoingBubbleColor(String id) async {
    await _prefs.setString(_chatOutgoingBubbleColorKey, id);
  }
}
