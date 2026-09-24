import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hasim/core/di/providers.dart';
import 'package:hasim/core/theme/hasim_layout.dart';
import 'package:shared_preferences/shared_preferences.dart';

const _prefKey = 'desktop_conversation_panel_width';

final desktopConversationPanelWidthProvider =
    StateNotifierProvider<DesktopConversationPanelWidthNotifier, double?>(
  (ref) => DesktopConversationPanelWidthNotifier(ref.watch(sharedPrefsProvider)),
);

/// عرض قائمة المحادثات القابل للتعديل على سطح المكتب — يُحفظ محليًا.
class DesktopConversationPanelWidthNotifier extends StateNotifier<double?> {
  DesktopConversationPanelWidthNotifier(this._prefs) : super(_prefs.getDouble(_prefKey));

  final SharedPreferences _prefs;

  double resolve(BuildContext context, {bool customerDrawerOpen = false}) {
    final fallback = HasimLayout.suggestedConversationPanelWidth(context);
    final max = HasimLayout.conversationPanelMaxForScreen(context, customerDrawerOpen: customerDrawerOpen);
    final value = (state ?? fallback).clamp(HasimLayout.conversationPanelUserMin, max);
    if (state != null && state != value) {
      state = value;
      _prefs.setDouble(_prefKey, value);
    }
    return value;
  }

  void adjustByDrag(BuildContext context, double deltaDx, {bool customerDrawerOpen = false}) {
    final rtl = Directionality.of(context) == TextDirection.rtl;
    final delta = rtl ? -deltaDx : deltaDx;
    final max = HasimLayout.conversationPanelMaxForScreen(context, customerDrawerOpen: customerDrawerOpen);
    final next = (resolve(context, customerDrawerOpen: customerDrawerOpen) + delta)
        .clamp(HasimLayout.conversationPanelUserMin, max);
    state = next;
    _prefs.setDouble(_prefKey, next);
  }

  void resetToSuggested(BuildContext context) {
    state = null;
    _prefs.remove(_prefKey);
  }
}
