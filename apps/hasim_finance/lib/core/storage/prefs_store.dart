import 'package:shared_preferences/shared_preferences.dart';

class PrefsStore {
  PrefsStore(this._prefs);

  final SharedPreferences _prefs;

  static const _workspaceKey = 'hasim_finance_workspace_id';
  static const _apiBaseKey = 'hasim_finance_api_base';
  static const _themeModeKey = 'hasim_finance_theme_mode';
  static const _localeKey = 'hasim_finance_locale';

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

  String get themeMode => _prefs.getString(_themeModeKey) ?? 'system';

  Future<void> setThemeMode(String mode) async {
    await _prefs.setString(_themeModeKey, mode);
  }

  String get localeCode => _prefs.getString(_localeKey) ?? 'ar';

  Future<void> setLocaleCode(String code) async {
    await _prefs.setString(_localeKey, code);
  }
}
