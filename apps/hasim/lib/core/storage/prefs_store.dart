import 'package:shared_preferences/shared_preferences.dart';

class PrefsStore {
  PrefsStore(this._prefs);

  final SharedPreferences _prefs;

  static const _workspaceKey = 'workspace_id';
  static const _apiBaseKey = 'api_base_override';
  static const _themeModeKey = 'theme_mode';

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
  String get themeMode => _prefs.getString(_themeModeKey) ?? 'system';

  Future<void> setThemeMode(String mode) async {
    await _prefs.setString(_themeModeKey, mode);
  }
}
