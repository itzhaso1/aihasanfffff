import 'dart:convert';

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
  static const _conversationThemesKey = 'conversation_chat_themes';
  static const _conversationMarksKey = 'conversation_marks';
  static const _customerNotesKey = 'customer_notes';
  static const _pushDeviceIdKey = 'push_device_id';
  static const _pushDeviceWorkspaceKey = 'push_device_workspace_id';
  static const _pushDeviceTokenKey = 'push_device_token';
  static const _companyPushAllowedKey = 'company_push_allowed';

  int? get workspaceId {
    final v = _prefs.getInt(_workspaceKey);
    return v == null || v <= 0 ? null : v;
  }

  int? get pushDeviceId {
    final value = _prefs.getInt(_pushDeviceIdKey);
    return value == null || value <= 0 ? null : value;
  }

  int? get pushDeviceWorkspaceId {
    final value = _prefs.getInt(_pushDeviceWorkspaceKey);
    return value == null || value <= 0 ? null : value;
  }

  String? get pushDeviceToken {
    final value = _prefs.getString(_pushDeviceTokenKey);
    return value == null || value.isEmpty ? null : value;
  }

  bool get companyPushAllowed => _prefs.getBool(_companyPushAllowedKey) == true;

  Future<void> setCompanyPushAllowed(bool allowed) async {
    if (allowed) {
      await _prefs.setBool(_companyPushAllowedKey, true);
    } else {
      await _prefs.remove(_companyPushAllowedKey);
    }
  }

  Future<void> savePushDevice({
    required int id,
    required int workspaceId,
    required String token,
  }) async {
    await _prefs.setInt(_pushDeviceIdKey, id);
    await _prefs.setInt(_pushDeviceWorkspaceKey, workspaceId);
    await _prefs.setString(_pushDeviceTokenKey, token);
  }

  Future<void> clearPushDevice() async {
    await _prefs.remove(_pushDeviceIdKey);
    await _prefs.remove(_pushDeviceWorkspaceKey);
    await _prefs.remove(_pushDeviceTokenKey);
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

  /// سمة خاصة بمحادثة واحدة. القيم الفارغة تعني استخدام السمة العامة.
  ({String? wallpaper, String? bubble}) conversationTheme(int conversationId) {
    final entry = _readConversationThemes()['$conversationId'];
    if (entry is! Map) return (wallpaper: null, bubble: null);
    final wallpaper = entry['wallpaper']?.toString();
    final bubble = entry['bubble']?.toString();
    return (
      wallpaper: wallpaper == null || wallpaper.isEmpty ? null : wallpaper,
      bubble: bubble == null || bubble.isEmpty ? null : bubble,
    );
  }

  Future<void> setConversationTheme(
    int conversationId, {
    String? wallpaper,
    String? bubble,
    bool clearWallpaper = false,
    bool clearBubble = false,
  }) async {
    final all = _readConversationThemes();
    final current = entryMap(all['$conversationId']);
    if (clearWallpaper) {
      current.remove('wallpaper');
    } else if (wallpaper != null) {
      current['wallpaper'] = wallpaper;
    }
    if (clearBubble) {
      current.remove('bubble');
    } else if (bubble != null) {
      current['bubble'] = bubble;
    }
    if (current.isEmpty) {
      all.remove('$conversationId');
    } else {
      all['$conversationId'] = current;
    }
    await _prefs.setString(_conversationThemesKey, jsonEncode(all));
  }

  Map<String, dynamic> _readConversationThemes() {
    final raw = _prefs.getString(_conversationThemesKey);
    if (raw == null || raw.isEmpty) return {};
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
    } catch (_) {}
    return {};
  }

  /// تثبيت/نجمة/إخفاء محلي للمحادثات والرسائل — لا يعتمد على واجهة جديدة.
  Map<String, dynamic> conversationMarks(int workspaceId) {
    final all = _readConversationMarks();
    return entryMap(all['$workspaceId']);
  }

  Future<void> setConversationMarks(
    int workspaceId,
    Map<String, dynamic> marks,
  ) async {
    final all = _readConversationMarks();
    if (marks.isEmpty) {
      all.remove('$workspaceId');
    } else {
      all['$workspaceId'] = marks;
    }
    await _prefs.setString(_conversationMarksKey, jsonEncode(all));
  }

  Map<String, dynamic> _readConversationMarks() =>
      _readJsonMap(_conversationMarksKey);

  /// ملاحظات العملاء المحلية لكل مساحة عمل (تُزامن مع جهة الاتصال إن وُجدت).
  Map<int, String> customerNotes(int workspaceId) {
    final entry = entryMap(_readJsonMap(_customerNotesKey)['$workspaceId']);
    final notes = <int, String>{};
    for (final item in entry.entries) {
      final customerId = int.tryParse(item.key);
      final note = item.value?.toString() ?? '';
      if (customerId != null && note.trim().isNotEmpty) {
        notes[customerId] = note;
      }
    }
    return notes;
  }

  Future<void> setCustomerNotes(
    int workspaceId,
    Map<int, String> notes,
  ) async {
    final all = _readJsonMap(_customerNotesKey);
    final entry = <String, String>{
      for (final item in notes.entries)
        if (item.value.trim().isNotEmpty) '${item.key}': item.value,
    };
    if (entry.isEmpty) {
      all.remove('$workspaceId');
    } else {
      all['$workspaceId'] = entry;
    }
    await _prefs.setString(_customerNotesKey, jsonEncode(all));
  }

  Map<String, dynamic> _readJsonMap(String key) {
    final raw = _prefs.getString(key);
    if (raw == null || raw.isEmpty) return {};
    try {
      final decoded = jsonDecode(raw);
      if (decoded is Map) return Map<String, dynamic>.from(decoded);
    } catch (_) {}
    return {};
  }

  Map<String, dynamic> entryMap(Object? raw) {
    if (raw is Map) return Map<String, dynamic>.from(raw);
    return {};
  }
}
